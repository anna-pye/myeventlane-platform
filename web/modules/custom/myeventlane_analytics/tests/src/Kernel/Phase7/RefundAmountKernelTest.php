<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_analytics\Kernel\Phase7;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_price\Entity\Currency;
use Drupal\commerce_price\Price;
use Drupal\Core\Database\SchemaObjectExistsException;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\myeventlane_analytics\Phase7\Exception\AccessDeniedAnalyticsException;
use Drupal\myeventlane_analytics\Phase7\Exception\InvalidTimeWindowException;
use Drupal\myeventlane_analytics\Phase7\Exception\InvariantViolationException;
use Drupal\myeventlane_analytics\Phase7\Exception\MissingCurrencyException;
use Drupal\myeventlane_analytics\Phase7\Value\AnalyticsQuery;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Kernel tests for Refund Amount metric (Phase 7, order-item anchored).
 *
 * @group myeventlane_analytics
 */
final class RefundAmountKernelTest extends AnalyticsKernelTestBase {

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
    'path',
    'path_alias',
    'views',
    // Contrib.
    'address',
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

    $this->ensureCurrency('AUD', 'Australian Dollar', '036', '$', 2);
    $this->ensureEventContentType();
    $this->ensureOrderTypes();
    $this->ensureEventStoreField();
    $this->ensureOrderItemTargetEventField();
    $this->ensureBoostOrderItemTypeAndField();
    $this->ensureRefundLogTable();
  }

  /**
   * Aggregates refunds (partial + full) and allocates by store+event.
   */
  public function testRefundAmountAggregatesAndAllocates(): void {
    // Ensure UID 1 exists and is treated as admin override by scope resolver.
    $admin = $this->createUserAccount('admin');
    $this->assertSame(1, (int) $admin->id());

    $vendor_a = $this->createUserAccount('vendor_a');
    $vendor_b = $this->createUserAccount('vendor_b');

    $store_a = $this->createOnlineStore($vendor_a, 'Store A');
    $store_b = $this->createOnlineStore($vendor_b, 'Store B');

    $event_a = $this->createEventForStore('Event A', (int) $store_a->id());
    $event_b = $this->createEventForStore('Event B', (int) $store_b->id());

    $range_start = 100;
    $range_end = 200;

    // One completed order with ticket items for both events.
    $order = $this->createCompletedOrder((int) $store_a->id(), 150);
    $this->createOrderItem($order, 'default', new Price('10.00', 'AUD'), 1, (int) $event_a->id());
    $this->createOrderItem($order, 'default', new Price('20.00', 'AUD'), 1, (int) $event_b->id());

    // Two refunds against same (order,event) (multiple refunds case).
    $this->insertRefundLog((int) $order->id(), (int) $event_a->id(), (int) $vendor_a->id(), 500, 'aud', 'completed');
    $this->insertRefundLog((int) $order->id(), (int) $event_a->id(), (int) $vendor_a->id(), 250, 'aud', 'completed');
    // Refund for event B.
    $this->insertRefundLog((int) $order->id(), (int) $event_b->id(), (int) $vendor_b->id(), 700, 'aud', 'completed');

    $service = $this->createQueryService();
    $this->switchToUser($admin);

    $query = $this->buildQuery(
      scope: AnalyticsQuery::SCOPE_ADMIN,
      store_ids: [(int) $store_a->id(), (int) $store_b->id()],
      start_ts: $range_start,
      end_ts: $range_end,
      currency: 'AUD',
    );

    $rows = $service->getRefundAmount($query);
    $map = $this->indexRowsByStoreEventCurrency($rows);

    $this->assertSame(750, $map[(int) $store_a->id()][(int) $event_a->id()]['AUD'] ?? 0);
    $this->assertSame(700, $map[(int) $store_b->id()][(int) $event_b->id()]['AUD'] ?? 0);
    $this->assertCount(2, $rows);
  }

  /**
   * Refunds are excluded when they map only to excluded items (boost / zero-price).
   */
  public function testRefundsExcludedForBoostAndZeroPriceItems(): void {
    $admin = $this->createUserAccount('admin');
    $this->assertSame(1, (int) $admin->id());

    $vendor = $this->createUserAccount('vendor');
    $store = $this->createOnlineStore($vendor, 'Store');

    $event_boost = $this->createEventForStore('Boost Event', (int) $store->id());
    $event_free = $this->createEventForStore('Free Event', (int) $store->id());

    $order = $this->createCompletedOrder((int) $store->id(), 150);
    // Only a boost item for event_boost (excluded).
    $this->createOrderItem($order, 'boost', new Price('5.00', 'AUD'), 1, (int) $event_boost->id());
    // Only a zero-priced item for event_free (excluded).
    $this->createOrderItem($order, 'default', new Price('0.00', 'AUD'), 1, (int) $event_free->id());

    // Refund rows exist and are linked to an order item, but must be excluded.
    $this->insertRefundLog((int) $order->id(), (int) $event_boost->id(), (int) $vendor->id(), 100, 'aud', 'completed');
    $this->insertRefundLog((int) $order->id(), (int) $event_free->id(), (int) $vendor->id(), 200, 'aud', 'completed');

    $service = $this->createQueryService();
    $this->switchToUser($admin);

    $query = $this->buildQuery(
      scope: AnalyticsQuery::SCOPE_ADMIN,
      store_ids: [(int) $store->id()],
      start_ts: 100,
      end_ts: 200,
      currency: 'AUD',
    );

    $rows = $service->getRefundAmount($query);
    $this->assertSame([], $rows);
  }

  /**
   * Currency mismatch in-scope must fail-closed.
   */
  public function testCurrencyMismatchThrows(): void {
    $admin = $this->createUserAccount('admin');
    $this->assertSame(1, (int) $admin->id());

    $vendor = $this->createUserAccount('vendor');
    $store = $this->createOnlineStore($vendor, 'Store');
    $event = $this->createEventForStore('Event', (int) $store->id());

    $order = $this->createCompletedOrder((int) $store->id(), 150);
    $this->createOrderItem($order, 'default', new Price('10.00', 'AUD'), 1, (int) $event->id());

    // Refund currency mismatches query currency.
    $this->insertRefundLog((int) $order->id(), (int) $event->id(), (int) $vendor->id(), 100, 'usd', 'completed');

    $service = $this->createQueryService();
    $this->switchToUser($admin);

    $query = $this->buildQuery(
      scope: AnalyticsQuery::SCOPE_ADMIN,
      store_ids: [(int) $store->id()],
      start_ts: 100,
      end_ts: 200,
      currency: 'AUD',
    );

    $this->expectException(InvariantViolationException::class);
    $service->getRefundAmount($query);
  }

  /**
   * Refund rows that cannot be linked to any order item must fail-closed.
   */
  public function testUnlinkedRefundThrows(): void {
    $admin = $this->createUserAccount('admin');
    $this->assertSame(1, (int) $admin->id());

    $vendor = $this->createUserAccount('vendor');
    $store = $this->createOnlineStore($vendor, 'Store');

    $event_a = $this->createEventForStore('Event A', (int) $store->id());
    $event_b = $this->createEventForStore('Event B', (int) $store->id());

    $order = $this->createCompletedOrder((int) $store->id(), 150);
    // Only event A has an order item.
    $this->createOrderItem($order, 'default', new Price('10.00', 'AUD'), 1, (int) $event_a->id());

    // Refund log claims a refund for event B but there is no order item linkage.
    $this->insertRefundLog((int) $order->id(), (int) $event_b->id(), (int) $vendor->id(), 100, 'aud', 'completed');

    $service = $this->createQueryService();
    $this->switchToUser($admin);

    $query = $this->buildQuery(
      scope: AnalyticsQuery::SCOPE_ADMIN,
      store_ids: [(int) $store->id()],
      start_ts: 100,
      end_ts: 200,
      currency: 'AUD',
    );

    $this->expectException(InvariantViolationException::class);
    $service->getRefundAmount($query);
  }

  /**
   * Vendor attempting to query another store's refunds must throw.
   */
  public function testVendorCannotReadOtherStoreRefunds(): void {
    $admin = $this->createUserAccount('admin');
    $this->assertSame(1, (int) $admin->id());

    $vendor = $this->createUserAccount('vendor');
    $other = $this->createUserAccount('other');
    $store_vendor = $this->createOnlineStore($vendor, 'Vendor Store');
    $store_other = $this->createOnlineStore($other, 'Other Store');

    $service = $this->createQueryService();
    $this->switchToUser($vendor);

    $query = $this->buildQuery(
      scope: AnalyticsQuery::SCOPE_ADMIN,
      store_ids: [(int) $store_other->id()],
      start_ts: 1,
      end_ts: 2,
      currency: 'AUD',
    );

    $this->expectException(AccessDeniedAnalyticsException::class);
    $service->getRefundAmount($query);
  }

  /**
   * Missing currency must throw for money metrics.
   */
  public function testMissingCurrencyThrows(): void {
    $admin = $this->createUserAccount('admin');
    $this->assertSame(1, (int) $admin->id());
    $this->switchToUser($admin);

    $store = $this->createOnlineStore($admin, 'Admin Store');

    $service = $this->createQueryService();
    $query = $this->buildQuery(
      scope: AnalyticsQuery::SCOPE_ADMIN,
      store_ids: [(int) $store->id()],
      start_ts: 1,
      end_ts: 2,
      currency: NULL,
    );

    $this->expectException(MissingCurrencyException::class);
    $service->getRefundAmount($query);
  }

  /**
   * Invalid window must throw InvalidTimeWindowException.
   */
  public function testInvalidWindowThrows(): void {
    $admin = $this->createUserAccount('admin');
    $this->assertSame(1, (int) $admin->id());
    $this->switchToUser($admin);

    $store = $this->createOnlineStore($admin, 'Admin Store');

    $service = $this->createQueryService();
    $query = $this->buildQuery(
      scope: AnalyticsQuery::SCOPE_ADMIN,
      store_ids: [(int) $store->id()],
      start_ts: 2,
      end_ts: 1,
      currency: 'AUD',
    );

    $this->expectException(InvalidTimeWindowException::class);
    $service->getRefundAmount($query);
  }

  /**
   * No refunds in window returns an empty row set.
   */
  public function testNoRefundsReturnsEmptyArray(): void {
    $admin = $this->createUserAccount('admin');
    $this->assertSame(1, (int) $admin->id());

    $vendor = $this->createUserAccount('vendor');
    $store = $this->createOnlineStore($vendor, 'Store');
    $event = $this->createEventForStore('Event', (int) $store->id());

    $order = $this->createCompletedOrder((int) $store->id(), 150);
    $this->createOrderItem($order, 'default', new Price('10.00', 'AUD'), 1, (int) $event->id());

    $service = $this->createQueryService();
    $this->switchToUser($admin);

    $query = $this->buildQuery(
      scope: AnalyticsQuery::SCOPE_ADMIN,
      store_ids: [(int) $store->id()],
      start_ts: 100,
      end_ts: 200,
      currency: 'AUD',
    );

    $rows = $service->getRefundAmount($query);
    $this->assertSame([], $rows);
  }

  private function ensureCurrency(
    string $code,
    string $name,
    string $numeric_code,
    string $symbol,
    int $fraction_digits,
  ): void {
    if (Currency::load($code)) {
      return;
    }

    Currency::create([
      'currencyCode' => $code,
      'name' => $name,
      'numericCode' => $numeric_code,
      'symbol' => $symbol,
      'fractionDigits' => $fraction_digits,
    ])->save();
  }

  private function ensureEventContentType(): void {
    if (!NodeType::load('event')) {
      NodeType::create([
        'type' => 'event',
        'name' => 'Event',
      ])->save();
    }
  }

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

  private function ensureBoostOrderItemTypeAndField(): void {
    if (!OrderItemType::load('boost')) {
      OrderItemType::create([
        'id' => 'boost',
        'label' => 'Boost',
        'orderType' => 'default',
      ])->save();
    }

    if (!FieldConfig::loadByName('commerce_order_item', 'boost', 'field_target_event')) {
      FieldConfig::create([
        'field_name' => 'field_target_event',
        'entity_type' => 'commerce_order_item',
        'bundle' => 'boost',
        'label' => 'Target event',
        'required' => FALSE,
      ])->save();
    }
  }

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

  private function ensureRefundLogTable(): void {
    $schema = $this->container->get('database')->schema();
    if ($schema->tableExists('myeventlane_refund_log')) {
      return;
    }

    try {
      $schema->createTable('myeventlane_refund_log', [
        'description' => 'Test fixture table for refund log (Phase 7 kernel tests).',
        'fields' => [
          'id' => ['type' => 'serial', 'not null' => TRUE],
          'order_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
          'event_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
          'vendor_uid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
          'refund_type' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE],
          'refund_scope' => ['type' => 'varchar', 'length' => 64, 'not null' => TRUE],
          'amount_cents' => ['type' => 'int', 'not null' => TRUE],
          'currency' => ['type' => 'varchar', 'length' => 3, 'not null' => TRUE],
          'donation_refunded' => ['type' => 'int', 'size' => 'tiny', 'not null' => TRUE, 'default' => 0],
          'stripe_refund_id' => ['type' => 'varchar', 'length' => 255, 'not null' => FALSE],
          'status' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE],
          'reason' => ['type' => 'text', 'not null' => FALSE],
          'created' => ['type' => 'int', 'not null' => TRUE],
          'completed' => ['type' => 'int', 'not null' => FALSE],
          'error_message' => ['type' => 'text', 'not null' => FALSE],
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
    catch (SchemaObjectExistsException) {
      // Another test may have created it.
    }
  }

  private function insertRefundLog(
    int $order_id,
    int $event_id,
    int $vendor_uid,
    int $amount_cents,
    string $currency,
    string $status,
  ): void {
    $this->container->get('database')->insert('myeventlane_refund_log')->fields([
      'order_id' => $order_id,
      'event_id' => $event_id,
      'vendor_uid' => $vendor_uid,
      'refund_type' => 'partial',
      'refund_scope' => 'tickets_only',
      'amount_cents' => $amount_cents,
      'currency' => $currency,
      'donation_refunded' => 0,
      'stripe_refund_id' => NULL,
      'status' => $status,
      'reason' => NULL,
      'created' => 160,
      'completed' => 160,
      'error_message' => NULL,
    ])->execute();
  }

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

  private function createCompletedOrder(int $store_id, int $placed): Order {
    $order = Order::create([
      'type' => 'default',
      'store_id' => $store_id,
      'state' => 'completed',
      'uid' => 0,
      'placed' => $placed,
    ]);
    $order->save();
    return $order;
  }

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
   * Indexes MoneyByStoreEventCurrencyRow results into [store][event][currency].
   *
   * @param array $rows
   *   Rows from AnalyticsQueryService::getRefundAmount().
   *
   * @return array<int, array<int, array<string, int>>>
   *   Map of amount_cents.
   */
  private function indexRowsByStoreEventCurrency(array $rows): array {
    $map = [];
    foreach ($rows as $row) {
      $store_id = (int) $row->store_id;
      $event_id = (int) $row->event_id;
      $currency = (string) $row->currency;
      $map[$store_id][$event_id][$currency] = (int) $row->amount_cents;
    }
    return $map;
  }

}

