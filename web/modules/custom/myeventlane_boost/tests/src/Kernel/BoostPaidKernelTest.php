<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_boost\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_order\Event\OrderEvents;
use Drupal\commerce_price\Entity\Currency;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductType;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationType;
use Drupal\commerce_store\Entity\Store;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;

/**
 * Tests boost application on order payment.
 *
 * @group myeventlane_boost
 */
final class BoostPaidKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'datetime',
    'text',
    'commerce',
    'commerce_store',
    'commerce_order',
    'commerce_price',
    'commerce_product',
    'state_machine',
    'myeventlane_boost',
  ];

  /**
   * Tests entitlement activation and idempotency on order paid.
   */
  public function testBoostEntitlementCreatedAndIdempotent(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('commerce_currency');
    $this->installEntitySchema('commerce_store');
    $this->installEntitySchema('commerce_product');
    $this->installEntitySchema('commerce_product_variation');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installEntitySchema('myeventlane_boost_entitlement');

    $this->installConfig([
      'system',
      'user',
      'node',
      'commerce_price',
      'commerce_store',
      'commerce_product',
      'commerce_order',
      'myeventlane_boost',
    ]);

    $this->ensureNodeSchema();
    $this->ensureCommerceSchema();
    $this->ensureBoostCatalogSchema();
    $this->ensureBoostOrderItemFields();

    $vendor = User::create([
      'name' => 'vendor_user',
      'mail' => 'vendor@example.test',
      'status' => 1,
    ]);
    $vendor->save();

    $store = Store::create([
      'type' => 'online',
      'uid' => $vendor->id(),
      'name' => 'Vendor Store',
      'mail' => 'store@example.test',
      'default_currency' => 'AUD',
      'status' => TRUE,
      'is_default' => TRUE,
    ]);
    $store->save();

    $event = Node::create([
      'type' => 'event',
      'title' => 'Boosted Event',
      'status' => 1,
      'uid' => $vendor->id(),
      'field_promoted' => 0,
      'field_promo_expires' => NULL,
    ]);
    $event->save();

    $product = Product::create([
      'type' => 'boost_upgrade',
      'title' => 'Event Boost',
      'status' => 1,
      'stores' => [$store->id()],
    ]);
    $product->save();

    $variation = ProductVariation::create([
      'type' => 'boost_duration',
      'sku' => 'BOOST-7D',
      'title' => '7 Day Boost',
      'status' => 1,
      'price' => new Price('35.00', 'AUD'),
      'field_boost_days' => 7,
    ]);
    $variation->save();
    $product->addVariation($variation);
    $product->save();

    $order_item_storage = $this->container->get('entity_type.manager')->getStorage('commerce_order_item');
    $order_item = $order_item_storage->createFromPurchasableEntity($variation, ['type' => 'default']);
    $order_item->setQuantity('1');
    $order_item->set('field_target_event', ['target_id' => $event->id()]);
    $order_item->set('field_boost_days_purchased', 7);
    $order_item->save();

    $order = Order::create([
      'type' => 'default',
      'store_id' => $store->id(),
      'uid' => $vendor->id(),
      'state' => 'draft',
      'order_items' => [$order_item],
    ]);
    $order->save();

    $dispatcher = $this->container->get('event_dispatcher');
    $dispatcher->dispatch(new OrderEvent($order), OrderEvents::ORDER_PAID);

    $entitlement_storage = $this->container->get('entity_type.manager')->getStorage('myeventlane_boost_entitlement');
    $entitlement_ids = $entitlement_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_item_id', $order_item->id())
      ->execute();
    $this->assertCount(1, $entitlement_ids);

    $entitlement = $entitlement_storage->load(reset($entitlement_ids));
    $this->assertSame('active', (string) $entitlement->get('status')->value);
    $this->assertSame((int) $event->id(), (int) $entitlement->get('event')->target_id);
    $this->assertSame((int) $order->id(), (int) $entitlement->get('order_id')->target_id);
    $this->assertSame((int) $variation->id(), (int) $entitlement->get('variation_id')->target_id);

    $starts = (int) $entitlement->get('starts')->value;
    $ends = (int) $entitlement->get('ends')->value;
    $this->assertSame(7 * 86400, $ends - $starts);

    $dispatcher->dispatch(new OrderEvent($order), OrderEvents::ORDER_PAID);
    $entitlement_ids_second = $entitlement_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_item_id', $order_item->id())
      ->execute();
    $this->assertCount(1, $entitlement_ids_second);
  }

  /**
   * Ensures node schema for boost denormalized fields.
   */
  private function ensureNodeSchema(): void {
    if (!NodeType::load('event')) {
      NodeType::create([
        'type' => 'event',
        'name' => 'Event',
      ])->save();
    }

    if (!FieldStorageConfig::loadByName('node', 'field_promoted')) {
      FieldStorageConfig::create([
        'field_name' => 'field_promoted',
        'entity_type' => 'node',
        'type' => 'boolean',
      ])->save();
    }
    if (!FieldConfig::loadByName('node', 'event', 'field_promoted')) {
      FieldConfig::create([
        'field_name' => 'field_promoted',
        'entity_type' => 'node',
        'bundle' => 'event',
        'label' => 'Promoted',
      ])->save();
    }

    if (!FieldStorageConfig::loadByName('node', 'field_promo_expires')) {
      FieldStorageConfig::create([
        'field_name' => 'field_promo_expires',
        'entity_type' => 'node',
        'type' => 'datetime',
        'settings' => [
          'datetime_type' => 'datetime',
        ],
      ])->save();
    }
    if (!FieldConfig::loadByName('node', 'event', 'field_promo_expires')) {
      FieldConfig::create([
        'field_name' => 'field_promo_expires',
        'entity_type' => 'node',
        'bundle' => 'event',
        'label' => 'Promo expires',
      ])->save();
    }
  }

  /**
   * Ensures baseline commerce entities exist.
   */
  private function ensureCommerceSchema(): void {
    if (!Currency::load('AUD')) {
      Currency::create([
        'currencyCode' => 'AUD',
        'name' => 'Australian Dollar',
        'numericCode' => '036',
        'symbol' => '$',
        'fractionDigits' => 2,
      ])->save();
    }

    $store_type_storage = $this->container->get('entity_type.manager')->getStorage('commerce_store_type');
    if (!$store_type_storage->load('online')) {
      $store_type_storage->create([
        'id' => 'online',
        'label' => 'Online',
      ])->save();
    }

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
        'purchasableEntityType' => 'commerce_product_variation',
      ])->save();
    }
  }

  /**
   * Ensures boost product/variation schema exists.
   */
  private function ensureBoostCatalogSchema(): void {
    if (!ProductVariationType::load('boost_duration')) {
      ProductVariationType::create([
        'id' => 'boost_duration',
        'label' => 'Boost Duration',
        'orderItemType' => 'default',
        'generateTitle' => TRUE,
      ])->save();
    }

    if (!ProductType::load('boost_upgrade')) {
      ProductType::create([
        'id' => 'boost_upgrade',
        'label' => 'Boost Upgrade',
        'variationType' => 'boost_duration',
        'injectVariationFields' => TRUE,
      ])->save();
    }

    if (!FieldStorageConfig::loadByName('commerce_product_variation', 'field_boost_days')) {
      FieldStorageConfig::create([
        'field_name' => 'field_boost_days',
        'entity_type' => 'commerce_product_variation',
        'type' => 'integer',
      ])->save();
    }
    if (!FieldConfig::loadByName('commerce_product_variation', 'boost_duration', 'field_boost_days')) {
      FieldConfig::create([
        'field_name' => 'field_boost_days',
        'entity_type' => 'commerce_product_variation',
        'bundle' => 'boost_duration',
        'label' => 'Boost Days',
      ])->save();
    }
  }

  /**
   * Ensures boost order item metadata fields exist.
   */
  private function ensureBoostOrderItemFields(): void {
    if (!FieldStorageConfig::loadByName('commerce_order_item', 'field_target_event')) {
      FieldStorageConfig::create([
        'field_name' => 'field_target_event',
        'entity_type' => 'commerce_order_item',
        'type' => 'entity_reference',
        'settings' => [
          'target_type' => 'node',
        ],
      ])->save();
    }
    if (!FieldConfig::loadByName('commerce_order_item', 'default', 'field_target_event')) {
      FieldConfig::create([
        'field_name' => 'field_target_event',
        'entity_type' => 'commerce_order_item',
        'bundle' => 'default',
        'label' => 'Target Event',
        'settings' => [
          'handler' => 'default:node',
          'handler_settings' => [
            'target_bundles' => [
              'event' => 'event',
            ],
          ],
        ],
      ])->save();
    }

    if (!FieldStorageConfig::loadByName('commerce_order_item', 'field_boost_days_purchased')) {
      FieldStorageConfig::create([
        'field_name' => 'field_boost_days_purchased',
        'entity_type' => 'commerce_order_item',
        'type' => 'integer',
      ])->save();
    }
    if (!FieldConfig::loadByName('commerce_order_item', 'default', 'field_boost_days_purchased')) {
      FieldConfig::create([
        'field_name' => 'field_boost_days_purchased',
        'entity_type' => 'commerce_order_item',
        'bundle' => 'default',
        'label' => 'Boost Days Purchased',
      ])->save();
    }
  }

}
