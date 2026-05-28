<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_price\Entity\Currency;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_store\Entity\Store;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\myeventlane_tickets\Kernel\Traits\RegistersTicketBackedClassifierStubTrait;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Service\InventoryReservationGovernanceManager;
use Drupal\myeventlane_tickets\Service\OperationalCapabilityAuditProjector;
use Drupal\myeventlane_tickets\Service\OperationalCapabilityProjectionBuilder;
use Drupal\myeventlane_tickets\Service\OperationalEntitlementCapabilityManager;
use Drupal\myeventlane_tickets\Service\OperationalWorkspaceBuilder;
use Drupal\myeventlane_tickets\Ticket\TicketIssuer;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @coversDefaultClass \Drupal\myeventlane_tickets\Service\OperationalEntitlementCapabilityManager
 *
 * @group myeventlane_tickets
 */
#[RunTestsInSeparateProcesses]
final class OperationalEntitlementCapabilityKernelTest extends KernelTestBase {

  use RegistersTicketBackedClassifierStubTrait;

  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'link',
    'path',
    'path_alias',
    'views',
    'address',
    'node',
    'options',
    'datetime',
    'datetime_range',
    'commerce',
    'commerce_number_pattern',
    'commerce_price',
    'commerce_order',
    'commerce_product',
    'commerce_store',
    'entity_reference_revisions',
    'paragraphs',
    'profile',
    'state_machine',
    'mel_ticket',
    'myeventlane_vendor',
    'myeventlane_tickets',
    'myeventlane_wallet',
  ];

  private Node $event;

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
    $this->registerTicketBackedClassifierStub($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('commerce_currency');
    $this->installEntitySchema('profile');
    $this->installEntitySchema('commerce_store');
    $this->installEntitySchema('commerce_product');
    $this->installEntitySchema('commerce_product_variation');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installEntitySchema('myeventlane_ticket');
    $this->installConfig(['commerce_store', 'commerce_product', 'commerce_order', 'user']);
    $this->ensureWorkspaceRoles();

    NodeType::create([
      'type' => 'event',
      'name' => 'Event',
    ])->save();
    $this->createEventFields();
    $this->ensureProductEventField();

    if (!OrderItemType::load('default')) {
      OrderItemType::create([
        'id' => 'default',
        'label' => 'Default',
        'purchasableEntityType' => 'commerce_product_variation',
        'orderType' => 'default',
      ])->save();
    }
    if (!OrderType::load('default')) {
      OrderType::create([
        'id' => 'default',
        'label' => 'Default',
        'workflow' => 'order_default',
      ])->save();
    }
    if (!Currency::load('AUD')) {
      Currency::create([
        'currencyCode' => 'AUD',
        'name' => 'Australian Dollar',
        'numericCode' => '036',
        'symbol' => '$',
        'fractionDigits' => 2,
      ])->save();
    }

    $store_type_storage = $this->container->get('entity_type.manager')->getStorage('commerce_store_type');
    if (!$store_type_storage->load('online')) {
      $store_type_storage->create(['id' => 'online', 'label' => 'Online'])->save();
    }
    $store = Store::create([
      'type' => 'online',
      'name' => 'Capability Store',
      'mail' => 'store@example.test',
      'default_currency' => 'AUD',
      'status' => 1,
    ]);
    $store->save();

    $customer = User::create([
      'name' => 'capability_customer',
      'mail' => 'capability@example.test',
      'status' => 1,
    ]);
    $customer->save();

    $this->event = Node::create([
      'type' => 'event',
      'title' => 'Capability Event',
      'uid' => $customer->id(),
      'status' => 1,
      'field_event_start' => '2026-08-01T10:00:00',
      'field_event_end' => '2026-08-01T12:00:00',
      'field_venue_name' => 'Hall C',
      'field_event_vendor' => $customer->id(),
    ]);
    $this->event->save();

    $product = Product::create([
      'type' => 'default',
      'title' => 'Capability Product',
      'stores' => [$store],
      'field_event' => $this->event->id(),
    ]);
    $product->save();

    $variation = ProductVariation::create([
      'type' => 'default',
      'sku' => 'CAPABILITY-SKU',
      'title' => 'Merch',
      'product_id' => $product->id(),
      'price' => new Price('25.00', 'AUD'),
      'status' => 1,
    ]);
    $variation->save();

    $order = Order::create([
      'type' => 'default',
      'store_id' => $store->id(),
      'state' => 'completed',
      'uid' => $customer->id(),
      'mail' => 'capability@example.test',
      'placed' => time(),
    ]);
    $order->save();

    $item = OrderItem::create([
      'type' => 'default',
      'purchased_entity' => $variation,
      'quantity' => 1,
      'unit_price' => new Price('25.00', 'AUD'),
      'order_id' => $order->id(),
    ]);
    $item->save();
    $order->addItem($item);
    $order->save();
  }

  public function testCapabilityTypeNormalization(): void {
    $m = $this->capabilityManager();
    $this->assertSame('digital_redemption', $m->normalizeCapabilityType(''));
    $this->assertSame('merch_pickup', $m->normalizeCapabilityType('merch_pickup'));
    $this->assertSame('hospitality_access', $m->normalizeCapabilityType('hospitality_access'));
    $this->assertSame('timed_collection', $m->normalizeCapabilityType('timed_collection'));
  }

  public function testCapabilityStateNormalization(): void {
    $m = $this->capabilityManager();
    $this->assertSame('unavailable', $m->normalizeCapabilityState(''));
    $this->assertSame('reserved', $m->normalizeCapabilityState('reserved'));
    $this->assertSame('ready', $m->normalizeCapabilityState('ready'));
    $this->assertSame('redeemed', $m->normalizeCapabilityState('redeemed'));
  }

  public function testCapabilityReadinessProjections(): void {
    $model = $this->capabilityManager()->composeCapabilityReadModel($this->sampleMerged(), 1_700_000_000);
    $readiness = $model['readiness_projection'] ?? [];
    $this->assertSame('capability_readiness_visibility', $readiness['descriptor'] ?? '');
    $this->assertArrayHasKey('execution_ready_rows', $readiness);
    $this->assertArrayHasKey('merch_pickup_ready_rows', $readiness);
  }

  public function testCapabilityDegradationProjections(): void {
    $merged = $this->sampleMerged();
    $merged['recovery']['recovery_mismatch_observed'] = TRUE;
    $model = $this->capabilityManager()->composeCapabilityReadModel($merged, 1_700_000_000);
    $degradation = $model['degradation_projection'] ?? [];
    $this->assertNotSame('nominal', $degradation['descriptor'] ?? 'nominal');
  }

  public function testCapabilityContinuityOrdering(): void {
    $timeline = $this->capabilityManager()->projectCapabilityAuditTimeline([
      ['state' => 'reserved', 'recorded_at' => 200, 'note' => 'second'],
      ['state' => 'ready', 'recorded_at' => 100, 'note' => 'first'],
    ]);
    $this->assertSame(100, $timeline[0]['recorded_at_unix']);
    $this->assertSame(200, $timeline[1]['recorded_at_unix']);
  }

  public function testWorkspaceCapabilityRendering(): void {
    $this->seedEventTickets();
    $staff = $this->staffWithCapabilityPermission();
    /** @var AccountSwitcherInterface $switcher */
    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($staff);

    $workspace = $this->workspaceBuilder()->build($this->event);
    $ids = array_column($workspace['sections'], 'id');
    $this->assertContains('capability_governance_summary', $ids);
    $this->assertContains('capability_lifecycle_cards', $ids);
    $this->assertContains('capability_readiness_visibility', $ids);
    $this->assertContains('capability_lifecycle_audit_timeline', $ids);

    $switcher->switchBack();
  }

  public function testPermissionSeparation(): void {
    $this->seedEventTickets();
    $viewer = User::create([
      'name' => 'capability_view_only',
      'mail' => 'cap-view@example.test',
      'status' => 1,
    ]);
    $viewer->addRole('mel_ws_ops_view');
    $viewer->save();
    $viewer = User::load($viewer->id());
    $this->assertNotFalse($viewer);

    /** @var AccountSwitcherInterface $switcher */
    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($viewer);

    $workspace = $this->workspaceBuilder()->build($this->event);
    $this->assertFalse($workspace['meta']['capability_projection_enabled']);
    $ids = array_column($workspace['sections'], 'id');
    $this->assertNotContains('capability_governance_summary', $ids);

    $switcher->switchBack();
  }

  public function testReservationCapabilityComposition(): void {
    $model = $this->capabilityManager()->composeCapabilityReadModel($this->sampleMerged(), 1_700_000_000);
    $reservation = $model['reservation_capability_projection'] ?? [];
    $this->assertSame('reservation_capability_visibility', $reservation['descriptor'] ?? '');
    $this->assertArrayHasKey('allocated_fraction', $reservation);
    $rows = $model['ticket_projections'] ?? [];
    $this->assertNotEmpty($rows);
    $this->assertStringContainsString('reservation_state=', (string) ($rows[0]['reservation_capability_summary'] ?? ''));
  }

  public function testFulfillmentCapabilityComposition(): void {
    $model = $this->capabilityManager()->composeCapabilityReadModel($this->sampleMerged(), 1_700_000_000);
    $fulfillment = $model['fulfillment_capability_projection'] ?? [];
    $this->assertSame('fulfillment_capability_visibility', $fulfillment['descriptor'] ?? '');
    $this->assertArrayHasKey('fulfilment_collect_rows', $fulfillment);
    $rows = $model['ticket_projections'] ?? [];
    $this->assertNotEmpty($rows);
    $this->assertStringContainsString('fulfilment_status=', (string) ($rows[0]['fulfillment_capability_summary'] ?? ''));
  }

  public function testNoReplayLeakage(): void {
    $sections = $this->projectionBuilder()->buildWorkspaceCapabilitySections($this->sampleMergedWithSecrets(), 1_700_000_000);
    $blob = json_encode($sections, JSON_THROW_ON_ERROR);
    $this->assertStringNotContainsString('replay_token', $blob);
  }

  public function testNoQrLeakage(): void {
    $sections = $this->auditProjector()->buildWorkspaceCapabilityAuditSections($this->sampleMergedWithSecrets(), 1_700_000_000);
    $blob = json_encode($sections, JSON_THROW_ON_ERROR);
    $this->assertStringNotContainsString('qr_payload', $blob);
  }

  public function testNoDeviceFingerprintLeakage(): void {
    $sections = $this->auditProjector()->buildWorkspaceCapabilityAuditSections($this->sampleMergedWithSecrets(), 1_700_000_000);
    $blob = json_encode($sections, JSON_THROW_ON_ERROR);
    $this->assertStringNotContainsString('device_fingerprint', $blob);
    $this->assertStringNotContainsString('fingerprint', strtolower($blob));
  }

  public function testInvalidCapabilityNormalization(): void {
    $m = $this->capabilityManager();
    $this->assertSame('unavailable', $m->normalizeCapabilityState('not_a_state'));
    $this->assertSame('digital_redemption', $m->normalizeCapabilityType('warehouse_slot'));
  }

  public function testCapabilityLifecycleContinuity(): void {
    $model = $this->capabilityManager()->composeCapabilityReadModel($this->sampleMerged(), 1_700_000_000);
    $continuity = $model['continuity_projection'] ?? [];
    $this->assertSame('capability_continuity_visibility', $continuity['descriptor'] ?? '');
    $this->assertArrayHasKey('guest_continuity_statuses', $continuity);
  }

  public function testTimedCollectionCapabilitySemantics(): void {
    $m = $this->capabilityManager();
    $type = $m->mapEntitlementToCapabilityType(Ticket::ENTITLEMENT_TICKET, 1, 'early');
    $this->assertSame('timed_collection', $type);
    $merged = $this->sampleMerged();
    $merged['artifacts']['timed_entry_policy'] = [
      '1' => [
        'policy' => [
          'scanner' => ['state' => 'open'],
        ],
      ],
    ];
    $model = $m->composeCapabilityReadModel($merged, 1_700_000_000);
    $readiness = $model['readiness_projection'] ?? [];
    $this->assertArrayHasKey('timed_collection_ready_rows', $readiness);
  }

  public function testMerchPickupCapabilitySemantics(): void {
    $m = $this->capabilityManager();
    $type = $m->mapEntitlementToCapabilityType(Ticket::ENTITLEMENT_MERCH, 1);
    $this->assertSame('merch_pickup', $type);
    $model = $m->composeCapabilityReadModel($this->sampleMerged(), 1_700_000_000);
    $types = $model['rollup']['capability_type_tally'] ?? [];
    $this->assertGreaterThan(0, (int) ($types['merch_pickup'] ?? 0));
  }

  public function testHospitalityCapabilitySemantics(): void {
    $m = $this->capabilityManager();
    $type = $m->mapEntitlementToCapabilityType(Ticket::ENTITLEMENT_ADDON, 3);
    $this->assertSame('hospitality_access', $type);
    $reservation_type = $m->mapReservationTypeToCapabilityType(InventoryReservationGovernanceManager::TYPE_HOSPITALITY);
    $this->assertSame('hospitality_access', $reservation_type);
  }

  public function testRollupCompositionContinuity(): void {
    $m = $this->capabilityManager();
    $state = $m->projectCapabilityStateFromRollup($this->sampleMerged());
    $this->assertContains($state, $m->allCanonicalStates());
    $rollup = $m->composeCapabilityReadModel($this->sampleMerged(), 1_700_000_000)['rollup'] ?? [];
    $this->assertArrayHasKey('rollup_continuity_composition', $rollup);
    $this->assertArrayHasKey('canonical_capability_state_tally', $rollup);
    $this->assertArrayHasKey('reservation_state_tally', $rollup);
  }

  public function testAuditContinuityOrdering(): void {
    $sections = $this->auditProjector()->buildWorkspaceCapabilityAuditSections($this->sampleMerged(), 1_700_000_000);
    $timeline = $sections[0]['timeline'] ?? [];
    $this->assertNotEmpty($timeline);
    $times = array_column($timeline, 'recorded_at_unix');
    $sorted = $times;
    sort($sorted);
    $this->assertSame($sorted, $times);
  }

  /**
   * @return array<string, mixed>
   */
  private function sampleMerged(): array {
    return [
      'issuance' => [
        'worst_quantity_alignment_status' => 'valid',
        'aggregated_expected_quantity' => 1,
        'aggregated_issued_ticket_count' => 1,
      ],
      'recovery' => ['recovery_mismatch_observed' => FALSE],
      'guest_continuity' => ['continuity_statuses_observed' => ['valid']],
      'artifacts' => [
        'canonical_pdf_readiness' => 'valid',
        'operational_continuity' => [
          '1' => ['continuity_summary' => ['continuity_mode' => 'online']],
        ],
        'timed_entry_policy' => [],
      ],
      'fulfillment_signals' => [
        'by_ticket_id' => [
          '1' => [
            'entitlement_type' => Ticket::ENTITLEMENT_MERCH,
            'fulfilment_status' => Ticket::FULFILMENT_READY,
            'redemption_count' => 0,
            'redemption_limit' => 1,
            'ticket_status' => Ticket::STATUS_ASSIGNED,
            'admission_checked_in' => FALSE,
          ],
          '2' => [
            'entitlement_type' => Ticket::ENTITLEMENT_DRINK,
            'fulfilment_status' => Ticket::FULFILMENT_PENDING,
            'redemption_count' => 1,
            'redemption_limit' => 3,
            'ticket_status' => Ticket::STATUS_ASSIGNED,
            'admission_checked_in' => FALSE,
          ],
        ],
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function sampleMergedWithSecrets(): array {
    $merged = $this->sampleMerged();
    $merged['artifacts']['operational_continuity']['1']['replay_token'] = 'secret-replay';
    $merged['artifacts']['operational_continuity']['1']['qr_payload'] = 'qr-bytes';
    $merged['artifacts']['operational_continuity']['1']['device_fingerprint'] = 'fp-123';
    return $merged;
  }

  private function seedEventTickets(): void {
    $order = $this->loadOrder();
    $this->issuer()->issueForOrder($order);
    $tickets = $this->loadTicketsForOrder((int) $order->id());
    $this->assertNotEmpty($tickets);
    foreach ($tickets as $ticket) {
      $ticket->set('event_id', $this->event->id());
      $ticket->set('entitlement_type', Ticket::ENTITLEMENT_MERCH);
      $ticket->set('fulfilment_status', Ticket::FULFILMENT_READY);
      $ticket->save();
    }
  }

  private function staffWithCapabilityPermission(): User {
    if (!Role::load('mel_ws_capability')) {
      Role::create([
        'id' => 'mel_ws_capability',
        'label' => 'MEL workspace capability',
      ])
        ->grantPermission('view mel venue operations workspace')
        ->grantPermission('govern mel operational capabilities')
        ->save();
    }
    $staff = User::create([
      'name' => 'capability_staff',
      'mail' => 'cap-staff@example.test',
      'status' => 1,
    ]);
    $staff->addRole('mel_ws_capability');
    $staff->save();
    $loaded = User::load($staff->id());
    $this->assertInstanceOf(User::class, $loaded);
    return $loaded;
  }

  private function ensureWorkspaceRoles(): void {
    if (!Role::load('mel_ws_ops_view')) {
      Role::create([
        'id' => 'mel_ws_ops_view',
        'label' => 'MEL workspace view only',
      ])
        ->grantPermission('view mel venue operations workspace')
        ->save();
    }
  }

  private function capabilityManager(): OperationalEntitlementCapabilityManager {
    return $this->container->get('myeventlane_tickets.operational_entitlement_capability_manager');
  }

  private function projectionBuilder(): OperationalCapabilityProjectionBuilder {
    return $this->container->get('myeventlane_tickets.operational_capability_projection_builder');
  }

  private function auditProjector(): OperationalCapabilityAuditProjector {
    return $this->container->get('myeventlane_tickets.operational_capability_audit_projector');
  }

  private function workspaceBuilder(): OperationalWorkspaceBuilder {
    return $this->container->get('myeventlane_tickets.operational_workspace_builder');
  }

  private function issuer(): TicketIssuer {
    return $this->container->get('myeventlane_tickets.ticket_issuer');
  }

  private function loadOrder(): Order {
    $storage = $this->container->get('entity_type.manager')->getStorage('commerce_order');
    $ids = $storage->getQuery()->accessCheck(FALSE)->sort('order_id')->execute();
    $this->assertNotEmpty($ids);
    $order = $storage->load(reset($ids));
    $this->assertInstanceOf(Order::class, $order);
    return $order;
  }

  /**
   * @return array<int, Ticket>
   */
  private function loadTicketsForOrder(int $order_id): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('myeventlane_ticket');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_id', $order_id)
      ->sort('id')
      ->execute();
    if (!$ids) {
      return [];
    }
    return array_values($storage->loadMultiple($ids));
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
      'field_venue_name' => [
        'type' => 'string',
        'settings' => ['max_length' => 255],
      ],
      'field_event_vendor' => [
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'user'],
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

  private function ensureProductEventField(): void {
    if (FieldStorageConfig::loadByName('commerce_product', 'field_event')) {
      return;
    }
    FieldStorageConfig::create([
      'field_name' => 'field_event',
      'entity_type' => 'commerce_product',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'node'],
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_event',
      'entity_type' => 'commerce_product',
      'bundle' => 'default',
      'label' => 'Event',
    ])->save();
  }

}
