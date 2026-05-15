<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_order\Entity\OrderType;
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
use Drupal\myeventlane_commerce\Service\OperationalMerchandiseGovernanceManager;
use Drupal\myeventlane_commerce\Service\OperationalMerchandiseManager;
use Drupal\myeventlane_commerce\Service\OperationalPurchaseCompositionManager;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;

/**
 * Kernel smoke tests for operational merchandise normalization (Commerce fixtures).
 *
 * @group myeventlane_commerce
 */
class OperationalMerchandiseKernelTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'node',
    'path',
    'path_alias',
    'options',
    'views',
    'address',
    'profile',
    'entity_reference_revisions',
    'commerce',
    'commerce_number_pattern',
    'commerce_price',
    'commerce_store',
    'commerce_order',
    'commerce_product',
    'state_machine',
  ];

  protected Store $store;

  protected Node $event;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('commerce_currency');
    $this->installEntitySchema('profile');
    $this->installEntitySchema('commerce_store');
    $this->installEntitySchema('commerce_product');
    $this->installEntitySchema('commerce_product_variation');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installConfig(['commerce_store', 'commerce_product', 'commerce_order', 'user']);

    NodeType::create(['type' => 'event', 'name' => 'Event'])->save();

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
      $store_type_storage->create(['id' => 'online', 'label' => 'Online'])->save();
    }

    $owner = User::create([
      'name' => 'merch_kernel_owner',
      'mail' => 'merch-kernel-owner@example.test',
      'status' => 1,
    ]);
    $owner->save();

    $this->store = Store::create([
      'type' => 'online',
      'uid' => $owner->id(),
      'name' => 'Merch Kernel Store',
      'mail' => 'merch-store@example.test',
      'default_currency' => 'AUD',
      'status' => TRUE,
      'is_default' => TRUE,
    ]);
    $this->store->save();

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
        'purchasableEntityType' => 'commerce_product_variation',
        'orderType' => 'default',
      ])->save();
    }

    $this->ensureCatalogTypes('operational_merchandise_var', 'operational_merchandise');
    $this->ensureCatalogTypes('hospitality_package_var', 'hospitality_package');
    $this->ensureProductEventField('operational_merchandise');
    $this->ensureProductEventField('hospitality_package');
    $this->ensureOperationalProductField('operational_merchandise');
    $this->ensureOperationalProductField('hospitality_package');
    $this->ensureEventCapabilitiesField();

    $this->event = Node::create([
      'type' => 'event',
      'title' => 'Merch kernel event',
      'uid' => $owner->id(),
      'status' => 1,
    ]);
    $this->event->save();
  }

  public function testNormalizeEventMerchandiseAuthoringLinksOperationalProduct(): void {
    $product = $this->createOperationalProduct('operational_merchandise', 'operational_merchandise_var', 'MERCH-KERNEL');
    $merch = $this->merchandiseManager();
    $normalized = $merch->normalizeEventMerchandiseAuthoring([
      'linked_products' => [
        [
          'product_id' => (int) $product->id(),
          'role' => 'merch_pickup',
          'bundle_group' => 'A',
        ],
      ],
    ], $this->event);
    $this->assertCount(1, $normalized['linked_products']);
    $link = $normalized['linked_products'][0];
    $this->assertSame((int) $product->id(), $link['product_id']);
    $this->assertArrayHasKey('product_payload', $link);
    $this->assertArrayNotHasKey('qr_payload', $link['product_payload']);
  }

  public function testComposeForOrderGroupsByOperationalProductBundle(): void {
    $customer = User::create([
      'name' => 'merch_kernel_buyer',
      'mail' => 'merch-kernel-buyer@example.test',
      'status' => 1,
    ]);
    $customer->save();

    $merchProduct = $this->createOperationalProduct('operational_merchandise', 'operational_merchandise_var', 'MERCH-ORDER');
    $merchProduct->set('field_mel_operational_product', json_encode([
      'operational_product_type' => 'merch_pickup',
      'operational_summary' => 'Collect at north counter',
      'inventory_quantity' => 50,
      'qr_payload' => 'nope',
      'operational_chips' => [
        ['label' => 'Collect after entry', 'tone' => 'info'],
      ],
    ], JSON_THROW_ON_ERROR));
    $merchProduct->save();

    $hospitalityProduct = $this->createOperationalProduct('hospitality_package', 'hospitality_package_var', 'HOSP-ORDER');
    $hospitalityProduct->save();

    $merchVariation = $merchProduct->getVariations()[0];
    $hospVariation = $hospitalityProduct->getVariations()[0];

    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'state' => 'draft',
      'uid' => $customer->id(),
      'mail' => 'merch-kernel-buyer@example.test',
    ]);
    $order->save();

    $itemMerch = OrderItem::create([
      'type' => 'default',
      'order_id' => $order->id(),
      'purchased_entity' => $merchVariation,
      'quantity' => 1,
      'unit_price' => new Price('10.00', 'AUD'),
    ]);
    $itemMerch->save();

    $itemHosp = OrderItem::create([
      'type' => 'default',
      'order_id' => $order->id(),
      'purchased_entity' => $hospVariation,
      'quantity' => 1,
      'unit_price' => new Price('40.00', 'AUD'),
    ]);
    $itemHosp->save();

    $order->addItem($itemMerch);
    $order->addItem($itemHosp);
    $order->save();

    $doc = $this->compositionManager()->composeForOrder($order);
    $this->assertTrue($doc[OperationalPurchaseCompositionManager::CONTRACT_FLAG]);
    $this->assertCount(1, $doc['groups']['merchandise']);
    $this->assertCount(1, $doc['groups']['hospitality']);
    $pres = $doc['groups']['merchandise'][0]['presentation'];
    $this->assertArrayNotHasKey('inventory_quantity', $pres);
    $this->assertArrayNotHasKey('qr_payload', $pres);
    $this->assertSame('Collect at north counter', $pres['operational_summary']);
  }

  public function testComposePreviewFromEventReadsOperationalMerchandiseJson(): void {
    $product = $this->createOperationalProduct('operational_merchandise', 'operational_merchandise_var', 'MERCH-PREVIEW');
    $product->save();

    $this->event->set('field_mel_op_capabilities', json_encode([
      'operational_merchandise' => [
        'schema_version' => 1,
        'linked_products' => [
          [
            'product_id' => (int) $product->id(),
            'role' => 'timed_collection',
            'bundle_group' => 'T1',
            'product_payload' => [
              'operational_product_type' => 'timed_collection_product',
              'pickup_mode' => 'conflict',
              'readiness_mode' => 'authoring',
              'customer_visibility' => 'visible',
            ],
          ],
        ],
      ],
    ], JSON_THROW_ON_ERROR));
    $this->event->save();

    $doc = $this->compositionManager()->composePreviewFromEvent(Node::load($this->event->id()));
    $this->assertCount(1, $doc['groups']['timed_collection']);
    $this->assertSame('elevated', $doc['governance']['severity']);
  }

  protected function merchandiseManager(): OperationalMerchandiseManager {
    return new OperationalMerchandiseManager(
      $this->container->get('entity_type.manager'),
      $this->container->get('string_translation'),
      $this->container->get('logger.factory')->get('operational_merchandise_kernel'),
    );
  }

  protected function compositionManager(): OperationalPurchaseCompositionManager {
    $merch = $this->merchandiseManager();
    $gov = new OperationalMerchandiseGovernanceManager(
      $merch,
      $this->container->get('string_translation'),
    );
    return new OperationalPurchaseCompositionManager(
      $merch,
      $gov,
      $this->container->get('string_translation'),
    );
  }

  private function ensureCatalogTypes(string $variationTypeId, string $productTypeId): void {
    if (!ProductVariationType::load($variationTypeId)) {
      ProductVariationType::create([
        'id' => $variationTypeId,
        'label' => $variationTypeId,
        'orderItemType' => 'default',
        'generateTitle' => TRUE,
      ])->save();
    }

    if (!ProductType::load($productTypeId)) {
      ProductType::create([
        'id' => $productTypeId,
        'label' => $productTypeId,
        'variationType' => $variationTypeId,
        'injectVariationFields' => TRUE,
      ])->save();
    }
  }

  private function ensureProductEventField(string $bundle): void {
    if (!FieldStorageConfig::loadByName('commerce_product', 'field_event')) {
      FieldStorageConfig::create([
        'field_name' => 'field_event',
        'entity_type' => 'commerce_product',
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'node'],
      ])->save();
    }
    if (!FieldConfig::loadByName('commerce_product', $bundle, 'field_event')) {
      FieldConfig::create([
        'field_name' => 'field_event',
        'entity_type' => 'commerce_product',
        'bundle' => $bundle,
        'label' => 'Event',
      ])->save();
    }
  }

  private function ensureOperationalProductField(string $bundle): void {
    if (!FieldStorageConfig::loadByName('commerce_product', 'field_mel_operational_product')) {
      FieldStorageConfig::create([
        'field_name' => 'field_mel_operational_product',
        'entity_type' => 'commerce_product',
        'type' => 'string_long',
      ])->save();
    }
    if (!FieldConfig::loadByName('commerce_product', $bundle, 'field_mel_operational_product')) {
      FieldConfig::create([
        'field_name' => 'field_mel_operational_product',
        'entity_type' => 'commerce_product',
        'bundle' => $bundle,
        'label' => 'MEL operational product',
      ])->save();
    }
  }

  private function ensureEventCapabilitiesField(): void {
    if (FieldStorageConfig::loadByName('node', 'field_mel_op_capabilities')) {
      return;
    }
    FieldStorageConfig::create([
      'field_name' => 'field_mel_op_capabilities',
      'entity_type' => 'node',
      'type' => 'string_long',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_mel_op_capabilities',
      'entity_type' => 'node',
      'bundle' => 'event',
      'label' => 'Operational capabilities',
    ])->save();
  }

  protected function createOperationalProduct(string $productType, string $variationType, string $sku): Product {
    $product = Product::create([
      'type' => $productType,
      'title' => $sku . ' product',
      'status' => 1,
      'stores' => [$this->store->id()],
      'field_event' => ['target_id' => $this->event->id()],
    ]);
    $product->save();

    $variation = ProductVariation::create([
      'type' => $variationType,
      'sku' => $sku,
      'title' => $sku . ' variation',
      'status' => 1,
      'price' => new Price('15.00', 'AUD'),
    ]);
    $variation->save();

    $product->addVariation($variation);
    $product->save();

    $reloaded = Product::load($product->id());
    $this->assertInstanceOf(Product::class, $reloaded);
    return $reloaded;
  }

}
