<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_pro\Kernel;

use Drupal\advancedqueue\Job;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_price\Price;
use Drupal\commerce_store\Entity\Store;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_pro\Plugin\AdvancedQueue\JobType\ProAbandonedCartJob;
use Drupal\myeventlane_pro\Service\AbandonedCartScheduler;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Kernel coverage for Pro abandoned cart engine.
 *
 * @group myeventlane_pro
 */
final class AbandonedCartEngineKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'commerce',
    'commerce_price',
    'commerce_store',
    'commerce_order',
    'state_machine',
    'advancedqueue',
    'myeventlane_messaging',
    'myeventlane_vendor',
    'myeventlane_pro',
  ];

  /**
   * The scheduler under test.
   *
   * @var \Drupal\myeventlane_pro\Service\AbandonedCartScheduler
   */
  private AbandonedCartScheduler $scheduler;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('commerce_store');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installEntitySchema('advancedqueue_queue');
    $this->installEntitySchema('advancedqueue_job');
    $this->installConfig(['commerce_store', 'commerce_order', 'advancedqueue', 'myeventlane_pro']);
    $this->installSchema('myeventlane_pro', ['myeventlane_pro_abandoned_cart']);

    if (!Role::load('pro_organiser')) {
      Role::create([
        'id' => 'pro_organiser',
        'label' => 'Pro Organiser',
      ])->save();
    }

    if (!OrderItemType::load('default')) {
      OrderItemType::create([
        'id' => 'default',
        'label' => 'Default',
        'orderType' => 'default',
      ])->save();
    }

    $this->scheduler = $this->container->get('myeventlane_pro.abandoned_cart_scheduler');
  }

  /**
   * Scheduling the same order twice only creates one row per step.
   */
  public function testDuplicatePreventionOnSchedule(): void {
    $owner = User::create([
      'name' => 'pro_vendor',
      'mail' => 'pro@example.com',
      'status' => 1,
      'roles' => ['authenticated', 'pro_organiser'],
    ]);
    $owner->save();

    $store = $this->createStore($owner->id(), 'Pro Store');
    $order = $this->createDraftOrder($store->id(), $owner->id());

    $this->scheduler->scheduleForOrder($order);
    $this->scheduler->scheduleForOrder($order);

    $rows = $this->container->get('database')->select('myeventlane_pro_abandoned_cart', 't')
      ->fields('t', ['step'])
      ->condition('order_id', (int) $order->id())
      ->execute()
      ->fetchCol();

    sort($rows);
    $this->assertSame(['w1', 'w2'], $rows);
  }

  /**
   * Non-Pro owners never get abandoned-cart reminder rows.
   */
  public function testNonProStoreDoesNotSchedule(): void {
    $owner = User::create([
      'name' => 'non_pro_vendor',
      'mail' => 'nonpro@example.com',
      'status' => 1,
      'roles' => ['authenticated'],
    ]);
    $owner->save();

    $store = $this->createStore($owner->id(), 'Non-Pro Store');
    $order = $this->createDraftOrder($store->id(), $owner->id());

    $created = $this->scheduler->scheduleForOrder($order);
    $this->assertSame(0, $created);
  }

  /**
   * Rows are skipped when order becomes completed before worker processing.
   */
  public function testCompletedOrderIsSkippedByWorker(): void {
    $owner = User::create([
      'name' => 'pro_vendor_worker',
      'mail' => 'worker@example.com',
      'status' => 1,
      'roles' => ['authenticated', 'pro_organiser'],
    ]);
    $owner->save();

    $store = $this->createStore($owner->id(), 'Worker Store');
    $order = $this->createDraftOrder($store->id(), $owner->id());
    $order->set('state', 'completed');
    $order->set('cart', 0);
    $order->save();

    $db = $this->container->get('database');
    $trackingId = (int) $db->insert('myeventlane_pro_abandoned_cart')
      ->fields([
        'order_id' => (int) $order->id(),
        'store_id' => (int) $store->id(),
        'step' => 'w1',
        'scheduled' => \Drupal::time()->getRequestTime() - 10,
        'status' => 'scheduled',
      ])
      ->execute();

    $plugin = new ProAbandonedCartJob(
      [],
      'pro_abandoned_cart_job',
      [],
      $this->container->get('database'),
      $this->container->get('entity_type.manager'),
      $this->container->get('datetime.time'),
      $this->container->get('logger.channel.myeventlane_pro'),
      $this->container->get('myeventlane_pro.entitlement'),
      $this->container->get('config.factory'),
      $this->container->get('plugin.manager.mail'),
      $this->container->get('myeventlane_messaging.manager'),
    );

    $result = $plugin->process(Job::create('pro_abandoned_cart_job', ['tracking_id' => $trackingId]));
    $this->assertTrue($result->isSuccess());

    $row = $db->select('myeventlane_pro_abandoned_cart', 't')
      ->fields('t', ['status'])
      ->condition('id', $trackingId)
      ->execute()
      ->fetchAssoc();
    $this->assertSame('skipped', $row['status']);
  }

  /**
   * Creates a store with owner.
   */
  private function createStore(int $ownerId, string $name): Store {
    $store = Store::create([
      'type' => 'default',
      'name' => $name,
      'mail' => 'store@example.com',
      'default_currency' => 'AUD',
      'is_default' => TRUE,
      'uid' => $ownerId,
    ]);
    $store->save();
    return $store;
  }

  /**
   * Creates a draft cart order with one non-zero order item.
   */
  private function createDraftOrder(int $storeId, int $ownerId): Order {
    $order = Order::create([
      'type' => 'default',
      'store_id' => $storeId,
      'state' => 'draft',
      'uid' => $ownerId,
      'mail' => 'buyer@example.com',
      'cart' => 1,
    ]);
    $order->save();

    $item = OrderItem::create([
      'type' => 'default',
      'quantity' => 1,
      'unit_price' => new Price('12.00', 'AUD'),
    ]);
    $item->save();
    $order->addItem($item);
    $order->save();

    return $order;
  }

}

