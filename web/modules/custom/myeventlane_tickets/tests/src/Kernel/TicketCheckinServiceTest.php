<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_tickets\Entity\RedemptionLog;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Kernel tests for ticket check-in validation service.
 *
 * @group myeventlane_tickets
 */
#[RunTestsInSeparateProcesses]
final class TicketCheckinServiceTest extends KernelTestBase {

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
   * Event fixture A.
   */
  private Node $eventA;

  /**
   * Event fixture B.
   */
  private Node $eventB;

  /**
   * Logged-in scanner user fixture.
   */
  private User $scannerUser;

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

    $this->scannerUser = User::create([
      'name' => 'scanner',
      'mail' => 'scanner@example.test',
      'status' => 1,
    ]);
    $this->scannerUser->save();
    $this->container->get('current_user')->setAccount($this->scannerUser);

    $this->eventA = Node::create([
      'type' => 'event',
      'title' => 'Event A',
      'uid' => $this->scannerUser->id(),
      'status' => 1,
    ]);
    $this->eventA->save();

    $this->eventB = Node::create([
      'type' => 'event',
      'title' => 'Event B',
      'uid' => $this->scannerUser->id(),
      'status' => 1,
    ]);
    $this->eventB->save();

    require_once DRUPAL_ROOT . '/modules/custom/myeventlane_tickets/myeventlane_tickets.install';
    \myeventlane_tickets_update_8005();
  }

  /**
   * Valid ticket is admitted and logged.
   */
  public function testAdmitsValidTicketAndLogsAttempt(): void {
    $ticket = $this->createTicket('MEL-VALID-0001', (int) $this->eventA->id(), Ticket::STATUS_ASSIGNED);
    $payload = $this->container->get('myeventlane_tickets.ticket_qr_payload')->buildForTicket($ticket);

    $result = $this->container->get('myeventlane_tickets.ticket_checkin_service')
      ->checkIn((int) $this->eventA->id(), $payload, 'kernel-device-1', 'online');

    $this->assertTrue($result['ok']);
    $this->assertSame('admitted', $result['result']);

    $reloaded = Ticket::load($ticket->id());
    $this->assertSame(Ticket::STATUS_CHECKED_IN, (string) $reloaded->get('status')->value);

    $log_count = (int) $this->container->get('database')->select('myeventlane_ticket_checkin_log', 'l')
      ->condition('event_nid', (int) $this->eventA->id())
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertGreaterThan(0, $log_count);
  }

  /**
   * Wrong event payload is blocked.
   */
  public function testBlocksWrongEventPayload(): void {
    $ticket = $this->createTicket('MEL-WRONG-EVENT-1', (int) $this->eventA->id(), Ticket::STATUS_ASSIGNED);
    $payload = $this->container->get('myeventlane_tickets.ticket_qr_payload')->buildForTicket($ticket);

    $result = $this->container->get('myeventlane_tickets.ticket_checkin_service')
      ->checkIn((int) $this->eventB->id(), $payload, 'kernel-device-2', 'online');

    $this->assertFalse($result['ok']);
    $this->assertSame('wrong_event', $result['result']);
  }

  /**
   * Invalid signature payload is rejected.
   */
  public function testBlocksInvalidSignaturePayload(): void {
    $ticket = $this->createTicket('MEL-BAD-SIG-1', (int) $this->eventA->id(), Ticket::STATUS_ASSIGNED);
    $payload = $this->container->get('myeventlane_tickets.ticket_qr_payload')->buildForTicket($ticket) . 'tamper';

    $result = $this->container->get('myeventlane_tickets.ticket_checkin_service')
      ->checkIn((int) $this->eventA->id(), $payload, 'kernel-device-3', 'online');

    $this->assertFalse($result['ok']);
    $this->assertSame('invalid', $result['result']);
  }

  /**
   * Refunded/void statuses are blocked.
   */
  public function testBlocksRefundedAndVoid(): void {
    $refunded = $this->createTicket('MEL-REFUNDED-1', (int) $this->eventA->id(), Ticket::STATUS_REFUNDED);
    $void = $this->createTicket('MEL-VOID-1', (int) $this->eventA->id(), Ticket::STATUS_VOID);

    $result_refunded = $this->container->get('myeventlane_tickets.ticket_checkin_service')
      ->checkIn((int) $this->eventA->id(), (string) $refunded->get('ticket_code')->value, 'kernel-device-4', 'online');
    $result_void = $this->container->get('myeventlane_tickets.ticket_checkin_service')
      ->checkIn((int) $this->eventA->id(), (string) $void->get('ticket_code')->value, 'kernel-device-4', 'online');

    $this->assertSame('refunded', $result_refunded['result']);
    $this->assertSame('void', $result_void['result']);
  }

  /**
   * Legacy order-item ticket codes still resolve to a ticket entity.
   */
  public function testLegacyOrderItemCodeStillValidates(): void {
    $ticket = $this->createTicket('MEL-CANONICAL-LEGACY', (int) $this->eventA->id(), Ticket::STATUS_ASSIGNED, [
      'order_item_id' => 987,
    ]);
    $legacy_code = sprintf('MEL-%d-123-987-ABC123', (int) $this->eventA->id());

    $result = $this->container->get('myeventlane_tickets.ticket_checkin_service')
      ->checkIn((int) $this->eventA->id(), $legacy_code, 'kernel-device-5', 'online');

    $this->assertTrue($result['ok']);
    $this->assertSame('admitted', $result['result']);

    $reloaded = Ticket::load($ticket->id());
    $this->assertSame(Ticket::STATUS_CHECKED_IN, (string) $reloaded->get('status')->value);
  }

  /**
   * Structured mel:v1 entitlement payloads redeem through the scanner service.
   */
  public function testSessionSequencingBlocksVipStructuredPayload(): void {
    $ticket = $this->createTicket('MEL-VIP-SEQ-1', (int) $this->eventA->id(), Ticket::STATUS_ASSIGNED, [
      'entitlement_type' => Ticket::ENTITLEMENT_VIP,
      'metadata_json' => [
        'mel_operational_session' => [
          'sequence_required' => TRUE,
          'required_prior_redemptions' => 1,
        ],
      ],
    ]);
    $payload = $this->container->get('myeventlane_tickets.ticket_qr_payload')->buildForTicket($ticket);

    $result = $this->container->get('myeventlane_tickets.ticket_checkin_service')
      ->checkIn((int) $this->eventA->id(), $payload, 'kernel-device-seq', 'online');

    $this->assertFalse($result['ok']);
    $this->assertSame('invalid', $result['result']);
  }

  public function testStructuredPayloadRedeemsDrinkVoucherOnce(): void {
    $ticket = $this->createTicket('MEL-DRINK-0001', (int) $this->eventA->id(), Ticket::STATUS_ASSIGNED, [
      'entitlement_type' => Ticket::ENTITLEMENT_DRINK,
      'redemption_limit' => 1,
    ]);
    $payload = $this->container->get('myeventlane_tickets.ticket_qr_payload')->buildForTicket($ticket);

    $this->assertStringStartsWith('mel:v1:json:', $payload);

    $result = $this->container->get('myeventlane_tickets.ticket_checkin_service')
      ->checkIn((int) $this->eventA->id(), $payload, 'kernel-device-6', 'online');

    $this->assertTrue($result['ok']);
    $this->assertSame('redeemed', $result['result']);

    $reloaded = Ticket::load($ticket->id());
    $this->assertSame(1, (int) $reloaded->get('redemption_count')->value);
    $this->assertSame(Ticket::FULFILMENT_REDEEMED, (string) $reloaded->get('fulfilment_status')->value);

    $second_result = $this->container->get('myeventlane_tickets.ticket_checkin_service')
      ->checkIn((int) $this->eventA->id(), $payload, 'kernel-device-6', 'online');

    $this->assertFalse($second_result['ok']);
    $this->assertSame('redemption_limit_reached', $second_result['result']);
  }

  /**
   * Expired entitlement payloads are blocked before mutation.
   */
  public function testExpiredStructuredPayloadIsBlocked(): void {
    $ticket = $this->createTicket('MEL-EXPIRED-0001', (int) $this->eventA->id(), Ticket::STATUS_ASSIGNED, [
      'entitlement_type' => Ticket::ENTITLEMENT_FOOD,
      'expires_at' => gmdate('Y-m-d\TH:i:s', time() - 3600),
    ]);
    $payload = $this->container->get('myeventlane_tickets.ticket_qr_payload')->buildForTicket($ticket);

    $result = $this->container->get('myeventlane_tickets.ticket_checkin_service')
      ->checkIn((int) $this->eventA->id(), $payload, 'kernel-device-7', 'online');

    $this->assertFalse($result['ok']);
    $this->assertSame('expired', $result['result']);

    $reloaded = Ticket::load($ticket->id());
    $this->assertSame(0, (int) $reloaded->get('redemption_count')->value);
  }

  /**
   * mel_redemption_log rows must carry venue integrity metadata (staff-side).
   */
  public function testRedemptionAuditStoresVenueIntegrityMetadata(): void {
    $this->installEntitySchema('mel_redemption_log');

    $ticket = $this->createTicket('MEL-REDEMPTION-META-1', (int) $this->eventA->id(), Ticket::STATUS_ASSIGNED, [
      'entitlement_type' => Ticket::ENTITLEMENT_DRINK,
      'redemption_limit' => 2,
    ]);
    $payload = $this->container->get('myeventlane_tickets.ticket_qr_payload')->buildForTicket($ticket);

    $result = $this->container->get('myeventlane_tickets.ticket_checkin_service')
      ->checkIn((int) $this->eventA->id(), $payload, 'kernel-device-meta', 'online');

    $this->assertTrue($result['ok']);

    $storage = $this->container->get('entity_type.manager')->getStorage('mel_redemption_log');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('ticket_id', (int) $ticket->id())
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();
    $this->assertNotEmpty($ids);
    $log = $storage->load((int) reset($ids));
    $this->assertInstanceOf(RedemptionLog::class, $log);
    $item = $log->get('metadata_json')->first();
    $this->assertNotNull($item);
    $meta = $item->getValue();
    $this->assertIsArray($meta);
    $this->assertArrayHasKey('venue_operation_integrity', $meta);
    $this->assertArrayHasKey('operational_scan_policy', $meta);
    $integrity = $meta['venue_operation_integrity'];
    $this->assertIsArray($integrity);
    $this->assertStringStartsWith('op_', (string) ($integrity['operation_id'] ?? ''));
    $this->assertArrayHasKey('replay_token', $integrity);
    $policy = $meta['operational_scan_policy'];
    $this->assertIsArray($policy);
    $this->assertArrayHasKey('zone_access', $policy);
  }

  /**
   * Scanner path attaches operational identity metadata (staff integrity).
   */
  public function testRedemptionAuditIncludesOperationalIdentity(): void {
    $this->installEntitySchema('mel_redemption_log');
    $ticket = $this->createTicket('MEL-OP-ID-1', (int) $this->eventA->id(), Ticket::STATUS_ASSIGNED, [
      'entitlement_type' => Ticket::ENTITLEMENT_DRINK,
      'redemption_limit' => 2,
    ]);
    $payload = $this->container->get('myeventlane_tickets.ticket_qr_payload')->buildForTicket($ticket);
    $ctx = [
      'mel_operational_device' => [
        'checkpoint_id' => 'bar-a',
        'operator_id' => 'op-999',
        'trust_level' => 'standard',
        'reconciliation_group' => 'sync-1',
      ],
    ];
    $result = $this->container->get('myeventlane_tickets.ticket_checkin_service')
      ->checkIn((int) $this->eventA->id(), $payload, 'kernel-op-id', 'online', $ctx);
    $this->assertTrue($result['ok']);
    $storage = $this->container->get('entity_type.manager')->getStorage('mel_redemption_log');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('ticket_id', (int) $ticket->id())
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();
    $log = $storage->load((int) reset($ids));
    $meta = $log->get('metadata_json')->first()->getValue();
    $this->assertArrayHasKey('operational_identity', $meta);
    $this->assertSame('bar-a', $meta['operational_identity']['normalized_device_context']['checkpoint_id']);
    $this->assertArrayHasKey('staff_integrity_identity', $meta['operational_identity']);
    $pub = json_encode($meta['operational_identity']['public_summary'] ?? []) ?: '';
    $this->assertStringNotContainsString('op-999', $pub);
  }

  /**
   * Redemption audit includes operational continuity staff payload.
   *
   * @group continuity
   */
  public function testRedemptionAuditIncludesOperationalContinuity(): void {
    $this->installEntitySchema('mel_redemption_log');
    $ticket = $this->createTicket('MEL-OC-AUDIT-1', (int) $this->eventA->id(), Ticket::STATUS_ASSIGNED, [
      'entitlement_type' => Ticket::ENTITLEMENT_DRINK,
      'redemption_limit' => 2,
      'metadata_json' => [
        'mel_operational_continuity' => [
          'continuity_epoch' => 7,
          'sync_hint' => 'batch_upload',
        ],
      ],
    ]);
    $payload = $this->container->get('myeventlane_tickets.ticket_qr_payload')->buildForTicket($ticket);
    $result = $this->container->get('myeventlane_tickets.ticket_checkin_service')
      ->checkIn((int) $this->eventA->id(), $payload, 'kernel-oc-audit', 'online', []);
    $this->assertTrue($result['ok']);
    $storage = $this->container->get('entity_type.manager')->getStorage('mel_redemption_log');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('ticket_id', (int) $ticket->id())
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();
    $log = $storage->load((int) reset($ids));
    $meta = $log->get('metadata_json')->first()->getValue();
    $this->assertArrayHasKey('operational_continuity', $meta);
    $this->assertSame(7, $meta['operational_continuity']['reconciliation']['continuity_epoch'] ?? NULL);
    $this->assertArrayHasKey('reconciliation_fingerprint', $meta['operational_continuity']);
  }

  /**
   * @covers \Drupal\myeventlane_tickets\Service\ScannerOperationManager::process
   */
  public function testScannerProcessResultShapeStableWithOperationalContext(): void {
    $ticket = $this->createTicket('MEL-SCAN-SHAPE', (int) $this->eventA->id(), Ticket::STATUS_ASSIGNED);
    $payload = $this->container->get('myeventlane_tickets.ticket_qr_payload')->buildForTicket($ticket);
    $mgr = $this->container->get('mel_scanner.operation_manager');
    $first = $mgr->process((int) $this->eventA->id(), $payload, 'dev-shape', 'online', NULL);
    $this->assertTrue($first['ok']);
    $baseline = $mgr->process((int) $this->eventA->id(), $payload, 'dev-shape', 'online', NULL);
    $with_ctx = $mgr->process((int) $this->eventA->id(), $payload, 'dev-shape', 'online', [
      'mel_operational_device' => ['trust_level' => 'limited'],
    ]);
    foreach (['ok', 'result', 'message', 'ticket_label', 'checked_in_at', 'ticket_id'] as $k) {
      $this->assertArrayHasKey($k, $baseline);
      $this->assertArrayHasKey($k, $with_ctx);
    }
    $this->assertFalse($baseline['ok']);
    $this->assertSame('already_checked_in', $baseline['result']);
    $this->assertFalse($with_ctx['ok']);
    $this->assertSame('already_checked_in', $with_ctx['result']);
  }

  /**
   * Timed entry metadata blocks scan before the nominal entry window opens.
   */
  public function testBlocksScanBeforeTimedEntryOpens(): void {
    $opens_at = time() + 7200;
    $closes_at = $opens_at + 14400;
    $ticket = $this->createTicket('MEL-TIMED-PREOPEN-1', (int) $this->eventA->id(), Ticket::STATUS_ASSIGNED, [
      'metadata_json' => [
        'mel_operational_timing' => [
          'entry_opens_at' => $opens_at,
          'entry_closes_at' => $closes_at,
          'early_entry_allowed' => FALSE,
        ],
      ],
    ]);
    $payload = $this->container->get('myeventlane_tickets.ticket_qr_payload')->buildForTicket($ticket);

    $result = $this->container->get('myeventlane_tickets.ticket_checkin_service')
      ->checkIn((int) $this->eventA->id(), $payload, 'kernel-device-timed', 'online');

    $this->assertFalse($result['ok']);
    $this->assertSame('invalid', $result['result']);
    $this->assertStringContainsStringIgnoringCase('open', (string) $result['message']);
  }

  /**
   * Scan succeeds inside an active timed entry window.
   */
  public function testAdmitsWithinTimedEntryWindow(): void {
    $opens_at = time() - 3600;
    $closes_at = time() + 7200;
    $ticket = $this->createTicket('MEL-TIMED-INWINDOW-1', (int) $this->eventA->id(), Ticket::STATUS_ASSIGNED, [
      'metadata_json' => [
        'mel_operational_timing' => [
          'entry_opens_at' => $opens_at,
          'entry_closes_at' => $closes_at,
        ],
      ],
    ]);
    $payload = $this->container->get('myeventlane_tickets.ticket_qr_payload')->buildForTicket($ticket);

    $result = $this->container->get('myeventlane_tickets.ticket_checkin_service')
      ->checkIn((int) $this->eventA->id(), $payload, 'kernel-device-timed-2', 'online');

    $this->assertTrue($result['ok']);
    $this->assertSame('admitted', $result['result']);
  }

  /**
   * Redemption audit includes occupancy slice inside operational scan policy.
   *
   * @group occupancy
   */
  public function testRedemptionAuditIncludesOccupancyInOperationalScanPolicy(): void {
    $this->installEntitySchema('mel_redemption_log');
    $ticket = $this->createTicket('MEL-OCC-AUDIT-1', (int) $this->eventA->id(), Ticket::STATUS_ASSIGNED, [
      'entitlement_type' => Ticket::ENTITLEMENT_DRINK,
      'redemption_limit' => 2,
      'metadata_json' => [
        'mel_operational_occupancy' => [
          'occupancy_mode' => 'live_estimate',
        ],
      ],
    ]);
    $payload = $this->container->get('myeventlane_tickets.ticket_qr_payload')->buildForTicket($ticket);
    $result = $this->container->get('myeventlane_tickets.ticket_checkin_service')
      ->checkIn((int) $this->eventA->id(), $payload, 'kernel-occ-audit', 'online', []);
    $this->assertTrue($result['ok']);
    $storage = $this->container->get('entity_type.manager')->getStorage('mel_redemption_log');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('ticket_id', (int) $ticket->id())
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();
    $log = $storage->load((int) reset($ids));
    $meta = $log->get('metadata_json')->first()->getValue();
    $this->assertArrayHasKey('operational_scan_policy', $meta);
    $this->assertArrayHasKey('occupancy', $meta['operational_scan_policy']);
  }

  /**
   * Creates one ticket fixture.
   */
  private function createTicket(string $ticket_code, int $event_id, string $status, array $overrides = []): Ticket {
    $values = [
      'ticket_code' => $ticket_code,
      'event_id' => $event_id,
      'purchaser_uid' => $this->scannerUser->id(),
      'status' => $status,
    ] + $overrides;

    $ticket = Ticket::create($values);
    $ticket->save();
    return $ticket;
  }

}

