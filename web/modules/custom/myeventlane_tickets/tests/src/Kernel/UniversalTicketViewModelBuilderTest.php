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
    $container->register('myeventlane_commerce.ticket_backed_order_item_classifier', \stdClass::class);
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

    // Signed QR payloads require a non-exportable signing secret.
    $this->setSetting('myeventlane_qr_secret', 'kernel-test-qr-secret');
  }

  /**
   * Base admission tickets normalize into the canonical operational model.
   */
  public function testIncludesTimedEntryAndSessionEntitlementPayloads(): void {
    $ticket = $this->createTicket([
      'ticket_code' => 'MEL-SESSION-VIEW',
    ]);
    $model = $this->builder()->build($ticket);
    $this->assertArrayHasKey('timed_entry', $model);
    $this->assertArrayHasKey('session_entitlement', $model);
    $this->assertSame('legacy_neutral', $model['session_entitlement']['progression']['current_state']);
    $this->assertArrayHasKey('timing_state', $model['scanner']);
    $this->assertArrayHasKey('session_state', $model['scanner']);
  }

  public function testExposesZoneAccessTopologyReadOnly(): void {
    $ticket = $this->createTicket([
      'ticket_code' => 'MEL-ZONE-VIEW-1',
      'metadata_json' => [
        'mel_operational_zones' => [
          'allowed_zones' => ['main_floor'],
          'progression_order' => ['main_floor', 'balcony'],
          'gate_groups' => ['entrance_a' => 'main_floor'],
        ],
      ],
    ]);
    $model = $this->builder()->build($ticket);
    $this->assertArrayHasKey('zone_access', $model);
    $this->assertArrayHasKey('topology', $model);
    $this->assertArrayHasKey('gate_groups', $model);
    $this->assertArrayHasKey('reentry', $model);
    $this->assertArrayHasKey('progression', $model);
    $this->assertSame(['main_floor', 'balcony'], $model['progression']['zone_order']);
    $this->assertArrayHasKey('session', $model['progression']);
    $encoded = json_encode($model['topology']) ?: '';
    $this->assertStringNotContainsString('replay_token', $encoded);
  }

  public function testExposesCustomerSafeOccupancySummaryWithoutInternals(): void {
    $ticket = $this->createTicket([
      'ticket_code' => 'MEL-OCC-VIEW',
      'metadata_json' => [
        'mel_operational_occupancy' => [
          'occupancy_mode' => 'live_estimate',
          'reentry_policy' => 'session_governed',
          'directional_mode' => 'none',
          'anti_passback_mode' => 'strict',
        ],
      ],
    ]);
    $model = $this->builder()->build($ticket);
    $this->assertArrayHasKey('occupancy', $model);
    $enc = json_encode($model['occupancy']) ?: '';
    $this->assertStringNotContainsString('anti_passback', $enc);
    $this->assertStringNotContainsString('topology_id', $enc);
    $this->assertSame('live_estimate', $model['occupancy']['occupancy_mode']);
  }

  public function testExposesCustomerSafeOperationalIdentityFromMetadata(): void {
    $ticket = $this->createTicket([
      'ticket_code' => 'MEL-OP-PUB',
      'metadata_json' => [
        'mel_operational_device' => [
          'checkpoint_id' => 'entry-a',
          'trust_level' => 'normal',
          'scan_mode' => 'admit',
          'operator_id' => 'hidden-staff',
        ],
      ],
    ]);
    $model = $this->builder()->build($ticket);
    $this->assertArrayHasKey('operational_identity', $model);
    $enc = json_encode($model['operational_identity']) ?: '';
    $this->assertStringNotContainsString('hidden-staff', $enc);
    $this->assertStringNotContainsString('replay', strtolower($enc));
    $this->assertSame('entry-a', $model['operational_identity']['checkpoint']);
  }

  public function testOmitsOperationalIdentityWhenNoDeviceMetadata(): void {
    $ticket = $this->createTicket(['ticket_code' => 'MEL-NO-OP']);
    $model = $this->builder()->build($ticket);
    $this->assertArrayNotHasKey('operational_identity', $model);
  }

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
    $this->assertNull($model['actions']['wallet']['apple']);
    $this->assertNull($model['actions']['wallet']['google']);
    $this->assertTrue($model['scanner']['can_scan']);
    $this->assertSame('ready', $model['scanner']['status']);
    $this->assertSame('admit', $model['capabilities']['scanner_mode']);
    $this->assertSame('none', $model['fulfilment']['mode']);
    $this->assertArrayHasKey('continuity', $model);
    $this->assertArrayHasKey('offline_capable', $model['continuity']);
    $this->assertStringNotContainsString('reconciliation_fingerprint', json_encode($model['continuity']) ?: '');
  }

  /**
   * Refunded tickets do not expose a downloadable admission document.
   */
  public function testRefundedTicketDoesNotExposePdfDownload(): void {
    $ticket = $this->createTicket([
      'ticket_code' => 'MEL-REFUNDED-0001',
      'status' => Ticket::STATUS_REFUNDED,
      'fulfilment_status' => Ticket::FULFILMENT_CANCELLED,
      'order_item_id' => 123,
    ]);

    $model = $this->builder()->build($ticket, TRUE, TRUE);

    $this->assertFalse($model['qr']['included']);
    $this->assertSame('', $model['qr']['payload']);
    $this->assertSame('', $model['qr']['data_uri']);
    $this->assertNull($model['actions']['wallet']['apple']);
    $this->assertNull($model['actions']['wallet']['google']);
    $this->assertSame('', $model['actions']['pdf']['download']['route']);
    $this->assertSame('', $model['actions']['pdf']['download']['url']);
  }

  /**
   * Fulfilment cancellation also suppresses every admission artifact.
   */
  public function testCancelledFulfilmentDoesNotExposeAdmissionArtifacts(): void {
    $ticket = $this->createTicket([
      'ticket_code' => 'MEL-CANCELLED-0001',
      'status' => Ticket::STATUS_ASSIGNED,
      'fulfilment_status' => Ticket::FULFILMENT_CANCELLED,
      'order_item_id' => 123,
    ]);

    $model = $this->builder()->build($ticket, TRUE, TRUE);

    $this->assertFalse($model['qr']['included']);
    $this->assertSame('', $model['qr']['data_uri']);
    $this->assertNull($model['actions']['wallet']['apple']);
    $this->assertNull($model['actions']['wallet']['google']);
    $this->assertSame('', $model['actions']['pdf']['download']['url']);
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
   * Overview-style builds can omit QR without requiring a signing secret.
   */
  public function testBuildWithoutQrSkipsSigning(): void {
    $this->setSetting('myeventlane_qr_secret', '');
    putenv('MEL_QR_SECRET');
    $ticket = $this->createTicket(['ticket_code' => 'MEL-NO-QR']);
    $model = $this->builder()->build($ticket, FALSE);
    $this->assertSame('', $model['qr']['payload']);
    $this->assertSame('', $model['qr']['data_uri']);
    $this->assertFalse($model['qr']['unavailable']);
    $this->assertFalse($model['qr']['included']);
    $this->assertSame('MEL-NO-QR', $model['ticket']['code']);
  }

  /**
   * Customer detail may degrade when allow_qr_unavailable is TRUE.
   */
  public function testBuildWithQrDegradesWhenSecretMissingAndAllowed(): void {
    $this->setSetting('myeventlane_qr_secret', '');
    putenv('MEL_QR_SECRET');
    $ticket = $this->createTicket(['ticket_code' => 'MEL-QR-MISS']);
    $model = $this->builder()->build($ticket, TRUE, TRUE);
    $this->assertSame('', $model['qr']['payload']);
    $this->assertSame('', $model['qr']['data_uri']);
    $this->assertTrue($model['qr']['unavailable']);
    $this->assertTrue($model['qr']['included']);
    $this->assertSame('MEL-QR-MISS', $model['ticket']['code']);
    $this->assertNotEmpty($model['event']);
  }

  /**
   * PDF/wallet default build() fails loud when the signing secret is missing.
   */
  public function testBuildWithQrThrowsWhenSecretMissingByDefault(): void {
    $this->setSetting('myeventlane_qr_secret', '');
    putenv('MEL_QR_SECRET');
    $ticket = $this->createTicket(['ticket_code' => 'MEL-QR-FAIL']);
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('MEL QR signing secret is not configured.');
    $this->builder()->build($ticket, TRUE);
  }

  /**
   * Detail builds include a signed QR when the secret is configured.
   */
  public function testBuildWithQrSucceedsWhenSecretConfigured(): void {
    $this->setSetting('myeventlane_qr_secret', 'kernel-test-qr-secret');
    $ticket = $this->createTicket(['ticket_code' => 'MEL-QR-OK']);
    $model = $this->builder()->build($ticket, TRUE);
    $this->assertNotSame('', $model['qr']['payload']);
    $this->assertStringStartsWith('mel:v1:', $model['qr']['payload']);
    $this->assertNotSame('', $model['qr']['data_uri']);
    $this->assertFalse($model['qr']['unavailable']);
    $this->assertTrue($model['qr']['included']);
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
