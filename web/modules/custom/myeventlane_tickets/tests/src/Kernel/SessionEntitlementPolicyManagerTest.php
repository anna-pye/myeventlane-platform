<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Service\SessionEntitlementPolicyManager;
use Drupal\myeventlane_tickets\Service\TimedEntryPolicyManager;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @coversDefaultClass \Drupal\myeventlane_tickets\Service\SessionEntitlementPolicyManager
 *
 * @group myeventlane_tickets
 */
#[RunTestsInSeparateProcesses]
final class SessionEntitlementPolicyManagerTest extends KernelTestBase {

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
  }

  public function testDefaultLegacyNeutralSemantics(): void {
    $ticket = $this->createTicket(['entitlement_type' => Ticket::ENTITLEMENT_TICKET]);
    $timed = $this->timed()->evaluate($ticket, time(), NULL);
    $payload = $this->session()->buildNormalizedPayload($ticket, time(), NULL, $timed);
    $this->assertSame('legacy_neutral', $payload['progression']['current_state']);
    $this->assertTrue($payload['scanner']['allowed_now']);
  }

  public function testMultiUseExhaustion(): void {
    $ticket = $this->createTicket([
      'entitlement_type' => Ticket::ENTITLEMENT_DRINK,
      'redemption_limit' => 2,
      'redemption_count' => 2,
    ]);
    $timed = $this->timed()->evaluate($ticket, time(), NULL);
    $payload = $this->session()->buildNormalizedPayload($ticket, time(), NULL, $timed);
    $this->assertTrue($payload['progression']['is_exhausted']);
    $this->assertFalse($payload['scanner']['allowed_now']);
    $this->assertSame('multi_use_exhausted', $payload['scanner']['reason']);
  }

  public function testWeekendPassSessionTypeIsSessionBound(): void {
    $ticket = $this->createTicket([
      'entitlement_type' => Ticket::ENTITLEMENT_TICKET,
      'metadata_json' => [
        'mel_operational_session' => [
          'session_type' => 'weekend_pass',
        ],
      ],
    ]);
    $timed = $this->timed()->evaluate($ticket, time(), NULL);
    $payload = $this->session()->buildNormalizedPayload($ticket, time(), NULL, $timed);
    $this->assertSame('weekend_pass', $payload['session']['session_type']);
    $this->assertSame('session_bound', $payload['progression']['current_state']);
  }

  public function testDrinkPackProgression(): void {
    $ticket = $this->createTicket([
      'entitlement_type' => Ticket::ENTITLEMENT_DRINK,
      'redemption_limit' => 4,
      'redemption_count' => 1,
    ]);
    $timed = $this->timed()->evaluate($ticket, time(), NULL);
    $payload = $this->session()->buildNormalizedPayload($ticket, time(), NULL, $timed);
    $this->assertSame(3, $payload['redemption']['remaining_uses']);
    $this->assertContains('redeem', $payload['progression']['next_allowed_operations']);
  }

  public function testParkingReentrySemantics(): void {
    $ticket = $this->createTicket(['entitlement_type' => Ticket::ENTITLEMENT_PARKING]);
    $timed = $this->timed()->evaluate($ticket, time(), NULL);
    $payload = $this->session()->buildNormalizedPayload($ticket, time(), NULL, $timed);
    $this->assertTrue($payload['redemption']['reentry_allowed']);
  }

  public function testVipWorkshopSequencing(): void {
    $ticket = $this->createTicket([
      'entitlement_type' => Ticket::ENTITLEMENT_VIP,
      'redemption_count' => 0,
      'metadata_json' => [
        'mel_operational_session' => [
          'sequence_required' => TRUE,
          'required_prior_redemptions' => 1,
        ],
      ],
    ]);
    $timed = $this->timed()->evaluate($ticket, time(), NULL);
    $payload = $this->session()->buildNormalizedPayload($ticket, time(), NULL, $timed);
    $this->assertTrue($payload['progression']['requires_previous_redemption']);
    $this->assertSame('sequencing_blocked', $payload['progression']['current_state']);
    $this->assertFalse($payload['scanner']['allowed_now']);
    $this->assertSame('sequence_previous_missing', $payload['scanner']['reason']);
  }

  public function testGroupedBundleMetadata(): void {
    $ticket = $this->createTicket([
      'entitlement_type' => Ticket::ENTITLEMENT_ADDON,
      'metadata_json' => [
        'mel_operational_session' => [
          'bundle_key' => 'meal_pack_a',
          'zone_key' => 'hall_b',
        ],
      ],
    ]);
    $timed = $this->timed()->evaluate($ticket, time(), NULL);
    $payload = $this->session()->buildNormalizedPayload($ticket, time(), NULL, $timed);
    $this->assertSame('meal_pack_a', $payload['session']['bundle_key']);
    $this->assertSame('hall_b', $payload['session']['zone_key']);
  }

  public function testVenueScanGateHonoursSessionSequencing(): void {
    $ticket = $this->createTicket([
      'entitlement_type' => Ticket::ENTITLEMENT_VIP,
      'metadata_json' => [
        'mel_operational_session' => [
          'sequence_required' => TRUE,
          'required_prior_redemptions' => 1,
        ],
      ],
    ]);
    $gate = $this->container->get('myeventlane_tickets.venue_operation_policy_manager')
      ->evaluateTimedEntryForScan($ticket, time(), NULL);
    $this->assertFalse($gate['allow']);
    $this->assertSame('invalid', $gate['result_token']);
  }

  /**
   * @param array<string, mixed> $overrides
   */
  private function createTicket(array $overrides): Ticket {
    $user = User::create([
      'name' => 'session_' . uniqid('', TRUE),
      'mail' => uniqid('u_', TRUE) . '@example.test',
      'status' => 1,
    ]);
    $user->save();
    $event = Node::create([
      'type' => 'event',
      'title' => 'Session test event',
      'uid' => $user->id(),
      'status' => 1,
    ]);
    $event->save();

    $values = array_replace([
      'ticket_code' => 'MEL-SESS-' . substr(hash('sha256', (string) microtime(TRUE)), 0, 8),
      'event_id' => (int) $event->id(),
      'purchaser_uid' => $user->id(),
      'status' => Ticket::STATUS_ASSIGNED,
    ], $overrides);

    $ticket = Ticket::create($values);
    $ticket->save();
    return $ticket;
  }

  private function session(): SessionEntitlementPolicyManager {
    return $this->container->get('myeventlane_tickets.session_entitlement_policy_manager');
  }

  private function timed(): TimedEntryPolicyManager {
    return $this->container->get('myeventlane_tickets.timed_entry_policy_manager');
  }

}
