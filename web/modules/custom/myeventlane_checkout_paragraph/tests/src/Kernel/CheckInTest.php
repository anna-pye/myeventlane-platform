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
 * Kernel tests for check-in functionality.
 *
 * @group myeventlane_checkout_paragraph
 */
final class CheckInTest extends KernelTestBase {

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
   * The check-in token service.
   *
   * @var \Drupal\myeventlane_checkout_paragraph\Service\CheckInTokenService
   */
  private $tokenService;

  /**
   * A vendor user.
   *
   * @var \Drupal\user\UserInterface
   */
  private $vendorUser;

  /**
   * Another vendor user.
   *
   * @var \Drupal\user\UserInterface
   */
  private $otherVendorUser;

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
    $this->vendorUser = User::create([
      'name' => 'vendor',
      'mail' => 'vendor@example.com',
      'status' => 1,
    ]);
    $this->vendorUser->save();

    $this->otherVendorUser = User::create([
      'name' => 'other_vendor',
      'mail' => 'other@example.com',
      'status' => 1,
    ]);
    $this->otherVendorUser->save();

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

    $this->tokenService = $this->container->get('myeventlane_checkout_paragraph.checkin_token');
  }

  /**
   * Tests that vendor can check in attendee for owned event.
   */
  public function testVendorCanCheckInAttendeeForOwnedEvent(): void {
    // Create event owned by vendor.
    $event = Node::create([
      'type' => 'event',
      'title' => 'Vendor Event',
      'status' => 1,
      'uid' => $this->vendorUser->id(),
    ]);
    $event->save();

    // Create order with attendee paragraph.
    $order = $this->createOrderWithAttendee($event, $this->store);
    $paragraph = $this->getAttendeeParagraph($order);

    // Verify paragraph is not checked in initially.
    $initially_checked_in = FALSE;
    if ($paragraph->hasField('field_checked_in') && !$paragraph->get('field_checked_in')->isEmpty()) {
      $initially_checked_in = (bool) $paragraph->get('field_checked_in')->value;
    }
    $this->assertFalse($initially_checked_in, 'Paragraph should not be checked in initially.');

    // Check in as vendor user.
    $this->container->get('current_user')->setAccount($this->vendorUser);
    $accessHandler = $this->container->get('entity_type.manager')->getAccessControlHandler('paragraph');
    $access = $accessHandler->access($paragraph, 'update', $this->vendorUser);
    $this->assertTrue($access, 'Vendor should have update access to attendee paragraph for owned event.');

    // Simulate check-in.
    if ($paragraph->hasField('field_checked_in')) {
      $paragraph->set('field_checked_in', 1);
    }
    if ($paragraph->hasField('field_checked_in_timestamp')) {
      $paragraph->set('field_checked_in_timestamp', time());
    }
    if ($paragraph->hasField('field_checked_in_by')) {
      $paragraph->set('field_checked_in_by', $this->vendorUser->id());
    }
    $paragraph->save();

    // Reload and verify.
    $paragraph = \Drupal::entityTypeManager()->getStorage('paragraph')->load($paragraph->id());
    if ($paragraph->hasField('field_checked_in') && !$paragraph->get('field_checked_in')->isEmpty()) {
      $this->assertTrue(
        (bool) $paragraph->get('field_checked_in')->value,
        'Paragraph should be checked in after check-in action.'
      );
    }
  }

  /**
   * Tests that vendor cannot check in attendee for other vendor event.
   */
  public function testVendorCannotCheckInAttendeeForOtherVendorEvent(): void {
    // Create event owned by other vendor.
    $event = Node::create([
      'type' => 'event',
      'title' => 'Other Vendor Event',
      'status' => 1,
      'uid' => $this->otherVendorUser->id(),
    ]);
    $event->save();

    // Create order with attendee paragraph.
    $order = $this->createOrderWithAttendee($event, $this->store);
    $paragraph = $this->getAttendeeParagraph($order);

    // Try to access as vendor user (should be denied).
    $this->container->get('current_user')->setAccount($this->vendorUser);
    $accessHandler = $this->container->get('entity_type.manager')->getAccessControlHandler('paragraph');
    $access = $accessHandler->access($paragraph, 'update', $this->vendorUser);
    $this->assertFalse($access, 'Vendor should not have update access to attendee paragraph for other vendor event.');
  }

  /**
   * Tests that token cannot be forged.
   */
  public function testTokenCannotBeForged(): void {
    // Create event and order.
    $event = Node::create([
      'type' => 'event',
      'title' => 'Test Event',
      'status' => 1,
      'uid' => $this->vendorUser->id(),
    ]);
    $event->save();

    $order = $this->createOrderWithAttendee($event, $this->store);
    $paragraph = $this->getAttendeeParagraph($order);

    // Generate valid token.
    $valid_token = $this->tokenService->generateToken($paragraph);
    $this->assertNotEmpty($valid_token, 'Token should be generated.');

    // Validate valid token.
    $token_data = $this->tokenService->validateToken($valid_token);
    $this->assertNotNull($token_data, 'Valid token should be validated.');
    $this->assertTrue($token_data['valid'], 'Token should be marked as valid.');
    $this->assertEquals($paragraph->id(), $token_data['paragraph_id'], 'Token should resolve to correct paragraph ID.');

    // Try to forge token with wrong HMAC.
    $forged_token = base64_encode($paragraph->id() . ':1234567890:invalid_hmac');
    $forged_data = $this->tokenService->validateToken($forged_token);
    $this->assertNull($forged_data, 'Forged token should be rejected.');

    // Try to forge token with wrong paragraph ID.
    $forged_token2 = base64_encode('999999:' . time() . ':' . base64_encode('fake_hmac'));
    $forged_data2 = $this->tokenService->validateToken($forged_token2);
    $this->assertNull($forged_data2, 'Token with wrong paragraph ID should be rejected.');
  }

  /**
   * Creates an order with an attendee paragraph.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The store.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface
   *   The created order.
   */
  private function createOrderWithAttendee($event, $store) {
    // Create product and variation.
    $product = Product::create([
      'type' => 'default',
      'title' => 'Test Tickets',
      'stores' => [$store],
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
      'store_id' => $store->id(),
      'state' => 'completed',
      'uid' => 1,
    ]);
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
