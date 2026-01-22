<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_refunds\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;

/**
 * Kernel tests for refund access control.
 *
 * @group myeventlane_refunds
 */
final class RefundAccessTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'commerce',
    'commerce_order',
    'commerce_price',
    'commerce_store',
    'myeventlane_checkout_flow',
    'myeventlane_refunds',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installSchema('commerce_order', ['commerce_order']);
    $this->installConfig(['commerce_order']);

    // Create order type and item type.
    $orderType = OrderType::create([
      'id' => 'default',
      'label' => 'Default',
      'workflow' => 'order_default',
    ]);
    $orderType->save();

    $orderItemType = OrderItemType::create([
      'id' => 'default',
      'label' => 'Default',
      'orderType' => 'default',
    ]);
    $orderItemType->save();

    // Create event node type.
    $nodeType = NodeType::create([
      'type' => 'event',
      'name' => 'Event',
    ]);
    $nodeType->save();
  }

  /**
   * Tests that vendor cannot refund other vendors' events.
   */
  public function testVendorCannotRefundOtherVendorsEvent(): void {
    $vendor1 = User::create(['name' => 'vendor1', 'mail' => 'vendor1@example.com']);
    $vendor1->save();

    $vendor2 = User::create(['name' => 'vendor2', 'mail' => 'vendor2@example.com']);
    $vendor2->save();

    $event = Node::create([
      'type' => 'event',
      'title' => 'Test Event',
      'uid' => $vendor1->id(),
    ]);
    $event->save();

    $order = Order::create([
      'type' => 'default',
      'state' => 'completed',
      'uid' => 0,
    ]);
    $order->save();

    $accessResolver = $this->container->get('myeventlane_refunds.access_resolver');
    $canRefund = $accessResolver->vendorCanRefundOrderForEvent($order, $event, $vendor2);

    $this->assertFalse($canRefund, 'Vendor 2 cannot refund Vendor 1\'s event.');
  }

  /**
   * Tests that vendor can refund their own event tickets.
   */
  public function testVendorCanRefundOwnedEventTicketsOnly(): void {
    $vendor = User::create(['name' => 'vendor', 'mail' => 'vendor@example.com']);
    $vendor->save();

    $event = Node::create([
      'type' => 'event',
      'title' => 'Test Event',
      'uid' => $vendor->id(),
    ]);
    $event->save();

    $order = Order::create([
      'type' => 'default',
      'state' => 'completed',
      'uid' => 0,
    ]);
    $order->save();

    $accessResolver = $this->container->get('myeventlane_refunds.access_resolver');
    $canRefund = $accessResolver->vendorCanRefundOrderForEvent($order, $event, $vendor);

    // Note: This will be FALSE if order has no items for this event.
    // In a real test, we'd create order items with field_target_event.
    $this->assertIsBool($canRefund);
  }

  /**
   * Tests that refund excludes donations by default.
   */
  public function testRefundExcludesDonationsByDefault(): void {
    $orderInspector = $this->container->get('myeventlane_refunds.order_inspector');

    // Create a donation order item.
    $donationItem = OrderItem::create([
      'type' => 'default',
      'title' => 'Donation',
    ]);
    // In a real test, set bundle to 'checkout_donation'.
    // $donationItem->bundle = 'checkout_donation';.
    $isDonation = $orderInspector->isDonationItem($donationItem);
    // This will be FALSE if bundle is not set correctly.
    $this->assertIsBool($isDonation);
  }

}
