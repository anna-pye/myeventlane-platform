<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_price\Entity\Currency;
use Drupal\commerce_price\Price;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\SchemaObjectExistsException;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_analytics\Service\OrderItemClassifier;
use Drupal\myeventlane_core\PlatformFeeDefaults;
use Drupal\myeventlane_vendor\Service\TicketSalesService;
use Drupal\myeventlane_vendor\Service\UserVendorMembershipQuery;
use Drupal\myeventlane_vendor\Service\VendorSubscriptionService;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies event overview sales summary refund attribution.
 *
 * @group myeventlane_vendor
 */
#[RunTestsInSeparateProcesses]
final class EventOverviewSalesSummaryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
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
   * The service under test.
   */
  private TicketSalesService $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installConfig(['commerce_order']);

    $this->ensureCurrency('AUD', 'Australian Dollar', '036', '$', 2);
    $this->ensureEventContentType();
    $this->ensureOrderTypes();
    $this->ensureOrderItemTargetEventField();
    $this->ensureRefundLogTable();

    $coreSettings = $this->createMock(ImmutableConfig::class);
    $coreSettings->method('get')
      ->willReturnCallback(static function (string $key, $default = NULL) {
        return $key === 'platform_fee_percent' ? PlatformFeeDefaults::DEFAULT_PLATFORM_FEE_PERCENT : $default;
      });

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('myeventlane_core.settings')
      ->willReturn($coreSettings);

    $this->service = new TicketSalesService(
      $this->container->get('entity_type.manager'),
      new OrderItemClassifier(),
      $this->container->get('database'),
      $this->container->get('logger.factory'),
      $this->createMock(VendorSubscriptionService::class),
      $configFactory,
      new UserVendorMembershipQuery(
        $this->container->get('entity_type.manager'),
        $this->container->get('entity_field.manager'),
      ),
    );
  }

  /**
   * Sales summary subtracts attributed ticket refunds from net sales.
   */
  public function testRefundAttributionAndNetSalesForEventOverview(): void {
    $vendor = User::create([
      'name' => 'vendor_event_overview',
      'mail' => 'vendor-event-overview@example.com',
      'status' => 1,
    ]);
    $vendor->save();

    $event = Node::create([
      'type' => 'event',
      'title' => 'Refund summary event',
      'status' => 1,
      'uid' => $vendor->id(),
    ]);
    $event->save();

    $order = Order::create([
      'type' => 'default',
      'state' => 'completed',
      'uid' => 0,
      'placed' => 1700000000,
    ]);
    $order->save();

    $this->createOrderItem($order, new Price('120.00', 'AUD'), 1, (int) $event->id());
    $this->createOrderItem($order, new Price('80.00', 'AUD'), 1, (int) $event->id());

    // Should not reduce ticket sales.
    $this->insertRefundLog((int) $order->id(), (int) $event->id(), (int) $vendor->id(), 5000, 'donation_only', 1);
    // Should reduce ticket sales fully.
    $this->insertRefundLog((int) $order->id(), (int) $event->id(), (int) $vendor->id(), 3000, 'tickets_only', 0);
    // Should be capped by remaining ticket subtotal for this order/event.
    $this->insertRefundLog((int) $order->id(), (int) $event->id(), (int) $vendor->id(), 25000, 'tickets_and_donation', 1);

    $summary = $this->service->getSalesSummary($event);

    $this->assertSame(20000, (int) ($summary['gross_cents'] ?? -1));
    $this->assertSame(20000, (int) ($summary['refunded_ticket_cents'] ?? -1));
    $this->assertSame(0, (int) ($summary['net_cents'] ?? -1));
  }

  /**
   * Ensures test currency exists.
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
   * Ensures the event content type exists.
   */
  private function ensureEventContentType(): void {
    if (NodeType::load('event')) {
      return;
    }
    NodeType::create([
      'type' => 'event',
      'name' => 'Event',
    ])->save();
  }

  /**
   * Ensures commerce order and order item types exist.
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
   * Ensures field_target_event exists on default order items.
   */
  private function ensureOrderItemTargetEventField(): void {
    if (!FieldStorageConfig::loadByName('commerce_order_item', 'field_target_event')) {
      FieldStorageConfig::create([
        'field_name' => 'field_target_event',
        'entity_type' => 'commerce_order_item',
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'node'],
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
   * Ensures test fixture table for refund logs exists.
   */
  private function ensureRefundLogTable(): void {
    $schema = $this->container->get('database')->schema();
    if ($schema->tableExists('myeventlane_refund_log')) {
      return;
    }

    try {
      $schema->createTable('myeventlane_refund_log', [
        'description' => 'Test fixture table for refund log.',
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
      ]);
    }
    catch (SchemaObjectExistsException) {
      // Another test run may have created the table.
    }
  }

  /**
   * Creates an order item linked to the event.
   */
  private function createOrderItem(Order $order, Price $unitPrice, int $quantity, int $eventId): void {
    $item = OrderItem::create([
      'type' => 'default',
      'order_id' => $order->id(),
      'unit_price' => $unitPrice,
      'quantity' => $quantity,
      'field_target_event' => ['target_id' => $eventId],
    ]);
    $item->save();
    $order->addItem($item);
    $order->save();
  }

  /**
   * Inserts a completed refund row.
   */
  private function insertRefundLog(
    int $orderId,
    int $eventId,
    int $vendorId,
    int $amountCents,
    string $refundScope,
    int $donationRefunded,
  ): void {
    $this->container->get('database')->insert('myeventlane_refund_log')->fields([
      'order_id' => $orderId,
      'event_id' => $eventId,
      'vendor_uid' => $vendorId,
      'refund_type' => 'partial',
      'refund_scope' => $refundScope,
      'amount_cents' => $amountCents,
      'currency' => 'aud',
      'donation_refunded' => $donationRefunded,
      'status' => 'completed',
      'created' => 1700000001,
      'completed' => 1700000002,
    ])->execute();
  }

}

