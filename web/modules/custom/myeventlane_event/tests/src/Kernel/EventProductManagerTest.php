<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event\Kernel;

use Drupal\commerce_store\Entity\Store;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Kernel tests for EventProductManager.
 *
 * @group myeventlane_event
 */
final class EventProductManagerTest extends KernelTestBase {

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
    'myeventlane_event',
  ];

  /**
   * The product manager service.
   *
   * @var \Drupal\myeventlane_event\Service\EventProductManager
   */
  private $productManager;

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
    $this->installConfig(['commerce_product', 'commerce_store']);

    // Create event content type if it doesn't exist.
    if (!NodeType::load('event')) {
      NodeType::create([
        'type' => 'event',
        'name' => 'Event',
      ])->save();
    }

    // Create a default store.
    $store = Store::create([
      'type' => 'default',
      'name' => 'Test Store',
      'mail' => 'test@example.com',
      'default_currency' => 'USD',
      'is_default' => TRUE,
    ]);
    $store->save();

    $this->productManager = $this->container->get('myeventlane_event.event_product_manager');
  }

  /**
   * Tests that draft events do not create products.
   */
  public function testDraftEventsDoNotCreateProducts(): void {
    // Create an unpublished (draft) RSVP event.
    $event = Node::create([
      'type' => 'event',
      'title' => 'Test Draft Event',
    // Unpublished.
      'status' => 0,
      'field_event_type' => 'rsvp',
    ]);
    $event->save();

    // Attempt to sync with invalid intent (should fail).
    $result = $this->productManager->syncProducts($event, 'publish');
    $this->assertFalse($result, 'Draft events cannot sync products with "publish" intent.');

    // Verify no product was created.
    $products = \Drupal::entityTypeManager()
      ->getStorage('commerce_product')
      ->loadByProperties(['title' => 'Test Draft Event - RSVP']);
    $this->assertEmpty($products, 'No products should be created for draft events.');
  }

  /**
   * Tests that publish intent creates products.
   */
  public function testPublishIntentCreatesProducts(): void {
    // Create a published RSVP event.
    $event = Node::create([
      'type' => 'event',
      'title' => 'Test Published Event',
    // Published.
      'status' => 1,
      'field_event_type' => 'rsvp',
    ]);
    $event->save();

    // Sync products with 'publish' intent.
    $result = $this->productManager->syncProducts($event, 'publish');
    $this->assertTrue($result, 'Product sync should succeed for published events.');

    // Verify product was created and linked.
    $event = Node::load($event->id());
    $this->assertFalse($event->get('field_product_target')->isEmpty(), 'Event should have a linked product.');

    $product = $event->get('field_product_target')->entity;
    $this->assertNotNull($product, 'Product should exist.');
    $this->assertEquals('Test Published Event - RSVP', $product->label(), 'Product title should match event.');
    $this->assertTrue($product->isPublished(), 'Product should be published.');
  }

  /**
   * Tests that repeated publish does NOT duplicate products.
   */
  public function testRepeatedPublishDoesNotDuplicateProducts(): void {
    // Create a published RSVP event.
    $event = Node::create([
      'type' => 'event',
      'title' => 'Test Repeated Publish Event',
      'status' => 1,
      'field_event_type' => 'rsvp',
    ]);
    $event->save();

    // First sync.
    $result1 = $this->productManager->syncProducts($event, 'publish');
    $this->assertTrue($result1, 'First sync should succeed.');

    // Reload event to get the product reference.
    $event = Node::load($event->id());
    $firstProductId = $event->get('field_product_target')->target_id;
    $this->assertNotNull($firstProductId, 'First product should be created.');

    // Count products before second sync.
    $productsBefore = \Drupal::entityTypeManager()
      ->getStorage('commerce_product')
      ->loadByProperties(['title' => 'Test Repeated Publish Event - RSVP']);
    $countBefore = count($productsBefore);

    // Second sync (should not create duplicate).
    $result2 = $this->productManager->syncProducts($event, 'publish');
    $this->assertTrue($result2, 'Second sync should succeed.');

    // Reload event.
    $event = Node::load($event->id());
    $secondProductId = $event->get('field_product_target')->target_id;

    // Verify same product ID (no duplicate created).
    $this->assertEquals($firstProductId, $secondProductId, 'Same product should be used, no duplicate created.');

    // Count products after second sync.
    $productsAfter = \Drupal::entityTypeManager()
      ->getStorage('commerce_product')
      ->loadByProperties(['title' => 'Test Repeated Publish Event - RSVP']);
    $countAfter = count($productsAfter);

    $this->assertEquals($countBefore, $countAfter, 'Product count should not increase on repeated sync.');
  }

  /**
   * Tests that sync fails for new (unsaved) nodes.
   */
  public function testSyncFailsForNewNodes(): void {
    // Create a new (unsaved) event.
    $event = Node::create([
      'type' => 'event',
      'title' => 'Test New Event',
      'status' => 1,
      'field_event_type' => 'rsvp',
    ]);
    // Do NOT save.
    // Attempt to sync (should fail).
    $result = $this->productManager->syncProducts($event, 'publish');
    $this->assertFalse($result, 'Sync should fail for new (unsaved) nodes.');
  }

  /**
   * Tests that invalid intent is rejected.
   */
  public function testInvalidIntentRejected(): void {
    $event = Node::create([
      'type' => 'event',
      'title' => 'Test Invalid Intent Event',
      'status' => 1,
      'field_event_type' => 'rsvp',
    ]);
    $event->save();

    // Attempt to sync with invalid intent (should fail).
    $result = $this->productManager->syncProducts($event, 'invalid_intent');
    $this->assertFalse($result, 'Invalid intent should be rejected.');
  }

}
