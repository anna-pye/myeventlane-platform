<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_capacity\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_store\Entity\Store;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_capacity\Exception\CapacityExceededException;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\state_machine\Event\WorkflowTransitionEvent;

/**
 * Kernel tests for Commerce capacity enforcement.
 *
 * @group myeventlane_capacity
 */
final class CapacityEnforcementTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'commerce',
    'commerce_price',
    'commerce_product',
    'commerce_store',
    'commerce_order',
    'commerce_cart',
    'myeventlane_core',
    'myeventlane_capacity',
  ];

  /**
   * The capacity service.
   *
   * @var \Drupal\myeventlane_capacity\Service\EventCapacityService
   */
  private $capacityService;

  /**
   * The order inspector service.
   *
   * @var \Drupal\myeventlane_capacity\Service\CapacityOrderInspector
   */
  private $orderInspector;

  /**
   * The enforcement subscriber.
   *
   * @var \Drupal\myeventlane_capacity\EventSubscriber\CommerceCapacityEnforcementSubscriber
   */
  private $enforcementSubscriber;

  /**
   * A test store.
   *
   * @var \Drupal\commerce_store\Entity\StoreInterface
   */
  private $store;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('commerce_store');
    $this->installEntitySchema('commerce_product');
    $this->installEntitySchema('commerce_product_variation');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installConfig(['commerce_product', 'commerce_store', 'commerce_order']);

    // Create event content type.
    if (!NodeType::load('event')) {
      NodeType::create([
        'type' => 'event',
        'name' => 'Event',
      ])->save();
    }

    // Create order item type if needed.
    if (!OrderItemType::load('default')) {
      OrderItemType::create([
        'id' => 'default',
        'label' => 'Default',
        'purchasableEntityType' => 'commerce_product_variation',
        'orderType' => 'default',
      ])->save();
    }

    // Create a default store.
    $this->store = Store::create([
      'type' => 'default',
      'name' => 'Test Store',
      'mail' => 'test@example.com',
      'default_currency' => 'AUD',
      'is_default' => TRUE,
    ]);
    $this->store->save();

    $this->capacityService = $this->container->get('myeventlane_capacity.service');
    $this->orderInspector = $this->container->get('myeventlane_capacity.order_inspector');
    $this->enforcementSubscriber = $this->container->get('myeventlane_capacity.commerce_enforcement_subscriber');
  }

  /**
   * Tests that overselling is blocked at order placement.
   */
  public function testOversellBlockedAtOrderPlacement(): void {
    // Create an event with capacity = 1.
    $event = Node::create([
      'type' => 'event',
      'title' => 'Test Event - Capacity 1',
      'status' => 1,
      'field_event_capacity_total' => 1,
      'field_event_type' => 'paid',
    ]);
    $event->save();

    // Create a product and variation.
    $product = Product::create([
      'type' => 'default',
      'title' => 'Test Event Tickets',
      'stores' => [$this->store],
    ]);
    $product->save();

    $variation = ProductVariation::create([
      'type' => 'default',
      'title' => 'General Admission',
      'product_id' => $product->id(),
      'price' => new Price('10.00', 'AUD'),
      'status' => 1,
    ]);
    $variation->save();

    // Create an order with 2 tickets (exceeds capacity of 1).
    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'state' => 'draft',
      'uid' => 1,
    ]);
    $order->save();

    $order_item = OrderItem::create([
      'type' => 'default',
      'purchased_entity' => $variation,
      'quantity' => 2,
      'unit_price' => new Price('10.00', 'AUD'),
      'order_id' => $order->id(),
    ]);

    // Set field_target_event if the field exists.
    if ($order_item->hasField('field_target_event')) {
      $order_item->set('field_target_event', ['target_id' => $event->id()]);
    }
    $order_item->save();
    $order->addItem($order_item);
    $order->save();

    // Verify capacity service correctly identifies oversell.
    try {
      $this->capacityService->assertCanBook($event, 2);
      $this->fail('Expected CapacityExceededException was not thrown.');
    }
    catch (CapacityExceededException $e) {
      $this->assertInstanceOf(CapacityExceededException::class, $e);
    }

    // Simulate order placement pre-transition event.
    $workflow = $order->getState()->getWorkflow();
    $transition = $workflow->getTransition('place');
    $transition_event = new WorkflowTransitionEvent($transition, $workflow, $order, 'state');

    // This should throw CapacityExceededException.
    try {
      $this->enforcementSubscriber->onOrderPlacePreTransition($transition_event);
      $this->fail('Expected CapacityExceededException was not thrown at order placement.');
    }
    catch (CapacityExceededException $e) {
      $this->assertInstanceOf(CapacityExceededException::class, $e);
      $this->assertStringContainsString('remaining', $e->getMessage() ?? '', 'Exception message should mention remaining tickets.');
    }
  }

  /**
   * Tests that capacity enforcement allows valid bookings.
   */
  public function testValidBookingAllowed(): void {
    // Create an event with capacity = 10.
    $event = Node::create([
      'type' => 'event',
      'title' => 'Test Event - Capacity 10',
      'status' => 1,
      'field_event_capacity_total' => 10,
      'field_event_type' => 'paid',
    ]);
    $event->save();

    // Create a product and variation.
    $product = Product::create([
      'type' => 'default',
      'title' => 'Test Event Tickets',
      'stores' => [$this->store],
    ]);
    $product->save();

    $variation = ProductVariation::create([
      'type' => 'default',
      'title' => 'General Admission',
      'product_id' => $product->id(),
      'price' => new Price('10.00', 'AUD'),
      'status' => 1,
    ]);
    $variation->save();

    // Create an order with 5 tickets (within capacity).
    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'state' => 'draft',
      'uid' => 1,
    ]);
    $order->save();

    $order_item = OrderItem::create([
      'type' => 'default',
      'purchased_entity' => $variation,
      'quantity' => 5,
      'unit_price' => new Price('10.00', 'AUD'),
      'order_id' => $order->id(),
    ]);

    if ($order_item->hasField('field_target_event')) {
      $order_item->set('field_target_event', ['target_id' => $event->id()]);
    }
    $order_item->save();
    $order->addItem($order_item);
    $order->save();

    // Verify capacity service allows valid booking.
    try {
      $this->capacityService->assertCanBook($event, 5);
      $this->assertTrue(TRUE, 'Valid booking should not throw exception.');
    }
    catch (CapacityExceededException $e) {
      $this->fail('Valid booking should not throw CapacityExceededException.');
    }

    // Simulate order placement - should not throw.
    $workflow = $order->getState()->getWorkflow();
    $transition = $workflow->getTransition('place');
    $transition_event = new WorkflowTransitionEvent($transition, $workflow, $order, 'state');

    try {
      $this->enforcementSubscriber->onOrderPlacePreTransition($transition_event);
      $this->assertTrue(TRUE, 'Valid order placement should not throw exception.');
    }
    catch (CapacityExceededException $e) {
      $this->fail('Valid order placement should not throw CapacityExceededException.');
    }
  }

  /**
   * Tests that donation items are excluded from capacity checks.
   */
  public function testDonationItemsExcluded(): void {
    // Create an event with capacity = 1.
    $event = Node::create([
      'type' => 'event',
      'title' => 'Test Event - Capacity 1',
      'status' => 1,
      'field_event_capacity_total' => 1,
      'field_event_type' => 'paid',
    ]);
    $event->save();

    // Create an order with 1 ticket + 1 donation (donation should be excluded).
    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'state' => 'draft',
      'uid' => 1,
    ]);
    $order->save();

    // Verify order inspector excludes donations.
    $event_quantities = $this->orderInspector->extractEventQuantities($order);
    $this->assertEmpty($event_quantities, 'Order with no ticket items should have empty event quantities.');

    // Create a ticket order item.
    $product = Product::create([
      'type' => 'default',
      'title' => 'Test Event Tickets',
      'stores' => [$this->store],
    ]);
    $product->save();

    $variation = ProductVariation::create([
      'type' => 'default',
      'title' => 'General Admission',
      'product_id' => $product->id(),
      'price' => new Price('10.00', 'AUD'),
      'status' => 1,
    ]);
    $variation->save();

    $ticket_item = OrderItem::create([
      'type' => 'default',
      'purchased_entity' => $variation,
      'quantity' => 1,
      'unit_price' => new Price('10.00', 'AUD'),
      'order_id' => $order->id(),
    ]);
    if ($ticket_item->hasField('field_target_event')) {
      $ticket_item->set('field_target_event', ['target_id' => $event->id()]);
    }
    $ticket_item->save();
    $order->addItem($ticket_item);

    // Create a donation order item (if checkout_donation type exists).
    if (OrderItemType::load('checkout_donation')) {
      $donation_item = OrderItem::create([
        'type' => 'checkout_donation',
        'title' => 'Donation',
        'quantity' => 1,
        'unit_price' => new Price('5.00', 'AUD'),
        'order_id' => $order->id(),
      ]);
      $donation_item->save();
      $order->addItem($donation_item);
    }

    $order->save();

    // Verify only ticket item is counted.
    $event_quantities = $this->orderInspector->extractEventQuantities($order);
    $this->assertCount(1, $event_quantities, 'Order should have one event with quantity.');
    $this->assertEquals(1, $event_quantities[$event->id()], 'Only ticket item should be counted, not donation.');
  }

}
