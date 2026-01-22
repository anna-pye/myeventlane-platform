<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_checkout_paragraph\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_store\Entity\Store;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\user\Entity\User;

/**
 * Kernel tests for attendee paragraph access control.
 *
 * @group myeventlane_checkout_paragraph
 */
final class AttendeeParagraphAccessTest extends KernelTestBase {

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
    'state_machine',
    'paragraphs',
    'entity_reference_revisions',
    'myeventlane_core',
    'myeventlane_checkout_paragraph',
    'myeventlane_checkout_flow',
  ];

  /**
   * The access control handler.
   *
   * @var \Drupal\Core\Entity\EntityAccessControlHandlerInterface
   */
  private $accessHandler;

  /**
   * A test store.
   *
   * @var \Drupal\commerce_store\Entity\StoreInterface
   */
  private $store;

  /**
   * A customer user.
   *
   * @var \Drupal\user\UserInterface
   */
  private $customerUser;

  /**
   * A vendor user.
   *
   * @var \Drupal\user\UserInterface
   */
  private $vendorUser;

  /**
   * Another user (not customer, not vendor).
   *
   * @var \Drupal\user\UserInterface
   */
  private $otherUser;

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
    $this->installEntitySchema('paragraph');
    $this->installConfig(['commerce_product', 'commerce_store', 'commerce_order']);

    // Create event content type.
    if (!NodeType::load('event')) {
      NodeType::create([
        'type' => 'event',
        'name' => 'Event',
      ])->save();
    }

    // Create order item type.
    if (!OrderItemType::load('default')) {
      OrderItemType::create([
        'id' => 'default',
        'label' => 'Default',
        'purchasableEntityType' => 'commerce_product_variation',
        'orderType' => 'default',
      ])->save();
    }

    // Create test users.
    $this->customerUser = User::create([
      'name' => 'customer',
      'mail' => 'customer@example.com',
      'status' => 1,
    ]);
    $this->customerUser->save();

    $this->vendorUser = User::create([
      'name' => 'vendor',
      'mail' => 'vendor@example.com',
      'status' => 1,
    ]);
    $this->vendorUser->save();

    $this->otherUser = User::create([
      'name' => 'other',
      'mail' => 'other@example.com',
      'status' => 1,
    ]);
    $this->otherUser->save();

    // Create a default store.
    $this->store = Store::create([
      'type' => 'default',
      'name' => 'Test Store',
      'mail' => 'test@example.com',
      'default_currency' => 'AUD',
      'is_default' => TRUE,
      'uid' => $this->vendorUser->id(),
    ]);
    $this->store->save();

    $this->accessHandler = $this->container->get('entity_type.manager')
      ->getAccessControlHandler('paragraph');
  }

  /**
   * Tests that vendor cannot access attendee paragraphs for events they don't own.
   */
  public function testVendorCannotAccessOtherVendorEvents(): void {
    // Create event owned by other user.
    $event = Node::create([
      'type' => 'event',
      'title' => 'Other Vendor Event',
      'status' => 1,
      'uid' => $this->otherUser->id(),
    ]);
    $event->save();

    // Create order with attendee paragraph.
    $order = $this->createOrderWithAttendee($event, $this->customerUser);
    $paragraph = $this->getAttendeeParagraph($order);

    // Vendor user should NOT have access (event not owned by vendor).
    $access = $this->accessHandler->access($paragraph, 'view', $this->vendorUser);
    $this->assertFalse($access, 'Vendor should not have access to attendee paragraphs for events they do not own.');
  }

  /**
   * Tests that customer cannot access attendee paragraphs from other users' orders.
   */
  public function testCustomerCannotAccessOtherCustomerOrders(): void {
    // Create event.
    $event = Node::create([
      'type' => 'event',
      'title' => 'Test Event',
      'status' => 1,
    ]);
    $event->save();

    // Create order owned by customer user.
    $order = $this->createOrderWithAttendee($event, $this->customerUser);
    $paragraph = $this->getAttendeeParagraph($order);

    // Other user should NOT have access.
    $access = $this->accessHandler->access($paragraph, 'view', $this->otherUser);
    $this->assertFalse($access, 'Other user should not have access to attendee paragraphs from orders they do not own.');
  }

  /**
   * Tests that attendee paragraph cannot be edited after order placement.
   */
  public function testAttendeeParagraphImmutableAfterOrderPlacement(): void {
    // Create event.
    $event = Node::create([
      'type' => 'event',
      'title' => 'Test Event',
      'status' => 1,
    ]);
    $event->save();

    // Create order in draft state.
    $order = $this->createOrderWithAttendee($event, $this->customerUser);
    $paragraph = $this->getAttendeeParagraph($order);

    // Customer should be able to update before order is placed.
    $access = $this->accessHandler->access($paragraph, 'update', $this->customerUser);
    $this->assertTrue($access, 'Customer should be able to update attendee paragraph before order is placed.');

    // Place the order.
    $order->getState()->applyTransitionById('place');
    $order->save();

    // Customer should NOT be able to update after order is placed.
    $access = $this->accessHandler->access($paragraph, 'update', $this->customerUser);
    $this->assertFalse($access, 'Customer should not be able to update attendee paragraph after order is placed.');

    // Customer should NOT be able to delete after order is placed.
    $access = $this->accessHandler->access($paragraph, 'delete', $this->customerUser);
    $this->assertFalse($access, 'Customer should not be able to delete attendee paragraph after order is placed.');
  }

  /**
   * Tests that customer can view their own attendee paragraphs.
   */
  public function testCustomerCanViewOwnAttendeeParagraphs(): void {
    // Create event.
    $event = Node::create([
      'type' => 'event',
      'title' => 'Test Event',
      'status' => 1,
    ]);
    $event->save();

    // Create order owned by customer user.
    $order = $this->createOrderWithAttendee($event, $this->customerUser);
    $paragraph = $this->getAttendeeParagraph($order);

    // Customer should have view access.
    $access = $this->accessHandler->access($paragraph, 'view', $this->customerUser);
    $this->assertTrue($access, 'Customer should have view access to their own attendee paragraphs.');
  }

  /**
   * Creates an order with an attendee paragraph.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param \Drupal\user\UserInterface $customer
   *   The customer user.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface
   *   The created order.
   */
  private function createOrderWithAttendee($event, $customer) {
    // Create product and variation.
    $product = Product::create([
      'type' => 'default',
      'title' => 'Test Tickets',
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

    // Create order.
    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'state' => 'draft',
      'uid' => $customer->id(),
    ]);
    $order->setCustomerId($customer->id());
    $order->save();

    // Create order item.
    $order_item = OrderItem::create([
      'type' => 'default',
      'purchased_entity' => $variation,
      'quantity' => 1,
      'unit_price' => new Price('10.00', 'AUD'),
      'order_id' => $order->id(),
    ]);

    // Set field_target_event if it exists.
    if ($order_item->hasField('field_target_event')) {
      $order_item->set('field_target_event', ['target_id' => $event->id()]);
    }

    // Create attendee paragraph.
    $paragraph = Paragraph::create([
      'type' => 'attendee_answer',
      'field_first_name' => 'John',
      'field_last_name' => 'Doe',
      'field_email' => 'john@example.com',
    ]);
    $paragraph->save();

    // Attach paragraph to order item.
    if ($order_item->hasField('field_ticket_holder')) {
      $order_item->set('field_ticket_holder', [$paragraph]);
    }
    $order_item->save();
    $order->addItem($order_item);
    $order->save();

    return $order;
  }

  /**
   * Gets the attendee paragraph from an order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Drupal\paragraphs\ParagraphInterface|null
   *   The attendee paragraph, or NULL if not found.
   */
  private function getAttendeeParagraph($order) {
    foreach ($order->getItems() as $item) {
      if ($item->hasField('field_ticket_holder') && !$item->get('field_ticket_holder')->isEmpty()) {
        $paragraphs = $item->get('field_ticket_holder')->referencedEntities();
        if (!empty($paragraphs)) {
          return reset($paragraphs);
        }
      }
    }
    return NULL;
  }

}
