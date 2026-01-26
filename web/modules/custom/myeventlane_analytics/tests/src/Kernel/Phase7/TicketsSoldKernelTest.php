<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_analytics\Kernel\Phase7;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_price\Entity\Currency;
use Drupal\commerce_price\Price;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\myeventlane_analytics\Phase7\Exception\AccessDeniedAnalyticsException;
use Drupal\myeventlane_analytics\Phase7\Exception\InvalidScopeException;
use Drupal\myeventlane_analytics\Phase7\Value\AnalyticsQuery;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Kernel tests for Tickets Sold metric (Phase 7, order-item anchored).
 *
 * This test validates correctness and fail-closed behaviour for Tickets Sold
 * without implementing any other metric.
 *
 * @group myeventlane_analytics
 */
final class TicketsSoldKernelTest extends AnalyticsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // Core.
    'system',
    'user',
    'field',
    'node',
    'options',
    'views',
    // Commerce.
    'commerce',
    'commerce_price',
    'commerce_store',
    'commerce_number_pattern',
    'commerce_order',
    // Commerce order dependencies.
    'entity_reference_revisions',
    'profile',
    'state_machine',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('node');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installConfig(['commerce_order']);

    $this->ensureAudCurrency();
    $this->ensureEventContentType();
    $this->ensureOrderTypes();
    $this->ensureEventStoreField();
    $this->ensureOrderItemTargetEventField();
    $this->ensureRefundLogTable();
  }

  /**
   * Validates Tickets Sold definition and guardrails.
   */
  public function testTicketsSoldOrderItemAnchored(): void {
    // Create admin first so it becomes UID 1 (admin override for scope resolver).
    $admin = $this->createUserAccount('admin');
    $this->assertSame(1, (int) $admin->id(), 'Expected first created user to be UID 1.');

    $vendor_a = $this->createUserAccount('vendor_a');
    $vendor_b = $this->createUserAccount('vendor_b');

    $store_a = $this->createOnlineStore($vendor_a, 'Store A');
    $store_b = $this->createOnlineStore($vendor_b, 'Store B');

    $event_a = $this->createEventForStore('Event A', (int) $store_a->id());
    $event_b = $this->createEventForStore('Event B', (int) $store_b->id());

    $range_start = 100;
    $range_end = 200;

    // One completed order that contains:
    // - 1 paid item for Event A (quantity>1 should still count as 1 order item)
    // - 1 zero-priced item for Event A (excluded)
    // - 1 paid item for Event B (allocates to Store B)
    // - 1 paid item not linked to any event (excluded; prevents "whole-order totals")
    $order_1 = $this->createCompletedOrder((int) $store_a->id(), 150);
    $this->createOrderItem($order_1, 'default', new Price('10.00', 'AUD'), 3, (int) $event_a->id());
    $this->createOrderItem($order_1, 'default', new Price('0.00', 'AUD'), 1, (int) $event_a->id());
    $this->createOrderItem($order_1, 'default', new Price('20.00', 'AUD'), 1, (int) $event_b->id());
    $this->createOrderItem($order_1, 'default', new Price('15.00', 'AUD'), 1, NULL);

    // Draft order should be excluded.
    $order_2 = $this->createOrder((int) $store_a->id(), 'draft', 150);
    $this->createOrderItem($order_2, 'default', new Price('10.00', 'AUD'), 1, (int) $event_a->id());

    // Completed order outside range should be excluded.
    $order_3 = $this->createCompletedOrder((int) $store_a->id(), 999);
    $this->createOrderItem($order_3, 'default', new Price('10.00', 'AUD'), 1, (int) $event_a->id());

    $service = $this->createQueryService();

    // Admin query over both stores (returns grouped by store_id + event_id).
    $this->switchToUser($admin);
    $admin_query = $this->buildQuery(
      scope: AnalyticsQuery::SCOPE_ADMIN,
      store_ids: [(int) $store_a->id(), (int) $store_b->id()],
      start_ts: $range_start,
      end_ts: $range_end,
      currency: NULL,
    );

    $rows = $service->getTicketsSold($admin_query);
    $map = $this->indexRowsByStoreEvent($rows);

    // 1) Paid ticket counts correctly (count order items, not quantities).
    $this->assertSame(1, $map[(int) $store_a->id()][(int) $event_a->id()] ?? 0);

    // 2) Zero-priced items are excluded.
    $this->assertSame(1, $map[(int) $store_a->id()][(int) $event_a->id()] ?? 0);

    // 4) Multi-event order allocates per event.
    $this->assertSame(1, $map[(int) $store_b->id()][(int) $event_b->id()] ?? 0);

    // 5) Multi-store order allocates per store (within the same order).
    $this->assertArrayHasKey((int) $store_a->id(), $map);
    $this->assertArrayHasKey((int) $store_b->id(), $map);

    // 8) No whole-order totals are used: event-less paid items must not be counted.
    $this->assertSame(2, count($rows), 'Only event-linked paid items should be counted.');

    // 3) Refunds do NOT reduce count: insert completed refund log row and re-run.
    $this->container->get('database')->insert('myeventlane_refund_log')->fields([
      'order_id' => (int) $order_1->id(),
      'event_id' => (int) $event_a->id(),
      'vendor_uid' => (int) $vendor_a->id(),
      'refund_type' => 'full',
      'refund_scope' => 'tickets_only',
      'amount_cents' => 1000,
      'currency' => 'AUD',
      'donation_refunded' => 0,
      'stripe_refund_id' => NULL,
      'status' => 'completed',
      'reason' => NULL,
      'created' => 160,
      'completed' => 160,
      'error_message' => NULL,
    ])->execute();

    $rows_after_refund = $service->getTicketsSold($admin_query);
    $map_after_refund = $this->indexRowsByStoreEvent($rows_after_refund);
    $this->assertSame(1, $map_after_refund[(int) $store_a->id()][(int) $event_a->id()] ?? 0);

    // 6) Vendor cannot query another store: vendor scope must only return Store A.
    $this->switchToUser($vendor_a);
    $vendor_query = $this->buildQuery(
      scope: AnalyticsQuery::SCOPE_VENDOR,
      store_ids: [(int) $store_b->id()],
      start_ts: $range_start,
      end_ts: $range_end,
      currency: NULL,
    );

    $vendor_rows = $service->getTicketsSold($vendor_query);
    $vendor_map = $this->indexRowsByStoreEvent($vendor_rows);
    $this->assertArrayHasKey((int) $store_a->id(), $vendor_map);
    $this->assertArrayNotHasKey((int) $store_b->id(), $vendor_map);
    $this->assertSame(1, $vendor_map[(int) $store_a->id()][(int) $event_a->id()] ?? 0);

    // 7) Missing / invalid scope throws exception (fail-closed).
    $this->expectException(InvalidScopeException::class);
    $invalid_scope_query = $this->buildQuery(
      scope: 'invalid-scope',
      store_ids: [],
      start_ts: $range_start,
      end_ts: $range_end,
      currency: NULL,
    );
    $service->getTicketsSold($invalid_scope_query);
  }

  /**
   * Vendor admin-scope attempts must throw (fail-closed).
   */
  public function testVendorCannotQueryAdminScope(): void {
    $admin = $this->createUserAccount('admin');
    $this->assertSame(1, (int) $admin->id(), 'Expected first created user to be UID 1.');

    $vendor = $this->createUserAccount('vendor');
    $other = $this->createUserAccount('other');

    $store_vendor = $this->createOnlineStore($vendor, 'Vendor Store');
    $store_other = $this->createOnlineStore($other, 'Other Store');

    $this->switchToUser($vendor);
    $service = $this->createQueryService();

    $query = $this->buildQuery(
      scope: AnalyticsQuery::SCOPE_ADMIN,
      store_ids: [(int) $store_other->id()],
      start_ts: 1,
      end_ts: 2,
      currency: NULL,
    );

    $this->expectException(AccessDeniedAnalyticsException::class);
    $service->getTicketsSold($query);
  }

  /**
   * Ensures the 'event' node type exists.
   */
  private function ensureEventContentType(): void {
    if (!NodeType::load('event')) {
      NodeType::create([
        'type' => 'event',
        'name' => 'Event',
      ])->save();
    }
  }

  /**
   * Ensures Commerce order and order item types exist.
   */
  private function ensureOrderTypes(): void {
    if (!OrderType::load('default')) {
      OrderType::create([
        'id' => 'default',
        'label' => 'Default',
        'workflow' => 'order_default',
      ])->save();
    }

    if (!OrderItemType::load('default')) {
      OrderItemType::create([
        'id' => 'default',
        'label' => 'Default',
        'orderType' => 'default',
      ])->save();
    }
  }

  /**
   * Ensures event->store field storage exists (node.field_event_store).
   */
  private function ensureEventStoreField(): void {
    if (!FieldStorageConfig::loadByName('node', 'field_event_store')) {
      FieldStorageConfig::create([
        'field_name' => 'field_event_store',
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'settings' => [
          'target_type' => 'commerce_store',
        ],
        'cardinality' => 1,
        'translatable' => TRUE,
      ])->save();
    }

    if (!FieldConfig::loadByName('node', 'event', 'field_event_store')) {
      FieldConfig::create([
        'field_name' => 'field_event_store',
        'entity_type' => 'node',
        'bundle' => 'event',
        'label' => 'Store',
        'required' => FALSE,
      ])->save();
    }
  }

  /**
   * Ensures order item -> event reference field storage exists.
   */
  private function ensureOrderItemTargetEventField(): void {
    if (!FieldStorageConfig::loadByName('commerce_order_item', 'field_target_event')) {
      FieldStorageConfig::create([
        'field_name' => 'field_target_event',
        'entity_type' => 'commerce_order_item',
        'type' => 'entity_reference',
        'settings' => [
          'target_type' => 'node',
        ],
        'cardinality' => 1,
        'translatable' => TRUE,
      ])->save();
    }

    if (!FieldConfig::loadByName('commerce_order_item', 'default', 'field_target_event')) {
      FieldConfig::create([
        'field_name' => 'field_target_event',
        'entity_type' => 'commerce_order_item',
        'bundle' => 'default',
        'label' => 'Target Event',
        'required' => FALSE,
      ])->save();
    }
  }

  /**
   * Ensures AUD currency exists for Price objects used in fixtures.
   */
  private function ensureAudCurrency(): void {
    if (Currency::load('AUD')) {
      return;
    }

    Currency::create([
      'currencyCode' => 'AUD',
      'name' => 'Australian Dollar',
      'numericCode' => '036',
      'symbol' => '$',
      'fractionDigits' => 2,
    ])->save();
  }

  /**
   * Ensures the refund log table exists for fixture insertion.
   *
   * Tickets Sold must not be reduced by refunds, so we create a refund log row
   * and assert the metric is unchanged. We do not enable refund modules here
   * to keep the kernel harness minimal.
   */
  private function ensureRefundLogTable(): void {
    $schema = $this->container->get('database')->schema();
    if ($schema->tableExists('myeventlane_refund_log')) {
      return;
    }

    $schema->createTable('myeventlane_refund_log', [
      'description' => 'Test fixture table for refund log (Phase 7 kernel tests).',
      'fields' => [
        'id' => [
          'type' => 'serial',
          'not null' => TRUE,
        ],
        'order_id' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'event_id' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'vendor_uid' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'refund_type' => [
          'type' => 'varchar',
          'length' => 32,
          'not null' => TRUE,
        ],
        'refund_scope' => [
          'type' => 'varchar',
          'length' => 64,
          'not null' => TRUE,
        ],
        'amount_cents' => [
          'type' => 'int',
          'not null' => TRUE,
        ],
        'currency' => [
          'type' => 'varchar',
          'length' => 3,
          'not null' => TRUE,
        ],
        'donation_refunded' => [
          'type' => 'int',
          'size' => 'tiny',
          'not null' => TRUE,
          'default' => 0,
        ],
        'stripe_refund_id' => [
          'type' => 'varchar',
          'length' => 255,
          'not null' => FALSE,
        ],
        'status' => [
          'type' => 'varchar',
          'length' => 32,
          'not null' => TRUE,
        ],
        'reason' => [
          'type' => 'text',
          'not null' => FALSE,
        ],
        'created' => [
          'type' => 'int',
          'not null' => TRUE,
        ],
        'completed' => [
          'type' => 'int',
          'not null' => FALSE,
        ],
        'error_message' => [
          'type' => 'text',
          'not null' => FALSE,
        ],
      ],
      'primary key' => ['id'],
      'indexes' => [
        'order_id' => ['order_id'],
        'event_id' => ['event_id'],
        'vendor_uid' => ['vendor_uid'],
        'status' => ['status'],
      ],
    ]);
  }

  /**
   * Creates a published event node linked to a store.
   */
  private function createEventForStore(string $title, int $store_id): Node {
    $event = Node::create([
      'type' => 'event',
      'title' => $title,
      'status' => 1,
      'field_event_store' => ['target_id' => $store_id],
    ]);
    $event->save();
    return $event;
  }

  /**
   * Creates an order with given state and placed time.
   */
  private function createOrder(int $store_id, string $state, int $placed): Order {
    $order = Order::create([
      'type' => 'default',
      'store_id' => $store_id,
      'state' => $state,
      'uid' => 0,
      'placed' => $placed,
    ]);
    $order->save();
    return $order;
  }

  /**
   * Creates a completed order.
   */
  private function createCompletedOrder(int $store_id, int $placed): Order {
    return $this->createOrder($store_id, 'completed', $placed);
  }

  /**
   * Creates an order item optionally linked to an event.
   */
  private function createOrderItem(
    Order $order,
    string $bundle,
    Price $unit_price,
    int $quantity,
    ?int $event_id,
  ): OrderItem {
    $item = OrderItem::create([
      'type' => $bundle,
      'order_id' => $order->id(),
      'unit_price' => $unit_price,
      'quantity' => $quantity,
    ]);
    if ($event_id !== NULL && $item->hasField('field_target_event')) {
      $item->set('field_target_event', ['target_id' => $event_id]);
    }
    $item->save();

    $order->addItem($item);
    $order->save();

    return $item;
  }

  /**
   * Indexes CountByStoreEventRow results into [store_id][event_id] => count.
   *
   * @param array $rows
   *   Rows from AnalyticsQueryService::getTicketsSold().
   *
   * @return array<int, array<int, int>>
   *   Map.
   */
  private function indexRowsByStoreEvent(array $rows): array {
    $map = [];
    foreach ($rows as $row) {
      $store_id = (int) $row->store_id;
      $event_id = (int) $row->event_id;
      $count = (int) $row->count;
      $map[$store_id][$event_id] = $count;
    }
    return $map;
  }

}

