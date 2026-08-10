<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_admin_dashboard\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_price\Entity\Currency;
use Drupal\commerce_price\Price;
use Drupal\commerce_store\Entity\Store;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_analytics\Phase7\Guard\AnalyticsQueryGuard;
use Drupal\myeventlane_analytics\Phase7\Scope\AnalyticsScopeResolver;
use Drupal\myeventlane_analytics\Phase7\Service\AnalyticsQueryService;
use Drupal\myeventlane_analytics\Phase7\Value\AnalyticsQuery;
use Drupal\myeventlane_analytics\Service\OrderItemClassifier;
use Drupal\myeventlane_pro\Service\ProRecoveryAnalyticsService;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel tests refund allocation and platform recovery analytics.
 *
 * @group myeventlane_admin_dashboard
 */
#[RunTestsInSeparateProcesses]
final class RefundAnalyticsAllocationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'node',
    'options',
    'path',
    'path_alias',
    'views',
    'address',
    'commerce',
    'commerce_price',
    'commerce_store',
    'commerce_number_pattern',
    'commerce_order',
    'entity_reference_revisions',
    'profile',
    'state_machine',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->requireAnalyticsAndRecoveryClasses();

    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('commerce_store');
    $this->installEntitySchema('node');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installConfig(['commerce_order']);

    $this->ensureCurrency('AUD', 'Australian Dollar', '036', '$', 2);
    $this->ensureStoreType();
    $this->ensureEventContentType();
    $this->ensureOrderTypes();
    $this->ensureEventStoreField();
    $this->ensureOrderItemTargetEventField();
    $this->ensureDonationOrderItemType();
    $this->ensureRefundLogTable();
    $this->ensureRecoveryAttributionTable();
  }

  /**
   * Tests refund allocation and net revenue behavior.
   */
  public function testRefundAllocationAndNetRevenue(): void {
    $admin = $this->createUser('admin');
    $this->assertSame(1, (int) $admin->id());
    $this->container->get('current_user')->setAccount($admin);

    $store = $this->createStore($admin, 'Vendor Store');
    $eventA = $this->createEventForStore('Event A', (int) $store->id());
    $eventB = $this->createEventForStore('Event B', (int) $store->id());

    $orderA = $this->createCompletedOrder((int) $store->id(), 1000);
    $this->createOrderItem($orderA, 'default', new Price('20.00', 'AUD'), 1, (int) $eventA->id());
    $this->createOrderItem($orderA, 'checkout_donation', new Price('5.00', 'AUD'), 1, (int) $eventA->id());

    $orderB = $this->createCompletedOrder((int) $store->id(), 1000);
    $this->createOrderItem($orderB, 'default', new Price('10.00', 'AUD'), 1, (int) $eventB->id());

    $this->insertRefundLog((int) $orderA->id(), (int) $eventA->id(), (int) $admin->id(), 500, 'donation_only', 1);
    $this->insertRefundLog((int) $orderA->id(), (int) $eventA->id(), (int) $admin->id(), 1500, 'tickets_and_donation', 1);
    $this->insertRefundLog((int) $orderA->id(), (int) $eventA->id(), (int) $admin->id(), 400, 'tickets_only', 0);
    $this->insertRefundLog((int) $orderB->id(), (int) $eventB->id(), (int) $admin->id(), 300, 'donation_only', 1);

    $service = $this->createAnalyticsService();
    $query = new AnalyticsQuery(
      scope: AnalyticsQuery::SCOPE_ADMIN,
      store_ids: [(int) $store->id()],
      start_ts: 900,
      end_ts: 1100,
      currency: 'AUD',
    );

    $refundRows = $service->getRefundAmount($query);
    $refundMap = $this->indexRows($refundRows);
    $this->assertSame(1900, $refundMap[(int) $store->id()][(int) $eventA->id()]['AUD'] ?? 0);
    $this->assertSame(0, $refundMap[(int) $store->id()][(int) $eventB->id()]['AUD'] ?? 0);

    $netRows = $service->getNetRevenue($query);
    $netMap = $this->indexRows($netRows);
    $this->assertSame(100, $netMap[(int) $store->id()][(int) $eventA->id()]['AUD'] ?? 0);
    $this->assertSame(1000, $netMap[(int) $store->id()][(int) $eventB->id()]['AUD'] ?? 0);
  }

  /**
   * Tests platform recovery stats aggregation.
   */
  public function testPlatformRecoveryStats(): void {
    $time = $this->container->get('datetime.time');
    $now = $time->getRequestTime();
    $database = $this->container->get('database');

    $database->insert('myeventlane_pro_recovery_attribution')->fields([
      'store_id' => 1,
      'recovered' => 1,
      'order_total' => 50.00,
      'sent_at' => $now,
      'recovered_at' => $now,
      'created' => $now,
    ])->execute();
    $database->insert('myeventlane_pro_recovery_attribution')->fields([
      'store_id' => 1,
      'recovered' => 1,
      'order_total' => 75.00,
      'sent_at' => $now,
      'recovered_at' => $now,
      'created' => $now,
    ])->execute();
    $database->insert('myeventlane_pro_recovery_attribution')->fields([
      'store_id' => 1,
      'recovered' => 0,
      'order_total' => 20.00,
      'sent_at' => $now,
      'recovered_at' => 0,
      'created' => $now,
    ])->execute();

    $service = new ProRecoveryAnalyticsService(
      $database,
      $this->container->get('entity_type.manager'),
      $time,
      $this->container->get('logger.factory')->get('myeventlane_pro'),
    );

    $stats = $service->getPlatformRecoveryStats(30);
    $this->assertSame(3, (int) $stats['reminders_sent']);
    $this->assertSame(2, (int) $stats['recovered_orders']);
    $this->assertSame(125.0, (float) $stats['recovered_revenue']);
  }

  private function createAnalyticsService(): AnalyticsQueryService {
    return new AnalyticsQueryService(
      new AnalyticsScopeResolver(
        $this->container->get('current_user'),
        $this->container->get('entity_type.manager'),
      ),
      new AnalyticsQueryGuard(
        $this->container->get('logger.factory')->get('myeventlane_analytics'),
      ),
      new OrderItemClassifier(),
    );
  }

  private function createUser(string $name): User {
    $user = User::create([
      'name' => $name,
      'mail' => $name . '@example.test',
      'status' => 1,
    ]);
    $user->save();
    return $user;
  }

  private function createStore(User $owner, string $name): Store {
    $store = Store::create([
      'type' => 'online',
      'name' => $name,
      'mail' => 'store-' . strtolower(str_replace(' ', '-', $name)) . '@example.test',
      'default_currency' => 'AUD',
      'uid' => $owner->id(),
      'status' => 1,
      'is_default' => FALSE,
    ]);
    $store->save();
    return $store;
  }

  private function createEventForStore(string $title, int $storeId): Node {
    $event = Node::create([
      'type' => 'event',
      'title' => $title,
      'status' => 1,
      'field_event_store' => ['target_id' => $storeId],
    ]);
    $event->save();
    return $event;
  }

  private function createCompletedOrder(int $storeId, int $placed): Order {
    $order = Order::create([
      'type' => 'default',
      'store_id' => $storeId,
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
    Price $unitPrice,
    int $quantity,
    int $eventId,
  ): void {
    $item = OrderItem::create([
      'type' => $bundle,
      'order_id' => $order->id(),
      'unit_price' => $unitPrice,
      'quantity' => $quantity,
      'field_target_event' => ['target_id' => $eventId],
    ]);
    $item->save();
    $order->addItem($item);
    $order->save();
  }

  private function insertRefundLog(
    int $orderId,
    int $eventId,
    int $vendorUid,
    int $amountCents,
    string $refundScope,
    int $donationRefunded,
  ): void {
    $this->container->get('database')->insert('myeventlane_refund_log')->fields([
      'order_id' => $orderId,
      'event_id' => $eventId,
      'vendor_uid' => $vendorUid,
      'refund_type' => 'partial',
      'refund_scope' => $refundScope,
      'amount_cents' => $amountCents,
      'currency' => 'aud',
      'donation_refunded' => $donationRefunded,
      'stripe_refund_id' => NULL,
      'status' => 'completed',
      'reason' => NULL,
      'created' => 1000,
      'completed' => 1000,
      'error_message' => NULL,
    ])->execute();
  }

  private function indexRows(array $rows): array {
    $map = [];
    foreach ($rows as $row) {
      $map[(int) $row->store_id][(int) $row->event_id][(string) $row->currency] = (int) $row->amount_cents;
    }
    return $map;
  }

  private function ensureCurrency(
    string $code,
    string $name,
    string $numericCode,
    string $symbol,
    int $fractionDigits,
  ): void {
    if (Currency::load($code)) {
      return;
    }
    Currency::create([
      'currencyCode' => $code,
      'name' => $name,
      'numericCode' => $numericCode,
      'symbol' => $symbol,
      'fractionDigits' => $fractionDigits,
    ])->save();
  }

  private function ensureStoreType(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('commerce_store_type');
    if ($storage->load('online')) {
      return;
    }
    $storage->create([
      'id' => 'online',
      'label' => 'Online',
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

  private function ensureDonationOrderItemType(): void {
    if (!OrderItemType::load('checkout_donation')) {
      OrderItemType::create([
        'id' => 'checkout_donation',
        'label' => 'Checkout Donation',
        'orderType' => 'default',
      ])->save();
    }

    if (!FieldConfig::loadByName('commerce_order_item', 'checkout_donation', 'field_target_event')) {
      FieldConfig::create([
        'field_name' => 'field_target_event',
        'entity_type' => 'commerce_order_item',
        'bundle' => 'checkout_donation',
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
        'settings' => ['target_type' => 'commerce_store'],
      ])->save();
    }
    if (!FieldConfig::loadByName('node', 'event', 'field_event_store')) {
      FieldConfig::create([
        'field_name' => 'field_event_store',
        'entity_type' => 'node',
        'bundle' => 'event',
        'label' => 'Store',
      ])->save();
    }
  }

  private function ensureOrderItemTargetEventField(): void {
    if (!FieldStorageConfig::loadByName('commerce_order_item', 'field_target_event')) {
      FieldStorageConfig::create([
        'field_name' => 'field_target_event',
        'entity_type' => 'commerce_order_item',
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'node'],
      ])->save();
    }
    if (!FieldConfig::loadByName('commerce_order_item', 'default', 'field_target_event')) {
      FieldConfig::create([
        'field_name' => 'field_target_event',
        'entity_type' => 'commerce_order_item',
        'bundle' => 'default',
        'label' => 'Target event',
        'required' => FALSE,
      ])->save();
    }
  }

  private function ensureRefundLogTable(): void {
    $schema = $this->container->get('database')->schema();
    if ($schema->tableExists('myeventlane_refund_log')) {
      return;
    }

    $schema->createTable('myeventlane_refund_log', [
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

  private function ensureRecoveryAttributionTable(): void {
    $schema = $this->container->get('database')->schema();
    if ($schema->tableExists('myeventlane_pro_recovery_attribution')) {
      return;
    }

    $schema->createTable('myeventlane_pro_recovery_attribution', [
      'fields' => [
        'id' => ['type' => 'serial', 'not null' => TRUE],
        'store_id' => ['type' => 'int', 'not null' => TRUE],
        'recovered' => ['type' => 'int', 'size' => 'tiny', 'not null' => TRUE, 'default' => 0],
        'order_total' => ['type' => 'numeric', 'precision' => 14, 'scale' => 2, 'not null' => TRUE, 'default' => 0],
        'sent_at' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'recovered_at' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'created' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
      ],
      'primary key' => ['id'],
    ]);
  }

  private function requireAnalyticsAndRecoveryClasses(): void {
    $analyticsRoot = dirname(__DIR__, 4) . '/myeventlane_analytics';
    require_once $analyticsRoot . '/src/Service/OrderItemClassifier.php';
    require_once $analyticsRoot . '/src/Phase7/Value/AnalyticsQuery.php';
    require_once $analyticsRoot . '/src/Phase7/Value/MoneyByStoreEventCurrencyRow.php';
    require_once $analyticsRoot . '/src/Phase7/Exception/AnalyticsException.php';
    require_once $analyticsRoot . '/src/Phase7/Exception/AccessDeniedAnalyticsException.php';
    require_once $analyticsRoot . '/src/Phase7/Exception/InvalidScopeException.php';
    require_once $analyticsRoot . '/src/Phase7/Exception/InvalidTimeWindowException.php';
    require_once $analyticsRoot . '/src/Phase7/Exception/MissingCurrencyException.php';
    require_once $analyticsRoot . '/src/Phase7/Exception/InvariantViolationException.php';
    require_once $analyticsRoot . '/src/Phase7/Guard/AnalyticsQueryGuardInterface.php';
    require_once $analyticsRoot . '/src/Phase7/Guard/AnalyticsQueryGuard.php';
    require_once $analyticsRoot . '/src/Phase7/Scope/AnalyticsScopeResolverInterface.php';
    require_once $analyticsRoot . '/src/Phase7/Scope/AnalyticsScopeResolver.php';
    require_once $analyticsRoot . '/src/Phase7/Service/AnalyticsQueryServiceInterface.php';
    require_once $analyticsRoot . '/src/Phase7/Service/AnalyticsQueryService.php';

    $proRoot = dirname(__DIR__, 4) . '/myeventlane_pro';
    require_once $proRoot . '/src/Service/ProRecoveryAnalyticsService.php';
  }

}
