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
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Service\EntitlementCapabilityRegistry;
use Drupal\myeventlane_tickets\Service\FulfillmentAuditProjector;
use Drupal\myeventlane_tickets\Service\FulfillmentExecutionProjectionBuilder;
use Drupal\myeventlane_tickets\Service\FulfillmentLifecycleManager;
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
 * @coversDefaultClass \Drupal\myeventlane_tickets\Service\FulfillmentLifecycleManager
 *
 * @group myeventlane_tickets
 */
#[RunTestsInSeparateProcesses]
final class FulfillmentLifecycleConvergenceKernelTest extends KernelTestBase {

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
    $this->ensureRoles();

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
      'name' => 'Fulfillment Store',
      'mail' => 'store@example.test',
      'default_currency' => 'AUD',
      'status' => 1,
    ]);
    $store->save();

    $customer = User::create([
      'name' => 'fulfillment_customer',
      'mail' => 'fulfillment@example.test',
      'status' => 1,
    ]);
    $customer->save();

    $this->event = Node::create([
      'type' => 'event',
      'title' => 'Fulfillment Event',
      'uid' => $customer->id(),
      'status' => 1,
      'field_event_start' => '2026-08-01T10:00:00',
      'field_event_end' => '2026-08-01T12:00:00',
      'field_venue_name' => 'Hall A',
      'field_event_vendor' => $customer->id(),
    ]);
    $this->event->save();

    $product = Product::create([
      'type' => 'default',
      'title' => 'Fulfillment Product',
      'stores' => [$store],
      'field_event' => $this->event->id(),
    ]);
    $product->save();

    $variation = ProductVariation::create([
      'type' => 'default',
      'sku' => 'FULFILL-SKU',
      'title' => 'GA',
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
      'mail' => 'fulfillment@example.test',
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

  public function testNormalizeInvalidFulfilmentStatus(): void {
    $cap = $this->registry()->getCapabilityMap(Ticket::ENTITLEMENT_TICKET);
    $row = [
      'entitlement_type' => Ticket::ENTITLEMENT_TICKET,
      'fulfilment_status' => 'not_a_real_status',
      'redemption_count' => 0,
      'redemption_limit' => 1,
      'ticket_status' => Ticket::STATUS_ASSIGNED,
      'admission_checked_in' => FALSE,
    ];
    $state = $this->lifecycleManager()->normalizeTicketLifecycleState($row, $cap, FALSE, 'valid', FulfillmentLifecycleManager::TYPE_ADMISSION);
    $this->assertSame(FulfillmentLifecycleManager::STATE_PENDING, $state);
  }

  public function testMapEntitlementToFulfillmentTypes(): void {
    $m = $this->lifecycleManager();
    $this->assertSame(FulfillmentLifecycleManager::TYPE_ADMISSION, $m->mapEntitlementToFulfillmentType(Ticket::ENTITLEMENT_TICKET, 1));
    $this->assertSame(FulfillmentLifecycleManager::TYPE_MERCH_PICKUP, $m->mapEntitlementToFulfillmentType(Ticket::ENTITLEMENT_MERCH, 1));
    $this->assertSame(FulfillmentLifecycleManager::TYPE_HOSPITALITY, $m->mapEntitlementToFulfillmentType(Ticket::ENTITLEMENT_DRINK, 4));
    $this->assertSame(FulfillmentLifecycleManager::TYPE_MULTI_REDEEM, $m->mapEntitlementToFulfillmentType(Ticket::ENTITLEMENT_ADDON, 4));
    $this->assertSame(FulfillmentLifecycleManager::TYPE_EQUIPMENT, $m->mapEntitlementToFulfillmentType(Ticket::ENTITLEMENT_ADDON, 1));
  }

  public function testPartialMultiRedeemAndConsumedPrecedence(): void {
    $m = $this->lifecycleManager();
    $cap = $this->registry()->getCapabilityMap(Ticket::ENTITLEMENT_DRINK);
    $partial_row = [
      'entitlement_type' => Ticket::ENTITLEMENT_DRINK,
      'fulfilment_status' => Ticket::FULFILMENT_PENDING,
      'redemption_count' => 2,
      'redemption_limit' => 4,
      'ticket_status' => Ticket::STATUS_ASSIGNED,
      'admission_checked_in' => FALSE,
    ];
    $this->assertTrue($m->isPartialFulfillment($partial_row, $cap));
    $this->assertSame(FulfillmentLifecycleManager::STATE_PARTIALLY_FULFILLED, $m->normalizeTicketLifecycleState(
      $partial_row,
      $cap,
      FALSE,
      'valid',
      FulfillmentLifecycleManager::TYPE_HOSPITALITY,
    ));

    $consumed_row = [
      'entitlement_type' => Ticket::ENTITLEMENT_DRINK,
      'fulfilment_status' => Ticket::FULFILMENT_REDEEMED,
      'redemption_count' => 4,
      'redemption_limit' => 4,
      'ticket_status' => Ticket::STATUS_ASSIGNED,
      'admission_checked_in' => FALSE,
    ];
    $this->assertSame(FulfillmentLifecycleManager::STATE_CONSUMED, $m->normalizeTicketLifecycleState(
      $consumed_row,
      $cap,
      FALSE,
      'valid',
      FulfillmentLifecycleManager::TYPE_HOSPITALITY,
    ));
    $this->assertFalse($m->isPartialFulfillment($consumed_row, $cap));
  }

  public function testAdmissionCheckedInMapsFulfilled(): void {
    $m = $this->lifecycleManager();
    $cap = $this->registry()->getCapabilityMap(Ticket::ENTITLEMENT_TICKET);
    $row = [
      'entitlement_type' => Ticket::ENTITLEMENT_TICKET,
      'fulfilment_status' => Ticket::FULFILMENT_PENDING,
      'redemption_count' => 0,
      'redemption_limit' => 1,
      'ticket_status' => Ticket::STATUS_CHECKED_IN,
      'admission_checked_in' => TRUE,
    ];
    $this->assertSame(FulfillmentLifecycleManager::STATE_FULFILLED, $m->normalizeTicketLifecycleState(
      $row,
      $cap,
      FALSE,
      'valid',
      FulfillmentLifecycleManager::TYPE_ADMISSION,
    ));
  }

  public function testMerchPreparedWhenPdfValid(): void {
    $m = $this->lifecycleManager();
    $cap = $this->registry()->getCapabilityMap(Ticket::ENTITLEMENT_MERCH);
    $row = [
      'entitlement_type' => Ticket::ENTITLEMENT_MERCH,
      'fulfilment_status' => Ticket::FULFILMENT_PENDING,
      'redemption_count' => 0,
      'redemption_limit' => 1,
      'ticket_status' => Ticket::STATUS_ASSIGNED,
      'admission_checked_in' => FALSE,
    ];
    $this->assertSame(FulfillmentLifecycleManager::STATE_PREPARED, $m->normalizeTicketLifecycleState(
      $row,
      $cap,
      FALSE,
      'valid',
      FulfillmentLifecycleManager::TYPE_MERCH_PICKUP,
    ));
  }

  public function testRollupFailedForcesFailedState(): void {
    $m = $this->lifecycleManager();
    $cap = $this->registry()->getCapabilityMap(Ticket::ENTITLEMENT_TICKET);
    $row = [
      'entitlement_type' => Ticket::ENTITLEMENT_TICKET,
      'fulfilment_status' => Ticket::FULFILMENT_READY,
      'redemption_count' => 0,
      'redemption_limit' => 1,
      'ticket_status' => Ticket::STATUS_ASSIGNED,
      'admission_checked_in' => FALSE,
    ];
    $this->assertSame(FulfillmentLifecycleManager::STATE_FAILED, $m->normalizeTicketLifecycleState(
      $row,
      $cap,
      TRUE,
      'valid',
      FulfillmentLifecycleManager::TYPE_ADMISSION,
    ));
  }

  public function testComposeReadModelRollupContinuity(): void {
    $merged = [
      'issuance' => [
        'worst_quantity_alignment_status' => 'valid',
      ],
      'recovery' => [
        'recovery_mismatch_observed' => FALSE,
      ],
      'guest_continuity' => [
        'continuity_statuses_observed' => ['valid'],
      ],
      'artifacts' => [
        'canonical_pdf_readiness' => 'valid',
        'operational_continuity' => [
          '10' => [
            'timing_session_composition_refs' => [
              'timed_scanner_state' => 'open',
              'session_scanner_state' => 'active',
            ],
          ],
        ],
      ],
      'fulfillment_signals' => [
        'by_ticket_id' => [
          '10' => [
            'entitlement_type' => Ticket::ENTITLEMENT_TICKET,
            'fulfilment_status' => Ticket::FULFILMENT_PENDING,
            'redemption_count' => 0,
            'redemption_limit' => 1,
            'ticket_status' => Ticket::STATUS_ASSIGNED,
            'admission_checked_in' => FALSE,
          ],
        ],
      ],
    ];
    $model = $this->lifecycleManager()->composeFulfillmentReadModel($merged, 100);
    $this->assertSame(['valid'], $model['rollup']['rollup_continuity_composition']['guest_continuity_statuses']);
    $this->assertStringContainsString('scanner_action=', (string) ($model['ticket_projections'][0]['redemption_execution_summary'] ?? ''));
  }

  public function testWorkspaceOmitsFulfillmentWithoutPermission(): void {
    $this->prepareIssuedTicketOnEvent();
    $viewer = User::create([
      'name' => 'fulfillment_ops_view',
      'mail' => 'fulfill-view@example.test',
      'status' => 1,
    ]);
    $viewer->addRole('mel_ws_ops_view');
    $viewer->save();
    $viewer = User::load($viewer->id());
    $this->assertNotFalse($viewer);

    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($viewer);
    $workspace = $this->workspaceBuilder()->build($this->event);
    $this->assertFalse($workspace['meta']['fulfillment_projection_enabled']);
    $ids = array_column($workspace['sections'], 'id');
    $this->assertNotContains('fulfillment_execution_summary', $ids);
    $switcher->switchBack();
  }

  public function testWorkspaceIncludesFulfillmentWithPermissionAndNoReplayLeakage(): void {
    $this->prepareIssuedTicketOnEvent();
    $user = User::create([
      'name' => 'fulfillment_only',
      'mail' => 'fulfill-only@example.test',
      'status' => 1,
    ]);
    $user->addRole('mel_ws_fulfillment_only');
    $user->save();
    $user = User::load($user->id());
    $this->assertNotFalse($user);

    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($user);
    $workspace = $this->workspaceBuilder()->build($this->event);
    $this->assertTrue($workspace['meta']['fulfillment_projection_enabled']);
    $ids = array_column($workspace['sections'], 'id');
    $this->assertContains('fulfillment_execution_summary', $ids);
    $this->assertContains('fulfillment_lifecycle_audit_timeline', $ids);
    $encoded = json_encode($workspace['sections'], JSON_THROW_ON_ERROR);
    $this->assertStringNotContainsString('replay_token', $encoded);
    $this->assertStringNotContainsString('qr_payload', $encoded);
    $this->assertStringNotContainsString('device_fingerprint', $encoded);
    $switcher->switchBack();
  }

  public function testFulfillmentPermissionIsolatedFromCoordination(): void {
    $this->prepareIssuedTicketOnEvent();
    $user = User::create([
      'name' => 'fulfillment_iso',
      'mail' => 'fulfill-iso@example.test',
      'status' => 1,
    ]);
    $user->addRole('mel_ws_fulfillment_only');
    $user->save();
    $user = User::load($user->id());
    $this->assertNotFalse($user);

    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($user);
    $workspace = $this->workspaceBuilder()->build($this->event);
    $this->assertFalse($workspace['meta']['coordination_projection_enabled']);
    $ids = array_column($workspace['sections'], 'id');
    $this->assertContains('fulfillment_lifecycle_cards', $ids);
    $this->assertNotContains('operational_coordination', $ids);
    $switcher->switchBack();
  }

  public function testAuditTimelineOrderingMatchesTicketOrdinals(): void {
    $merged = [
      'issuance' => ['worst_quantity_alignment_status' => 'valid'],
      'recovery' => ['recovery_mismatch_observed' => FALSE],
      'guest_continuity' => ['continuity_statuses_observed' => ['valid']],
      'artifacts' => [
        'canonical_pdf_readiness' => 'valid',
        'operational_continuity' => [],
      ],
      'fulfillment_signals' => [
        'by_ticket_id' => [
          '50' => [
            'entitlement_type' => Ticket::ENTITLEMENT_TICKET,
            'fulfilment_status' => Ticket::FULFILMENT_PENDING,
            'redemption_count' => 0,
            'redemption_limit' => 1,
            'ticket_status' => Ticket::STATUS_ASSIGNED,
            'admission_checked_in' => FALSE,
          ],
          '12' => [
            'entitlement_type' => Ticket::ENTITLEMENT_MERCH,
            'fulfilment_status' => Ticket::FULFILMENT_READY,
            'redemption_count' => 0,
            'redemption_limit' => 1,
            'ticket_status' => Ticket::STATUS_ASSIGNED,
            'admission_checked_in' => FALSE,
          ],
        ],
      ],
    ];
    $audit = $this->fulfillmentAuditProjector()->buildWorkspaceFulfillmentAuditSections($merged, 1000);
    $this->assertNotEmpty($audit);
    $timeline = $audit[0]['timeline'] ?? [];
    $this->assertCount(2, $timeline);
    $this->assertSame(1001, (int) ($timeline[0]['recorded_at_unix'] ?? 0));
    $this->assertSame(1002, (int) ($timeline[1]['recorded_at_unix'] ?? 0));
    $this->assertSame('ready', (string) ($timeline[0]['lane_state'] ?? ''));
    $this->assertSame('pending', (string) ($timeline[1]['lane_state'] ?? ''));
    $ordering = $audit[0]['cards'][0]['value'] ?? '';
    $this->assertSame('ready → pending', (string) $ordering);
  }

  public function testExecutionProjectionPickupAndReadinessDescriptors(): void {
    $merged = [
      'issuance' => ['worst_quantity_alignment_status' => 'valid'],
      'recovery' => ['recovery_mismatch_observed' => FALSE],
      'guest_continuity' => ['continuity_statuses_observed' => ['valid']],
      'artifacts' => [
        'canonical_pdf_readiness' => 'valid',
        'operational_continuity' => [],
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
        ],
      ],
    ];
    $sections = $this->fulfillmentExecutionProjectionBuilder()->buildWorkspaceFulfillmentSections($merged, 200);
    $this->assertNotEmpty($sections);
    $this->assertSame('fulfillment_pickup_redemption_visibility', $sections[2]['id']);
    $pickup_card = $sections[2]['cards'][0] ?? [];
    $this->assertSame('1', (string) ($pickup_card['value'] ?? ''));
  }

  private function prepareIssuedTicketOnEvent(): void {
    $order = $this->loadOrder();
    $this->issuer()->issueForOrder($order);
    foreach ($this->loadTicketsForOrder((int) $order->id()) as $ticket) {
      $ticket->set('event_id', $this->event->id());
      $ticket->save();
    }
  }

  private function ensureRoles(): void {
    $ops_view = Role::load('mel_ws_ops_view') ?? Role::create([
      'id' => 'mel_ws_ops_view',
      'label' => 'MEL workspace view only',
    ]);
    $ops_view->grantPermission('view mel venue operations workspace')->save();

    $fulfill_only = Role::load('mel_ws_fulfillment_only') ?? Role::create([
      'id' => 'mel_ws_fulfillment_only',
      'label' => 'MEL workspace fulfillment governance only',
    ]);
    $fulfill_only->grantPermission('view mel venue operations workspace')
      ->grantPermission('govern mel fulfillment lifecycle')
      ->save();
  }

  private function issuer(): TicketIssuer {
    return $this->container->get('myeventlane_tickets.ticket_issuer');
  }

  private function workspaceBuilder(): OperationalWorkspaceBuilder {
    return $this->container->get('myeventlane_tickets.operational_workspace_builder');
  }

  private function lifecycleManager(): FulfillmentLifecycleManager {
    return $this->container->get('myeventlane_tickets.fulfillment_lifecycle_manager');
  }

  private function registry(): EntitlementCapabilityRegistry {
    return $this->container->get('myeventlane_tickets.entitlement_capability_registry');
  }

  private function fulfillmentAuditProjector(): FulfillmentAuditProjector {
    return $this->container->get('myeventlane_tickets.fulfillment_audit_projector');
  }

  private function fulfillmentExecutionProjectionBuilder(): FulfillmentExecutionProjectionBuilder {
    return $this->container->get('myeventlane_tickets.fulfillment_execution_projection_builder');
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
   * @return array<int, \Drupal\myeventlane_tickets\Entity\Ticket>
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
