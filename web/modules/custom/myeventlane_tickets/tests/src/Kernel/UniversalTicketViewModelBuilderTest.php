<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests the canonical universal ticket view model builder.
 *
 * @group myeventlane_tickets
 */
#[RunTestsInSeparateProcesses]
final class UniversalTicketViewModelBuilderTest extends KernelTestBase {

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
  ];

  /**
   * Event fixture.
   */
  private Node $event;

  /**
   * Customer fixture.
   */
  private User $customer;

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
    $container->register('myeventlane_core.entity_id_normalizer', \stdClass::class);
    $container->register('myeventlane_boost.manager', \stdClass::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('myeventlane_ticket');

    NodeType::create([
      'type' => 'event',
      'name' => 'Event',
    ])->save();
    $this->createEventFields();

    $this->customer = User::create([
      'name' => 'customer',
      'mail' => 'customer@example.test',
      'status' => 1,
    ]);
    $this->customer->save();

    $this->event = Node::create([
      'type' => 'event',
      'title' => 'Canonical Event',
      'uid' => $this->customer->id(),
      'status' => 1,
      'field_event_start' => '2026-06-01T09:00:00',
      'field_event_end' => '2026-06-01T11:00:00',
      'field_venue_name' => 'Main Hall',
      'field_event_vendor' => $this->customer->id(),
    ]);
    $this->event->save();
  }

  /**
   * Base admission tickets normalize into the canonical operational model.
   */
  public function testBuildsAdmissionTicketModel(): void {
    $ticket = $this->createTicket([
      'ticket_code' => 'MEL-VIEW-0001',
      'holder_name' => 'Taylor Customer',
      'holder_email' => 'taylor@example.test',
      'status' => Ticket::STATUS_ASSIGNED,
      'order_item_id' => 123,
    ]);

    $model = $this->builder()->build($ticket);

    $this->assertSame((int) $ticket->id(), $model['ticket']['id']);
    $this->assertSame($ticket->uuid(), $model['ticket']['uuid']);
    $this->assertSame('MEL-VIEW-0001', $model['ticket']['code']);
    $this->assertSame(Ticket::ENTITLEMENT_TICKET, $model['ticket']['entitlement_type']);
    $this->assertSame('Canonical Event', $model['event']['label']);
    $this->assertSame('Main Hall', $model['event']['location']);
    $this->assertSame('Taylor Customer', $model['holder']['name']);
    $this->assertSame('taylor@example.test', $model['holder']['email']);
    $this->assertStringStartsWith('mel:v1:', $model['qr']['payload']);
    $this->assertSame(1, $model['redemption']['limit']);
    $this->assertSame(0, $model['redemption']['count']);
    $this->assertSame(1, $model['redemption']['remaining']);
    $this->assertFalse($model['expiry']['expired']);
    $this->assertSame('pending', $model['fulfilment']['status']);
    $this->assertSame('customer', $model['vendor']['label']);
    $this->assertSame('event', $model['vendor']['source']);
    $this->assertSame('myeventlane_tickets.download_pdf_by_code', $model['actions']['pdf']['download']['route']);
    $this->assertStringContainsString('/ticket/MEL-VIEW-0001/pdf', $model['actions']['pdf']['download']['url']);
    $this->assertSame('myeventlane_wallet.apple', $model['actions']['wallet']['apple']['route']);
    $this->assertSame('myeventlane_wallet.google', $model['actions']['wallet']['google']['route']);
    $this->assertTrue($model['scanner']['can_scan']);
    $this->assertSame('ready', $model['scanner']['status']);
    $this->assertSame('admit', $model['capabilities']['scanner_mode']);
    $this->assertSame('none', $model['fulfilment']['mode']);
    $this->assertArrayHasKey('timed_entry', $model);
    $this->assertSame('allowed', $model['timed_entry']['scanner']['state']);
    $this->assertTrue($model['scanner']['timing_allowed_now']);
    $this->assertSame('allowed', $model['scanner']['timing_state']);
  }

  /**
   * Non-admission entitlements preserve structured QR compatibility.
   */
  public function testBuildsStructuredQrEntitlementModel(): void {
    $ticket = $this->createTicket([
      'ticket_code' => 'MEL-DRINK-VIEW',
      'entitlement_type' => Ticket::ENTITLEMENT_DRINK,
      'redemption_limit' => 2,
      'redemption_count' => 1,
      'fulfilment_status' => Ticket::FULFILMENT_READY,
      'collect_location' => 'Bar one',
      'metadata_json' => [
        'sku' => 'drink-pass',
      ],
    ]);

    $model = $this->builder()->build($ticket);
    $parsed = $this->container->get('myeventlane_tickets.ticket_qr_payload')->parseAndValidate($model['qr']['payload']);

    $this->assertStringStartsWith('mel:v1:json:', $model['qr']['payload']);
    $this->assertSame(Ticket::ENTITLEMENT_DRINK, $parsed['entitlement_type']);
    $this->assertSame(2, $parsed['redemption_metadata']['limit']);
    $this->assertSame(Ticket::ENTITLEMENT_DRINK, $model['ticket']['entitlement_type']);
    $this->assertSame(2, $model['redemption']['limit']);
    $this->assertSame(1, $model['redemption']['count']);
    $this->assertSame(1, $model['redemption']['remaining']);
    $this->assertTrue($model['redemption']['multi_use']);
    $this->assertSame(Ticket::FULFILMENT_READY, $model['fulfilment']['status']);
    $this->assertSame('Bar one', $model['fulfilment']['collect_location']);
    $this->assertSame('drink-pass', $model['fulfilment']['metadata']['sku']);
    $this->assertTrue($model['scanner']['can_scan']);
    $this->assertSame('ready', $model['scanner']['status']);
    $this->assertSame('redeem', $model['capabilities']['scanner_mode']);
    $this->assertSame('redeem', $model['fulfilment']['mode']);
  }
  public function testBuildsUnavailableScannerStatus(): void {
    $expired = $this->createTicket([
      'ticket_code' => 'MEL-EXPIRED-VIEW',
      'entitlement_type' => Ticket::ENTITLEMENT_FOOD,
      'expires_at' => gmdate('Y-m-d\TH:i:s', time() - 3600),
    ]);
    $redeemed = $this->createTicket([
      'ticket_code' => 'MEL-REDEEMED-VIEW',
      'entitlement_type' => Ticket::ENTITLEMENT_DRINK,
      'redemption_limit' => 1,
      'redemption_count' => 1,
    ]);

    $expired_model = $this->builder()->build($expired);
    $redeemed_model = $this->builder()->build($redeemed);

    $this->assertTrue($expired_model['expiry']['expired']);
    $this->assertFalse($expired_model['scanner']['can_scan']);
    $this->assertSame('expired', $expired_model['scanner']['status']);
    $this->assertFalse($redeemed_model['scanner']['can_scan']);
    $this->assertSame(0, $redeemed_model['redemption']['remaining']);
    $this->assertSame('redemption_limit_reached', $redeemed_model['scanner']['status']);
  }

  /**
   * Fulfilment states that block scanning are reflected without a "ready" token.
   */
  public function testBuildsScannerStatusWhenFulfilmentBlocksScanning(): void {
    $cancelled = $this->createTicket([
      'ticket_code' => 'MEL-FUL-CANCEL',
      'entitlement_type' => Ticket::ENTITLEMENT_DRINK,
      'redemption_limit' => 2,
      'redemption_count' => 0,
      'fulfilment_status' => Ticket::FULFILMENT_CANCELLED,
    ]);
    $fulfilment_expired = $this->createTicket([
      'ticket_code' => 'MEL-FUL-EXPIRED',
      'entitlement_type' => Ticket::ENTITLEMENT_FOOD,
      'expires_at' => gmdate('Y-m-d\TH:i:s', time() + 86400),
      'fulfilment_status' => Ticket::FULFILMENT_EXPIRED,
    ]);

    $cancelled_model = $this->builder()->build($cancelled);
    $expired_model = $this->builder()->build($fulfilment_expired);

    $this->assertFalse($cancelled_model['scanner']['can_scan']);
    $this->assertSame('fulfilment_cancelled', $cancelled_model['scanner']['status']);
    $this->assertStringContainsString('cancelled', strtolower($cancelled_model['scanner']['message']));

    $this->assertFalse($expired_model['scanner']['can_scan']);
    $this->assertSame('fulfilment_expired', $expired_model['scanner']['status']);
    $this->assertStringContainsString('fulfilment', strtolower($expired_model['scanner']['message']));
  }

  /**
   * Returns the view model builder service.
   */
  private function builder(): object {
    return $this->container->get('myeventlane_tickets.universal_ticket_view_model_builder');
  }

  /**
   * Creates one issued ticket fixture.
   */
  private function createTicket(array $overrides = []): Ticket {
    $values = array_replace([
      'ticket_code' => 'MEL-VIEW-' . substr(hash('sha256', (string) microtime(TRUE)), 0, 8),
      'event_id' => $this->event->id(),
      'purchaser_uid' => $this->customer->id(),
      'status' => Ticket::STATUS_ASSIGNED,
    ], $overrides);

    $ticket = Ticket::create($values);
    $ticket->save();
    return $ticket;
  }

  /**
   * Creates the event fields needed by the builder fixtures.
   */
  private function createEventFields(): void {
    $fields = [
      'field_event_start' => [
        'type' => 'datetime',
        'settings' => ['datetime_type' => 'datetime'],
      ],
      'field_event_end' => [
        'type' => 'datetime',
        'settings' => ['datetime_type' => 'datetime'],
      ],
      'field_venue_name' => [
        'type' => 'string',
        'settings' => ['max_length' => 255],
      ],
      'field_event_vendor' => [
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'user'],
      ],
    ];

    foreach ($fields as $field_name => $definition) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => $definition['type'],
        'settings' => $definition['settings'],
      ])->save();

      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => 'event',
        'label' => $field_name,
      ])->save();
    }
  }

}
