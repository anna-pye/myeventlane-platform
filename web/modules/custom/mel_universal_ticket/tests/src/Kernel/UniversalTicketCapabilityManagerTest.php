<?php

declare(strict_types=1);

namespace Drupal\Tests\mel_universal_ticket\Kernel;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_tickets\Entity\RedemptionLog;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Kernel tests for universal ticket entitlement foundations.
 *
 * @group mel_universal_ticket
 */
#[RunTestsInSeparateProcesses]
final class UniversalTicketCapabilityManagerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'node',
    'options',
    'datetime',
    'datetime_range',
    'commerce',
    'commerce_price',
    'commerce_order',
    'commerce_product',
    'entity_reference_revisions',
    'paragraphs',
    'mel_ticket',
    'myeventlane_vendor',
    'myeventlane_tickets',
    'mel_universal_ticket',
  ];

  /**
   * Event fixture.
   */
  private Node $event;

  /**
   * Scanner user fixture.
   */
  private User $scannerUser;

  /**
   * Customer user fixture.
   */
  private User $customerUser;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);

    foreach (array_keys($container->getDefinitions()) as $service_id) {
      if (str_starts_with($service_id, 'myeventlane_vendor.') && $service_id !== 'myeventlane_vendor.event_access_checker') {
        $container->removeDefinition($service_id);
      }
    }

    $container->register('myeventlane_vendor.event_access_checker', EventVendorAccessChecker::class);
    $container->register('myeventlane_onboarding.manager', \stdClass::class);
    $container->register('myeventlane_core.vendor_follow', \stdClass::class);
    $container->register('myeventlane_event_studio.save', \stdClass::class);
    $container->register('myeventlane_legal.gatekeeper', \stdClass::class);
    $container->register('myeventlane_core.domain_detector', \stdClass::class);
    $container->register('myeventlane_analytics.order_item_classifier', \stdClass::class);
    $container->register('myeventlane_commerce.ticket_backed_order_item_classifier', \stdClass::class);
    $container->register('myeventlane_core.entity_id_normalizer', \stdClass::class);
    $container->register('myeventlane_boost.manager', \stdClass::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    putenv('MEL_QR_SECRET=mel-kernel-test-secret');

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('myeventlane_ticket');
    $this->installEntitySchema('mel_redemption_log');

    NodeType::create([
      'type' => 'event',
      'name' => 'Event',
    ])->save();

    Role::create([
      'id' => 'scanner',
      'label' => 'Scanner',
      'permissions' => ['check in tickets'],
    ])->save();

    Role::create([
      'id' => 'ticket_admin',
      'label' => 'Ticket admin',
      'permissions' => ['administer myeventlane tickets'],
    ])->save();

    $this->scannerUser = User::create([
      'name' => 'scanner',
      'mail' => 'scanner@example.test',
      'status' => 1,
      'roles' => ['scanner'],
    ]);
    $this->scannerUser->save();

    $this->customerUser = User::create([
      'name' => 'customer',
      'mail' => 'customer@example.test',
      'status' => 1,
    ]);
    $this->customerUser->save();

    $this->event = Node::create([
      'type' => 'event',
      'title' => 'Entitlement Event',
      'uid' => $this->scannerUser->id(),
      'status' => 1,
    ]);
    $this->event->save();
  }

  /**
   * Legacy field providers transfer without deleting field storage.
   */
  public function testLegacyFieldProviderTransfer(): void {
    $update_manager = $this->container->get('entity.definition_update_manager');
    $legacy_fields = [
      'myeventlane_ticket' => 'entitlement_type',
      'mel_redemption_log' => 'ip_address',
    ];

    foreach ($legacy_fields as $entity_type_id => $field_name) {
      $definition = $update_manager->getFieldStorageDefinition($field_name, $entity_type_id);
      $this->assertNotNull($definition);
      $this->assertInstanceOf(BaseFieldDefinition::class, $definition);
      $definition->setProvider('mel_universal_ticket');
      $update_manager->updateFieldStorageDefinition($definition);
      $this->assertSame(
        'mel_universal_ticket',
        $update_manager->getFieldStorageDefinition($field_name, $entity_type_id)->getProvider(),
      );
    }

    $this->container->get('module_handler')->loadInclude('myeventlane_tickets', 'install');
    $result = myeventlane_tickets_update_8011();

    $this->assertStringContainsString('myeventlane_ticket.entitlement_type', $result);
    $this->assertStringContainsString('mel_redemption_log.ip_address', $result);
    foreach ($legacy_fields as $entity_type_id => $field_name) {
      $definition = $update_manager->getFieldStorageDefinition($field_name, $entity_type_id);
      $this->assertNotNull($definition);
      $this->assertSame('myeventlane_tickets', $definition->getProvider());
    }

    $this->assertStringContainsString('Already owned:', myeventlane_tickets_update_8011());
  }

  /**
   * Capability manager resolves entitlement type and subtype predicates.
   */
  public function testEntitlementTypeResolution(): void {
    $ticket = $this->createTicket([
      'entitlement_type' => Ticket::ENTITLEMENT_VIP,
    ]);

    $manager = $this->container->get('mel_universal_ticket.capability_manager');

    $this->assertSame(Ticket::ENTITLEMENT_VIP, $manager->getEntitlementType($ticket));
    $this->assertFalse($manager->isTicket($ticket));
    $this->assertTrue($manager->isVipAccess($ticket));
    $this->assertFalse($manager->isMerchPickup($ticket));
    $this->assertFalse($manager->isParkingPass($ticket));
  }

  /**
   * Capability manager enforces redemption limits.
   */
  public function testRedemptionLimits(): void {
    $ticket = $this->createTicket([
      'entitlement_type' => Ticket::ENTITLEMENT_DRINK,
      'redemption_limit' => 2,
      'redemption_count' => 1,
    ]);

    $manager = $this->container->get('mel_universal_ticket.capability_manager');

    $this->assertTrue($manager->isRedeemable($ticket));
    $this->assertTrue($manager->supportsMultipleRedemptions($ticket));
    $this->assertSame(1, $manager->getRemainingRedemptions($ticket));
    $this->assertTrue($manager->canBeScanned($ticket));

    $ticket->set('redemption_count', 2);

    $this->assertSame(0, $manager->getRemainingRedemptions($ticket));
    $this->assertFalse($manager->canBeScanned($ticket));
  }

  /**
   * Capability manager blocks expired entitlements.
   */
  public function testExpiryLogic(): void {
    $ticket = $this->createTicket([
      'entitlement_type' => Ticket::ENTITLEMENT_FOOD,
      'expires_at' => gmdate('Y-m-d\TH:i:s', time() - 3600),
    ]);

    $manager = $this->container->get('mel_universal_ticket.capability_manager');

    $this->assertTrue($manager->isExpired($ticket));
    $this->assertFalse($manager->canBeScanned($ticket));
  }

  /**
   * Structured QR payloads avoid owner leakage and legacy payloads still parse.
   */
  public function testQrCompatibility(): void {
    $ticket = $this->createTicket([
      'entitlement_type' => Ticket::ENTITLEMENT_MERCH,
      'redemption_limit' => 3,
    ]);

    $payload_service = $this->container->get('myeventlane_tickets.ticket_qr_payload');
    $payload = $payload_service->buildForTicket($ticket);
    $parsed = $payload_service->parseAndValidate($payload);

    $this->assertStringStartsWith('mel:v1:json:', $payload);
    $this->assertArrayNotHasKey('owner_id', $parsed);
    $this->assertSame(Ticket::ENTITLEMENT_MERCH, $parsed['entitlement_type']);
    $this->assertSame(3, $parsed['redemption_metadata']['limit']);

    $legacy_ticket = $this->createTicket();
    $legacy_payload = $payload_service->buildForTicket($legacy_ticket);

    $legacy_parsed = $payload_service->parseAndValidate($legacy_payload);
    $this->assertSame((string) $legacy_ticket->get('ticket_code')->value, $legacy_parsed['ticket_code']);
    $this->assertSame((int) $this->event->id(), $legacy_parsed['event_id']);
  }

  /**
   * Redemption logs are append-only and inaccessible to customers.
   */
  public function testRedemptionLogAccessBoundaries(): void {
    $ticket = $this->createTicket();
    $log = RedemptionLog::create([
      'ticket_id' => $ticket->id(),
      'entitlement_type' => Ticket::ENTITLEMENT_TICKET,
      'event_id' => $this->event->id(),
      'staff_uid' => $this->scannerUser->id(),
      'action_type' => RedemptionLog::ACTION_ADMIT,
      'device_identifier' => 'kernel-device',
      'ip_address' => '127.0.0.1',
    ]);
    $log->save();

    $this->assertTrue($log->access('view', $this->scannerUser));
    $this->assertFalse($log->access('view', $this->customerUser));
    $this->assertFalse($log->access('update', $this->scannerUser));
    $this->assertFalse($log->access('delete', $this->scannerUser));
  }

  /**
   * Creates one ticket fixture.
   */
  private function createTicket(array $overrides = []): Ticket {
    $values = [
      'ticket_code' => 'MEL-FOUNDATION-' . substr(hash('sha256', (string) microtime(TRUE)), 0, 8),
      'event_id' => $this->event->id(),
      'purchaser_uid' => $this->customerUser->id(),
      'status' => Ticket::STATUS_ASSIGNED,
    ] + $overrides;

    $ticket = Ticket::create($values);
    $ticket->save();
    return $ticket;
  }

}
