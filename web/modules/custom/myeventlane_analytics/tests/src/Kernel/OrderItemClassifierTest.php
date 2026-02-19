<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_analytics\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_price\Entity\Currency;
use Drupal\commerce_price\Price;
use Drupal\commerce_store\Entity\Store;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_analytics\Service\OrderItemClassifier;

/**
 * Kernel tests for OrderItemClassifier.
 *
 * Validates donation/boost exclusion for vendor revenue eligibility.
 *
 * @group myeventlane_analytics
 */
final class OrderItemClassifierTest extends KernelTestBase {

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
   * The order item classifier.
   */
  private OrderItemClassifier $classifier;

  /**
   * A test store.
   */
  private ?Store $store = NULL;

  /**
   * A test user for orders.
   */
  private ?int $testUserId = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('commerce_currency');
    $this->installEntitySchema('commerce_store');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installConfig(['commerce_order']);
    $this->ensureCurrency('AUD', 'Australian Dollar', '036', '$', 2);
    $this->ensureStoreAndOrderTypes();
    $this->ensureOrderItemTypes();
    $user = $this->container->get('entity_type.manager')
      ->getStorage('user')
      ->create(['name' => 'test', 'status' => 1]);
    $user->save();
    $this->testUserId = (int) $user->id();
    $this->classifier = new OrderItemClassifier();
  }

  /**
   * Ensures a Commerce currency exists.
   */
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

  /**
   * Ensures store and order types exist.
   */
  private function ensureStoreAndOrderTypes(): void {
    $storeTypeStorage = $this->container->get('entity_type.manager')->getStorage('commerce_store_type');
    if (!$storeTypeStorage->load('online')) {
      $storeTypeStorage->create(['id' => 'online', 'label' => 'Online'])->save();
    }
    $this->store = Store::create([
      'type' => 'online',
      'name' => 'Test',
      'mail' => 'test@example.com',
      'default_currency' => 'AUD',
      'status' => 1,
    ]);
    $this->store->save();

    if (!OrderType::load('default')) {
      OrderType::create([
        'id' => 'default',
        'label' => 'Default',
        'workflow' => 'order_default',
      ])->save();
    }
  }

  /**
   * Ensures required order item types exist.
   */
  private function ensureOrderItemTypes(): void {
    $types = ['default', 'boost', 'checkout_donation', 'platform_donation', 'rsvp_donation'];
    foreach ($types as $id) {
      if (!OrderItemType::load($id)) {
        OrderItemType::create([
          'id' => $id,
          'label' => ucfirst(str_replace('_', ' ', $id)),
          'orderType' => 'default',
        ])->save();
      }
    }
  }

  /**
   * Creates an order item with the given type.
   */
  private function createOrderItemWithType(string $type): OrderItem {
    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'state' => 'draft',
      'uid' => $this->testUserId,
    ]);
    $order->save();

    $item = OrderItem::create([
      'type' => $type,
      'order_id' => $order->id(),
      'unit_price' => new Price('1.00', 'AUD'),
      'quantity' => 1,
    ]);
    $item->save();
    return $item;
  }

  /**
   * Tests boost item is not vendor eligible.
   */
  public function testBoostItemNotVendorEligible(): void {
    $item = $this->createOrderItemWithType('boost');
    $this->assertTrue($this->classifier->isBoost($item));
    $this->assertFalse($this->classifier->isDonation($item));
    $this->assertFalse($this->classifier->isVendorRevenueEligible($item));
  }

  /**
   * Tests donation items are not vendor eligible.
   */
  public function testDonationItemsNotVendorEligible(): void {
    foreach (['checkout_donation', 'platform_donation', 'rsvp_donation'] as $type) {
      $item = $this->createOrderItemWithType($type);
      $this->assertFalse($this->classifier->isBoost($item));
      $this->assertTrue($this->classifier->isDonation($item));
      $this->assertFalse($this->classifier->isVendorRevenueEligible($item));
    }
  }

  /**
   * Tests normal ticket item is vendor eligible.
   */
  public function testNormalTicketVendorEligible(): void {
    $item = $this->createOrderItemWithType('default');
    $this->assertFalse($this->classifier->isBoost($item));
    $this->assertFalse($this->classifier->isDonation($item));
    $this->assertTrue($this->classifier->isVendorRevenueEligible($item));
  }

  /**
   * Tests getExcludedTypes returns canonical list.
   *
   * This test does not require Commerce entities; it validates the
   * classifier's constant array.
   */
  public function testGetExcludedTypes(): void {
    $classifier = new OrderItemClassifier();
    $excluded = $classifier->getExcludedTypes();
    $this->assertContains('boost', $excluded);
    $this->assertContains('checkout_donation', $excluded);
    $this->assertContains('platform_donation', $excluded);
    $this->assertContains('rsvp_donation', $excluded);
    $this->assertCount(4, $excluded);
  }

}
