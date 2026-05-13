<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Service\TimedEntryPolicyManager;
use Drupal\myeventlane_tickets\Service\VenueOperationPolicyManager;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @coversDefaultClass \Drupal\myeventlane_tickets\Service\TimedEntryPolicyManager
 *
 * @group myeventlane_tickets
 */
#[RunTestsInSeparateProcesses]
final class TimedEntryPolicyManagerTest extends KernelTestBase {

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

  private User $user;

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

    $this->user = User::create([
      'name' => 'timed_entry_' . uniqid('', TRUE),
      'mail' => uniqid('u_', TRUE) . '@example.test',
      'status' => 1,
    ]);
    $this->user->save();
  }

  public function testLegacyUnrestrictedWhenNoTimingSources(): void {
    $ticket = $this->createAdmissionTicket([]);
    $policy = $this->timed()->evaluate($ticket, 1_700_000_000, NULL);
    $this->assertTrue($policy['scanner']['allowed_now']);
    $this->assertSame('allowed', $policy['scanner']['state']);
    $this->assertSame('legacy_unrestricted', $policy['scanner']['reason']);
    $this->assertNull($policy['entry_window']['opens_at']);
    $this->assertNull($policy['entry_window']['closes_at']);
  }

  public function testNotStartedWhenBeforeOpensWithoutEarlyEntry(): void {
    $opens = 1_800_000_000;
    $closes = $opens + 3600;
    $ticket = $this->createAdmissionTicket([
      'metadata_json' => [
        'mel_operational_timing' => [
          'entry_opens_at' => $opens,
          'entry_closes_at' => $closes,
          'early_entry_allowed' => FALSE,
          'late_entry_allowed' => TRUE,
          'grace_seconds' => 0,
        ],
      ],
    ]);
    $policy = $this->timed()->evaluate($ticket, $opens - 100, NULL);
    $this->assertFalse($policy['scanner']['allowed_now']);
    $this->assertSame('not_started', $policy['scanner']['state']);
  }

  public function testEarlyStateWhenEarlyEntryAllowed(): void {
    $opens = 1_800_000_000;
    $closes = $opens + 3600;
    $ticket = $this->createAdmissionTicket([
      'metadata_json' => [
        'mel_operational_timing' => [
          'entry_opens_at' => $opens,
          'entry_closes_at' => $closes,
          'early_entry_allowed' => TRUE,
          'late_entry_allowed' => TRUE,
          'grace_seconds' => 0,
        ],
      ],
    ]);
    $policy = $this->timed()->evaluate($ticket, $opens - 60, NULL);
    $this->assertTrue($policy['scanner']['allowed_now']);
    $this->assertSame('early', $policy['scanner']['state']);
  }

  public function testLateGraceWithinWindow(): void {
    $opens = 1_800_000_000;
    $closes = $opens + 3600;
    $ticket = $this->createAdmissionTicket([
      'metadata_json' => [
        'mel_operational_timing' => [
          'entry_opens_at' => $opens,
          'entry_closes_at' => $closes,
          'early_entry_allowed' => FALSE,
          'late_entry_allowed' => TRUE,
          'grace_seconds' => 600,
        ],
      ],
    ]);
    $policy = $this->timed()->evaluate($ticket, $closes + 120, NULL);
    $this->assertTrue($policy['scanner']['allowed_now']);
    $this->assertSame('late', $policy['scanner']['state']);
  }

  public function testExpiredAfterEffectiveClose(): void {
    $opens = 1_800_000_000;
    $closes = $opens + 3600;
    $ticket = $this->createAdmissionTicket([
      'metadata_json' => [
        'mel_operational_timing' => [
          'entry_opens_at' => $opens,
          'entry_closes_at' => $closes,
          'early_entry_allowed' => FALSE,
          'late_entry_allowed' => TRUE,
          'grace_seconds' => 60,
        ],
      ],
    ]);
    $policy = $this->timed()->evaluate($ticket, $closes + 3600, NULL);
    $this->assertFalse($policy['scanner']['allowed_now']);
    $this->assertSame('expired', $policy['scanner']['state']);
  }

  public function testWorkshopSessionMetadata(): void {
    $opens = 1_800_000_000;
    $closes = $opens + 1800;
    $ticket = $this->createAdmissionTicket([
      'metadata_json' => [
        'mel_operational_timing' => [
          'entry_opens_at' => $opens,
          'entry_closes_at' => $closes,
          'session_key' => 'workshop-a',
          'capacity_window' => 'slot-1',
          'early_entry_allowed' => FALSE,
          'late_entry_allowed' => FALSE,
          'grace_seconds' => 0,
        ],
      ],
    ]);
    $policy = $this->timed()->evaluate($ticket, $opens + 60, NULL);
    $this->assertSame('workshop-a', $policy['session']['session_key']);
    $this->assertSame('slot-1', $policy['session']['capacity_window']);
    $this->assertTrue($policy['scanner']['allowed_now']);
  }

  public function testParkingCollectWindowDrivesBounds(): void {
    $start = '2030-01-15T10:00:00';
    $end = '2030-01-15T12:00:00';
    $ticket = $this->createParkingTicket([
      'collect_window' => [
        'value' => $start,
        'end_value' => $end,
      ],
    ]);
    $now = strtotime($start . ' UTC') + 300;
    $policy = $this->timed()->evaluate($ticket, $now, NULL);
    $this->assertTrue($policy['scanner']['allowed_now']);
    $this->assertSame(strtotime($start . ' UTC'), $policy['entry_window']['opens_at']);
  }

  public function testVipEarlyAccessViaNegativeOffsetFromEventStart(): void {
    $event = Node::create([
      'type' => 'event',
      'title' => 'VIP event',
      'uid' => $this->user->id(),
      'status' => 1,
      'field_event_start' => '2030-02-01T18:00:00',
      'field_event_end' => '2030-02-01T22:00:00',
    ]);
    $event->save();

    $ticket = Ticket::create([
      'ticket_code' => 'MEL-VIP-TIMED-1',
      'event_id' => (int) $event->id(),
      'purchaser_uid' => $this->user->id(),
      'status' => Ticket::STATUS_ASSIGNED,
      'entitlement_type' => Ticket::ENTITLEMENT_VIP,
      'metadata_json' => [
        'mel_operational_timing' => [
          'entry_open_offset_seconds' => -3600,
          'entry_close_offset_seconds' => 3600,
          'early_entry_allowed' => TRUE,
          'late_entry_allowed' => TRUE,
          'grace_seconds' => 0,
        ],
      ],
    ]);
    $ticket->save();

    $event_start = strtotime('2030-02-01T18:00:00 UTC');
    $policy = $this->timed()->evaluate($ticket, $event_start - 1800, NULL);
    $this->assertTrue($policy['scanner']['allowed_now']);
    $this->assertSame('allowed', $policy['scanner']['state']);
  }

  public function testQrPayloadExpiryCapsClosing(): void {
    $opens = 1_800_000_000;
    $closes = $opens + 86400;
    $ticket = $this->createAdmissionTicket([
      'metadata_json' => [
        'mel_operational_timing' => [
          'entry_opens_at' => $opens,
          'entry_closes_at' => $closes,
          'early_entry_allowed' => TRUE,
          'late_entry_allowed' => TRUE,
          'grace_seconds' => 0,
        ],
      ],
    ]);
    $qr_cap = $opens + 120;
    $policy = $this->timed()->evaluate($ticket, $opens + 200, $qr_cap);
    $this->assertSame($qr_cap, $policy['entry_window']['closes_at']);
    $this->assertFalse($policy['scanner']['allowed_now']);
    $this->assertSame('expired', $policy['scanner']['state']);
  }

  public function testOperationalTimingAliasKey(): void {
    $opens = 1_900_000_000;
    $closes = $opens + 3600;
    $ticket = $this->createAdmissionTicket([
      'metadata_json' => [
        'operational_timing' => [
          'entry_opens_at' => $opens,
          'entry_closes_at' => $closes,
          'early_entry_allowed' => FALSE,
          'late_entry_allowed' => FALSE,
          'grace_seconds' => 0,
        ],
      ],
    ]);
    $policy = $this->timed()->evaluate($ticket, $opens + 60, NULL);
    $this->assertTrue($policy['scanner']['allowed_now']);
  }

  public function testDetectTimingConflictsForDivergentWindows(): void {
    $meta_open = 1_800_000_000;
    $cw_start = gmdate('Y-m-d\TH:i:s', $meta_open + 7200);
    $cw_end = gmdate('Y-m-d\TH:i:s', $meta_open + 10800);
    $ticket = $this->createAdmissionTicket([
      'metadata_json' => [
        'mel_operational_timing' => [
          'entry_opens_at' => $meta_open,
          'entry_closes_at' => $meta_open + 3600,
          'early_entry_allowed' => FALSE,
          'late_entry_allowed' => FALSE,
          'grace_seconds' => 0,
        ],
      ],
      'collect_window' => [
        'value' => $cw_start,
        'end_value' => $cw_end,
      ],
    ]);
    $conflicts = $this->timed()->detectTimingConflicts($ticket);
    $this->assertContains('metadata_opens_vs_collect_window', $conflicts);
  }

  public function testScannerConvergenceViaVenuePolicyManager(): void {
    $opens = time() + 50000;
    $closes = $opens + 7200;
    $ticket = $this->createAdmissionTicket([
      'metadata_json' => [
        'mel_operational_timing' => [
          'entry_opens_at' => $opens,
          'entry_closes_at' => $closes,
          'early_entry_allowed' => FALSE,
          'late_entry_allowed' => TRUE,
          'grace_seconds' => 0,
        ],
      ],
    ]);
    $now = $opens - 10;
    $direct = $this->timed()->evaluate($ticket, $now, NULL);
    $gate = $this->venue()->evaluateTimedEntryForScan($ticket, $now, NULL);
    $this->assertFalse($direct['scanner']['allowed_now']);
    $this->assertFalse($gate['allow']);
    $this->assertSame($direct['scanner']['allowed_now'], $gate['policy']['scanner']['allowed_now']);
  }

  private function timed(): TimedEntryPolicyManager {
    return $this->container->get('myeventlane_tickets.timed_entry_policy_manager');
  }

  private function venue(): VenueOperationPolicyManager {
    return $this->container->get('myeventlane_tickets.venue_operation_policy_manager');
  }

  /**
   * @param array<string, mixed> $overrides
   */
  private function createAdmissionTicket(array $overrides): Ticket {
    $event = Node::create([
      'type' => 'event',
      'title' => 'Timed entry event',
      'uid' => $this->user->id(),
      'status' => 1,
    ]);
    $event->save();

    $values = [
      'ticket_code' => 'MEL-TIMED-' . uniqid('', TRUE),
      'event_id' => (int) $event->id(),
      'purchaser_uid' => $this->user->id(),
      'status' => Ticket::STATUS_ASSIGNED,
      'entitlement_type' => Ticket::ENTITLEMENT_TICKET,
    ] + $overrides;

    $ticket = Ticket::create($values);
    $ticket->save();
    return $ticket;
  }

  /**
   * @param array<string, mixed> $overrides
   */
  private function createParkingTicket(array $overrides): Ticket {
    $event = Node::create([
      'type' => 'event',
      'title' => 'Parking event',
      'uid' => $this->user->id(),
      'status' => 1,
    ]);
    $event->save();

    $values = [
      'ticket_code' => 'MEL-PARK-' . uniqid('', TRUE),
      'event_id' => (int) $event->id(),
      'purchaser_uid' => $this->user->id(),
      'status' => Ticket::STATUS_ASSIGNED,
      'entitlement_type' => Ticket::ENTITLEMENT_PARKING,
    ] + $overrides;

    $ticket = Ticket::create($values);
    $ticket->save();
    return $ticket;
  }

  /**
   * @return array<string, array{type: string, settings: array<string, mixed>}>
   */
  private function eventFieldDefinitions(): array {
    return [
      'field_event_start' => [
        'type' => 'datetime',
        'settings' => ['datetime_type' => 'datetime'],
      ],
      'field_event_end' => [
        'type' => 'datetime',
        'settings' => ['datetime_type' => 'datetime'],
      ],
    ];
  }

  private function createEventFields(): void {
    foreach ($this->eventFieldDefinitions() as $field_name => $definition) {
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
