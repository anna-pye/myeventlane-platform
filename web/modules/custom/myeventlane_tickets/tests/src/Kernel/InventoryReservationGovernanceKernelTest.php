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
use Drupal\myeventlane_tickets\Service\InventoryReservationAuditProjector;
use Drupal\myeventlane_tickets\Service\InventoryReservationGovernanceManager;
use Drupal\myeventlane_tickets\Service\InventoryReservationProjectionBuilder;
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
 * @coversDefaultClass \Drupal\myeventlane_tickets\Service\InventoryReservationGovernanceManager
 *
 * @group myeventlane_tickets
 */
#[RunTestsInSeparateProcesses]
final class InventoryReservationGovernanceKernelTest extends KernelTestBase {

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
      'name' => 'Reservation Store',
      'mail' => 'store@example.test',
      'default_currency' => 'AUD',
      'status' => 1,
    ]);
    $store->save();

    $customer = User::create([
      'name' => 'reservation_customer',
      'mail' => 'reservation@example.test',
      'status' => 1,
    ]);
    $customer->save();

    $this->event = Node::create([
      'type' => 'event',
      'title' => 'Reservation Event',
      'uid' => $customer->id(),
      'status' => 1,
      'field_event_start' => '2026-08-01T10:00:00',
      'field_event_end' => '2026-08-01T12:00:00',
      'field_venue_name' => 'Hall B',
      'field_event_vendor' => $customer->id(),
    ]);
    $this->event->save();

    $product = Product::create([
      'type' => 'default',
      'title' => 'Reservation Product',
      'stores' => [$store],
      'field_event' => $this->event->id(),
    ]);
    $product->save();

    $variation = ProductVariation::create([
      'type' => 'default',
      'sku' => 'RESERVATION-SKU',
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
      'mail' => 'reservation@example.test',
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

  public function testReservationStateNormalization(): void {
    $m = $this->governanceManager();
    $this->assertSame('unreserved', $m->normalizeReservationState(''));
    $this->assertSame('reserved', $m->normalizeReservationState('reserved'));
    $this->assertSame('ready_for_collection', $m->normalizeReservationState('ready_for_collection'));
  }

  public function testReservationTypeNormalization(): void {
    $m = $this->governanceManager();
    $this->assertSame('digital_redemption', $m->normalizeReservationType(''));
    $this->assertSame('merch', $m->normalizeReservationType('merch'));
    $this->assertSame('timed_pickup', $m->normalizeReservationType('timed_pickup'));
  }

  public function testPartialAllocationSemantics(): void {
    $m = $this->governanceManager();
    $cap = $this->container->get('myeventlane_tickets.entitlement_capability_registry')->getCapabilityMap(Ticket::ENTITLEMENT_DRINK);
    $row = [
      'entitlement_type' => Ticket::ENTITLEMENT_DRINK,
      'redemption_count' => 1,
      'redemption_limit' => 3,
    ];
    $this->assertTrue($m->isPartialAllocation($row, $cap));
    $state = $m->normalizeTicketReservationState(
      $row,
      $cap,
      FALSE,
      'valid',
      'online',
      InventoryReservationGovernanceManager::TYPE_FOOD_DRINK,
      TRUE,
    );
    $this->assertSame('partially_reserved', $state);
  }

  public function testReservationReadinessProjections(): void {
    $model = $this->governanceManager()->composeReservationReadModel($this->sampleMerged(), 1_700_000_000);
    $readiness = $model['readiness_projection'] ?? [];
    $this->assertSame('reservation_readiness_visibility', $readiness['descriptor'] ?? '');
    $this->assertArrayHasKey('collection_ready_rows', $readiness);
  }

  public function testReservationDegradationProjections(): void {
    $merged = $this->sampleMerged();
    $merged['recovery']['recovery_mismatch_observed'] = TRUE;
    $model = $this->governanceManager()->composeReservationReadModel($merged, 1_700_000_000);
    $degradation = $model['degradation_projection'] ?? [];
    $this->assertNotSame('nominal', $degradation['descriptor'] ?? 'nominal');
  }

  public function testReservationContinuityOrdering(): void {
    $timeline = $this->governanceManager()->projectReservationAuditTimeline([
      ['state' => 'reserved', 'recorded_at' => 200, 'note' => 'second'],
      ['state' => 'allocated', 'recorded_at' => 100, 'note' => 'first'],
    ]);
    $this->assertSame(100, $timeline[0]['recorded_at_unix']);
    $this->assertSame(200, $timeline[1]['recorded_at_unix']);
  }

  public function testWorkspaceReservationRendering(): void {
    $this->seedEventTickets();
    $staff = $this->staffWithReservationPermission();
    /** @var AccountSwitcherInterface $switcher */
    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($staff);

    $workspace = $this->workspaceBuilder()->build($this->event);
    $ids = array_column($workspace['sections'], 'id');
    $this->assertContains('reservation_governance_summary', $ids);
    $this->assertContains('reservation_lifecycle_cards', $ids);
    $this->assertContains('reservation_allocation_visibility', $ids);
    $this->assertContains('reservation_lifecycle_audit_timeline', $ids);

    $switcher->switchBack();
  }

  public function testPermissionSeparation(): void {
    $this->seedEventTickets();
    $viewer = User::create([
      'name' => 'reservation_view_only',
      'mail' => 'res-view@example.test',
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
    $this->assertFalse($workspace['meta']['reservation_projection_enabled']);
    $ids = array_column($workspace['sections'], 'id');
    $this->assertNotContains('reservation_governance_summary', $ids);

    $switcher->switchBack();
  }

  public function testAuditContinuityOrdering(): void {
    $sections = $this->auditProjector()->buildWorkspaceReservationAuditSections($this->sampleMerged(), 1_700_000_000);
    $timeline = $sections[0]['timeline'] ?? [];
    $this->assertNotEmpty($timeline);
    $times = array_column($timeline, 'recorded_at_unix');
    $sorted = $times;
    sort($sorted);
    $this->assertSame($sorted, $times);
  }

  public function testNoReplayLeakage(): void {
    $merged = $this->sampleMergedWithSecrets();
    $sections = $this->projectionBuilder()->buildWorkspaceReservationSections($merged, 1_700_000_000);
    $blob = json_encode($sections, JSON_THROW_ON_ERROR);
    $this->assertStringNotContainsString('replay_token', $blob);
  }

  public function testNoQrLeakage(): void {
    $sections = $this->auditProjector()->buildWorkspaceReservationAuditSections($this->sampleMergedWithSecrets(), 1_700_000_000);
    $blob = json_encode($sections, JSON_THROW_ON_ERROR);
    $this->assertStringNotContainsString('qr_payload', $blob);
  }

  public function testNoDeviceFingerprintLeakage(): void {
    $sections = $this->auditProjector()->buildWorkspaceReservationAuditSections($this->sampleMergedWithSecrets(), 1_700_000_000);
    $blob = json_encode($sections, JSON_THROW_ON_ERROR);
    $this->assertStringNotContainsString('device_fingerprint', $blob);
    $this->assertStringNotContainsString('fingerprint', strtolower($blob));
  }

  public function testInvalidReservationNormalization(): void {
    $m = $this->governanceManager();
    $this->assertSame('unreserved', $m->normalizeReservationState('not_a_state'));
    $this->assertSame('digital_redemption', $m->normalizeReservationType('warehouse_slot'));
  }

  public function testReservationLifecycleContinuity(): void {
    $model = $this->governanceManager()->composeReservationReadModel($this->sampleMerged(), 1_700_000_000);
    $continuity = $model['continuity_projection'] ?? [];
    $this->assertSame('reservation_continuity_visibility', $continuity['descriptor'] ?? '');
    $this->assertArrayHasKey('guest_continuity_statuses', $continuity);
  }

  public function testAllocationDegradationSemantics(): void {
    $merged = $this->sampleMerged();
    $merged['issuance']['worst_quantity_alignment_status'] = 'invalid';
    $model = $this->governanceManager()->composeReservationReadModel($merged, 1_700_000_000);
    $allocation = $model['allocation_projection'] ?? [];
    $this->assertSame('allocation_visibility', $allocation['descriptor'] ?? '');
    $degradation = $model['degradation_projection'] ?? [];
    $this->assertStringContainsString('rollup_or_continuity_degraded', (string) ($degradation['descriptor'] ?? ''));
  }

  public function testTimedPickupReservationSemantics(): void {
    $m = $this->governanceManager();
    $type = $m->mapEntitlementToReservationType(Ticket::ENTITLEMENT_TICKET, 1, 'early');
    $this->assertSame('timed_pickup', $type);
    $merged = $this->sampleMerged();
    $merged['artifacts']['timed_entry_policy'] = [
      '1' => [
        'policy' => [
          'scanner' => ['state' => 'open'],
        ],
      ],
    ];
    $model = $m->composeReservationReadModel($merged, 1_700_000_000);
    $readiness = $model['readiness_projection'] ?? [];
    $this->assertArrayHasKey('timed_pickup_ready_rows', $readiness);
  }

  public function testRollupCompositionContinuity(): void {
    $m = $this->governanceManager();
    $state = $m->projectReservationStateFromRollup($this->sampleMerged());
    $this->assertContains($state, $m->allCanonicalStates());
    $rollup = $m->composeReservationReadModel($this->sampleMerged(), 1_700_000_000)['rollup'] ?? [];
    $this->assertArrayHasKey('rollup_continuity_composition', $rollup);
    $this->assertArrayHasKey('canonical_reservation_state_tally', $rollup);
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

  private function staffWithReservationPermission(): User {
    if (!Role::load('mel_ws_reservation')) {
      Role::create([
        'id' => 'mel_ws_reservation',
        'label' => 'MEL workspace reservation',
      ])
        ->grantPermission('view mel venue operations workspace')
        ->grantPermission('govern mel inventory reservations')
        ->save();
    }
    $staff = User::create([
      'name' => 'reservation_staff',
      'mail' => 'res-staff@example.test',
      'status' => 1,
    ]);
    $staff->addRole('mel_ws_reservation');
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

  private function governanceManager(): InventoryReservationGovernanceManager {
    return $this->container->get('myeventlane_tickets.inventory_reservation_governance_manager');
  }

  private function projectionBuilder(): InventoryReservationProjectionBuilder {
    return $this->container->get('myeventlane_tickets.inventory_reservation_projection_builder');
  }

  private function auditProjector(): InventoryReservationAuditProjector {
    return $this->container->get('myeventlane_tickets.inventory_reservation_audit_projector');
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
