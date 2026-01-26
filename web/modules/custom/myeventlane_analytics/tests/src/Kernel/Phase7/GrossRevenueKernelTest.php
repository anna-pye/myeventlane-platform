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
use Drupal\myeventlane_analytics\Phase7\Exception\InvalidTimeWindowException;
use Drupal\myeventlane_analytics\Phase7\Exception\InvariantViolationException;
use Drupal\myeventlane_analytics\Phase7\Exception\MissingCurrencyException;
use Drupal\myeventlane_analytics\Phase7\Value\AnalyticsQuery;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Kernel tests for Gross Revenue metric (Phase 7, order-item anchored).
 *
 * Validates correctness and fail-closed behaviour for Gross Revenue without
 * implementing any other metric.
 *
 * @group myeventlane_analytics
 */
final class GrossRevenueKernelTest extends AnalyticsKernelTestBase {

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
    $this->ensureCurrency('USD', 'US Dollar', '840', '$', 2);
    $this->ensureEventContentType();
    $this->ensureOrderTypes();
    $this->ensureEventStoreField();
    $this->ensureOrderItemTargetEventField();
  }

  /**
   * Sums paid ticket items, excludes zero-priced, and allocates by store+event.
   */
  public function testGrossRevenueSumsAndAllocates(): void {
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

    // Single completed order with items for Event A and Event B (multi-event).
    // Allocation must be order-item -> event -> store (NOT whole-order).
    $order = $this->createCompletedOrder((int) $store_a->id(), 150);

    // Event A: 10.00 * qty 2 = 20.00 => 2000 cents.
    $this->createOrderItem($order, 'default', new Price('10.00', 'AUD'), 2, (int) $event_a->id());
    // Zero-priced item excluded.
    $this->createOrderItem($order, 'default', new Price('0.00', 'AUD'), 1, (int) $event_a->id());
    // Event B: 5.50 * qty 1 = 5.50 => 550 cents.
    $this->createOrderItem($order, 'default', new Price('5.50', 'AUD'), 1, (int) $event_b->id());
    // Event-less paid item excluded by design (prevents whole-order totals).
    $this->createOrderItem($order, 'default', new Price('99.00', 'AUD'), 1, NULL);

    $service = $this->createQueryService();
    $this->switchToUser($admin);

    $query = $this->buildQuery(
      scope: AnalyticsQuery::SCOPE_ADMIN,
      store_ids: [(int) $store_a->id(), (int) $store_b->id()],
      start_ts: $range_start,
      end_ts: $range_end,
      currency: 'AUD',
    );

    $rows = $service->getGrossRevenue($query);
    $map = $this->indexRowsByStoreEventCurrency($rows);

    $this->assertSame(2000, $map[(int) $store_a->id()][(int) $event_a->id()]['AUD'] ?? 0);
    $this->assertSame(550, $map[(int) $store_b->id()][(int) $event_b->id()]['AUD'] ?? 0);

    // Only two rows expected: one per store+event (currency fixed to AUD).
    $this->assertCount(2, $rows);
  }

  /**
   * Missing currency must throw for money metrics (fail-closed).
   */
  public function testMissingCurrencyThrows(): void {
    $admin = $this->createUserAccount('admin');
    $this->assertSame(1, (int) $admin->id(), 'Expected first created user to be UID 1.');
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
    $service->getGrossRevenue($query);
  }

  /**
   * Invalid window must throw InvalidTimeWindowException (fail-closed).
   */
  public function testInvalidWindowThrows(): void {
    $admin = $this->createUserAccount('admin');
    $this->assertSame(1, (int) $admin->id(), 'Expected first created user to be UID 1.');
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
    $service->getGrossRevenue($query);
  }

  /**
   * Currency mismatch must fail-closed (no partial results).
   */
  public function testCurrencyMismatchThrows(): void {
    $admin = $this->createUserAccount('admin');
    $this->assertSame(1, (int) $admin->id(), 'Expected first created user to be UID 1.');

    $vendor = $this->createUserAccount('vendor');
    $store = $this->createOnlineStore($vendor, 'Store');
    $event = $this->createEventForStore('Event', (int) $store->id());

    // Completed order in range with a USD item (mismatch vs AUD query).
    $order = $this->createCompletedOrder((int) $store->id(), 150);
    $this->createOrderItem($order, 'default', new Price('10.00', 'USD'), 1, (int) $event->id());

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
    $service->getGrossRevenue($query);
  }

  /**
   * Vendor admin-scope override attempts must throw (fail-closed).
   */
  public function testVendorCannotOverrideStoreScope(): void {
    $admin = $this->createUserAccount('admin');
    $this->assertSame(1, (int) $admin->id(), 'Expected first created user to be UID 1.');

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
    $service->getGrossRevenue($query);
  }

  /**
   * Ensures a Commerce currency exists.
   */
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
   * Creates a completed order.
   */
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
   * Indexes MoneyByStoreEventCurrencyRow results into [store][event][currency].
   *
   * @param array $rows
   *   Rows from AnalyticsQueryService::getGrossRevenue().
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

