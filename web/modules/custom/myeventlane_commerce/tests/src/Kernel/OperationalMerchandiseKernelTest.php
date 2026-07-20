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
use Drupal\myeventlane_commerce\Service\EventOperationalAddonBuilder;
use Drupal\myeventlane_commerce\Service\OperationalExtraVisualPresenter;
use Drupal\myeventlane_commerce\Service\OperationalMerchandiseGovernanceManager;
use Drupal\myeventlane_commerce\Service\OperationalMerchandiseManager;
use Drupal\myeventlane_commerce\Service\OperationalPurchaseCompositionManager;
use Drupal\myeventlane_commerce\Service\OperationalVariationStockResolver;
use Drupal\myeventlane_event_studio\Service\OperationalCapabilityCommerceLinkManager;
use Drupal\myeventlane_event_studio\Service\VendorOperationalProductCreationManager;
use Drupal\myeventlane_event_studio\Service\VendorProductisationStudioManager;
use Drupal\myeventlane_tickets\Service\EntitlementCapabilityRegistry;
use Drupal\myeventlane_tickets\Service\FulfillmentLifecycleManager;
use Drupal\myeventlane_tickets\Service\InventoryReservationGovernanceManager;
use Drupal\myeventlane_tickets\Service\OperationalEntitlementCapabilityManager;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
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
    'filter',
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
    $this->ensureKernelEventStoreReferenceForWizard();
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

  public function testComposeForOrderGroupsParkingAddonByCapabilityReference(): void {
    $customer = User::create([
      'name' => 'merch_kernel_parking_buyer',
      'mail' => 'merch-kernel-parking@example.test',
      'status' => 1,
    ]);
    $customer->save();

    $parkingProduct = $this->createOperationalProduct('operational_merchandise', 'operational_merchandise_var', 'PARK-ORDER');
    $parkingProduct->set('field_mel_operational_product', json_encode([
      'operational_product_type' => 'merch_pickup',
      'capability_reference' => 'parking_addon',
      'operational_summary' => 'Lot B after 5pm',
      'operational_chips' => [],
    ], JSON_THROW_ON_ERROR));
    $parkingProduct->save();

    $plainMerch = $this->createOperationalProduct('operational_merchandise', 'operational_merchandise_var', 'MERCH-WITH-PARK');
    $plainMerch->set('field_mel_operational_product', json_encode([
      'operational_product_type' => 'merch_pickup',
      'capability_reference' => 'merchandise',
      'operational_summary' => 'Counter pickup',
      'operational_chips' => [],
    ], JSON_THROW_ON_ERROR));
    $plainMerch->save();

    $parkingVariation = $parkingProduct->getVariations()[0];
    $merchVariation = $plainMerch->getVariations()[0];

    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'state' => 'draft',
      'uid' => $customer->id(),
      'mail' => 'merch-kernel-parking@example.test',
    ]);
    $order->save();

    $itemParking = OrderItem::create([
      'type' => 'default',
      'order_id' => $order->id(),
      'purchased_entity' => $parkingVariation,
      'quantity' => 1,
      'unit_price' => new Price('5.00', 'AUD'),
    ]);
    $itemParking->save();

    $itemMerch = OrderItem::create([
      'type' => 'default',
      'order_id' => $order->id(),
      'purchased_entity' => $merchVariation,
      'quantity' => 2,
      'unit_price' => new Price('10.00', 'AUD'),
    ]);
    $itemMerch->save();

    $order->addItem($itemParking);
    $order->addItem($itemMerch);
    $order->save();

    $doc = $this->compositionManager()->composeForOrder($order);
    $this->assertCount(1, $doc['groups']['parking']);
    $this->assertCount(1, $doc['groups']['merchandise']);
    $this->assertSame('Lot B after 5pm', $doc['groups']['parking'][0]['presentation']['operational_summary']);
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

  public function testEventOperationalAddonBuilderReturnsLinkedOperationalProducts(): void {
    $product = $this->createOperationalProduct('operational_merchandise', 'operational_merchandise_var', 'ADDON-K1');
    $product->set('field_mel_operational_product', json_encode([
      'operational_product_type' => 'merch_pickup',
      'operational_summary' => 'Pick up at desk',
      'inventory_quantity' => 50,
      'qr_payload' => 'nope',
      'operational_chips' => [
        ['label' => 'After entry', 'tone' => 'info'],
      ],
    ], JSON_THROW_ON_ERROR));
    $product->save();

    $builder = $this->eventOperationalAddonBuilder();
    $built = $builder->buildForEvent($this->event);
    $this->assertNotSame([], $built['addons']);
    $first = $built['addons'][0];
    $this->assertSame((int) $product->id(), (int) ($first['product_id'] ?? 0));
    $this->assertArrayHasKey('operational', $first);
    $this->assertArrayNotHasKey('inventory_quantity', $first['operational']);
    $this->assertArrayNotHasKey('qr_payload', $first['operational']);
    $this->assertSame('Pick up at desk', $first['operational']['operational_summary']);
    $this->assertNotEmpty($first['variations']);
  }

  public function testEventOperationalAddonBuilderExcludesUnpublishedProduct(): void {
    $product = $this->createOperationalProduct('operational_merchandise', 'operational_merchandise_var', 'ADDON-OFF');
    $product->set('status', 0);
    $product->save();

    $builder = $this->eventOperationalAddonBuilder();
    $built = $builder->buildForEvent($this->event);
    $this->assertSame([], $built['addons']);
  }

  public function testEventOperationalAddonBuilderExcludesUnpublishedVariation(): void {
    $product = $this->createOperationalProduct('operational_merchandise', 'operational_merchandise_var', 'ADDON-VAROFF');
    foreach ($product->getVariations() as $variation) {
      $variation->set('status', 0);
      $variation->save();
    }
    $product->save();

    $builder = $this->eventOperationalAddonBuilder();
    $built = $builder->buildForEvent($this->event);
    $this->assertSame([], $built['addons']);
  }

  public function testEventOperationalAddonBuilderExcludesProductWithoutStore(): void {
    $product = $this->createOperationalProduct('operational_merchandise', 'operational_merchandise_var', 'ADDON-NOSTORE');
    $product->set('stores', []);
    $product->save();

    $builder = $this->eventOperationalAddonBuilder();
    $built = $builder->buildForEvent($this->event);
    $this->assertSame([], $built['addons']);
  }

  public function testEventOperationalAddonBuilderEmptyWhenWrongEvent(): void {
    $product = $this->createOperationalProduct('operational_merchandise', 'operational_merchandise_var', 'ADDON-OTHER');
    $other = Node::create([
      'type' => 'event',
      'title' => 'Other event',
      'uid' => $this->event->getOwnerId(),
      'status' => 1,
    ]);
    $other->save();
    $product->set('field_event', ['target_id' => $other->id()]);
    $product->set('status', 1);
    $product->save();

    $builder = $this->eventOperationalAddonBuilder();
    $built = $builder->buildForEvent($this->event);
    $this->assertSame([], $built['addons']);
  }

  public function testEventOperationalAddonBuilderHasAddonsFalseWhenNoProducts(): void {
    $empty_event = Node::create([
      'type' => 'event',
      'title' => 'No merch',
      'uid' => $this->event->getOwnerId(),
      'status' => 1,
    ]);
    $empty_event->save();
    $builder = $this->eventOperationalAddonBuilder();
    $this->assertFalse($builder->hasAddons($empty_event));
  }

  protected function merchandiseManager(): OperationalMerchandiseManager {
    return new OperationalMerchandiseManager(
      $this->container->get('entity_type.manager'),
      $this->container->get('string_translation'),
      $this->container->get('logger.factory')->get('operational_merchandise_kernel'),
    );
  }

  protected function eventOperationalAddonBuilder(): EventOperationalAddonBuilder {
    return new EventOperationalAddonBuilder(
      $this->container->get('entity_type.manager'),
      $this->merchandiseManager(),
      new OperationalExtraVisualPresenter(
        $this->container->get('entity_type.manager'),
        $this->container->get('file_url_generator'),
        $this->container->get('extension.path.resolver'),
        $this->container->get('string_translation'),
      ),
      new OperationalVariationStockResolver($this->container->get('string_translation')),
      $this->container->get('string_translation'),
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

  /**
   * Lets Event Studio store resolution fall back to field_event_store in kernel tests.
   */
  protected function ensureKernelEventStoreReferenceForWizard(): void {
    if (!FieldStorageConfig::loadByName('node', 'field_event_store')) {
      FieldStorageConfig::create([
        'field_name' => 'field_event_store',
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'commerce_store'],
        'cardinality' => 1,
      ])->save();
    }
    if (!FieldConfig::loadByName('node', 'event', 'field_event_store')) {
      FieldConfig::create([
        'field_name' => 'field_event_store',
        'entity_type' => 'node',
        'bundle' => 'event',
        'label' => 'Event store',
      ])->save();
    }
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $reloaded = Node::load($this->event->id());
    $this->assertInstanceOf(Node::class, $reloaded);
    $this->event = $reloaded;
    $this->event->set('field_event_store', ['target_id' => $this->store->id()]);
    $this->event->save();
  }

  public function testVendorProductCreationWizardStripForbiddenFromPayloadRecursively(): void {
    $manager = $this->commerceWizardCreationManager();
    $out = $manager->stripForbiddenFromPayload([
      'title' => 'OK',
      'nested' => [
        'inventory_quantity' => 99,
        'child' => ['qr_payload' => 'x'],
      ],
    ]);
    $this->assertSame('OK', $out['title']);
    $this->assertArrayNotHasKey('inventory_quantity', $out['nested']);
    $this->assertArrayNotHasKey('qr_payload', $out['nested']['child']);
  }

  public function testVendorProductCreationWizardCreatesMerchandiseWithStoreAndPrice(): void {
    $owner = User::load($this->event->getOwnerId());
    $this->assertNotNull($owner);
    $this->container->get('current_user')->setAccount($owner);

    $manager = $this->commerceWizardCreationManager();
    $payload = [
      'productisation_type' => VendorProductisationStudioManager::TYPE_MERCHANDISE,
      'event_id' => (int) $this->event->id(),
      'title' => 'Kernel wizard tee',
      'customer_summary' => 'Pick up at desk',
      'price_amount' => '12.50',
      'currency_code' => 'AUD',
      'customer_visibility' => 'visible',
      'fulfillment_mode' => 'counter',
      'reservation_mode' => 'operational_projection',
      'pickup_mode' => 'counter',
    ];
    $created = $manager->createOperationalProductForEvent($owner, $this->event, $payload);
    $this->assertGreaterThan(0, $created['product_id']);
    $this->assertSame((int) $this->store->id(), $created['store_id']);
    $this->assertNotEmpty($created['variation_ids']);

    $product = Product::load($created['product_id']);
    $this->assertNotNull($product);
    $this->assertTrue($this->wizardProductHasStore($product, (int) $this->store->id()));
    $vars = $product->getVariations();
    $this->assertNotEmpty($vars);
    $this->assertSame('12.50', number_format((float) $vars[0]->getPrice()->getNumber(), 2, '.', ''));
  }

  public function testVendorProductCreationWizardCreatesHospitalityPackage(): void {
    $owner = User::load($this->event->getOwnerId());
    $this->assertNotNull($owner);
    $this->container->get('current_user')->setAccount($owner);

    $manager = $this->commerceWizardCreationManager();
    $payload = [
      'productisation_type' => VendorProductisationStudioManager::TYPE_HOSPITALITY_PACKAGE,
      'event_id' => (int) $this->event->id(),
      'title' => 'VIP lounge',
      'customer_summary' => 'Includes drinks',
      'price_amount' => '200.00',
      'currency_code' => 'AUD',
      'customer_visibility' => 'visible',
      'fulfillment_mode' => 'redeem',
      'reservation_mode' => 'operational_projection',
      'hospitality_benefits_summary' => 'Premium seating',
    ];
    $created = $manager->createOperationalProductForEvent($owner, $this->event, $payload);
    $product = Product::load($created['product_id']);
    $this->assertNotNull($product);
    $this->assertSame('hospitality_package', $product->bundle());
  }

  public function testVendorProductCreationWizardIdempotentExistingProductId(): void {
    $owner = User::load($this->event->getOwnerId());
    $this->assertNotNull($owner);
    $this->container->get('current_user')->setAccount($owner);

    $manager = $this->commerceWizardCreationManager();
    $payload = [
      'productisation_type' => VendorProductisationStudioManager::TYPE_MERCHANDISE,
      'event_id' => (int) $this->event->id(),
      'title' => 'Idempotent cap',
      'customer_summary' => 'One run',
      'price_amount' => '5.00',
      'currency_code' => 'AUD',
      'customer_visibility' => 'visible',
      'fulfillment_mode' => 'counter',
      'reservation_mode' => 'operational_projection',
    ];
    $first = $manager->createOperationalProductForEvent($owner, $this->event, $payload);
    $before = $this->commerceProductCountForWizard();

    $second = $manager->createOperationalProductForEvent($owner, $this->event, $payload + [
      'existing_product_id' => $first['product_id'],
    ]);
    $this->assertSame($first['product_id'], $second['product_id']);
    $this->assertSame($before, $this->commerceProductCountForWizard());
  }

  public function testVendorProductCreationWizardAnonymousDenied(): void {
    $this->container->get('current_user')->setAccount(User::getAnonymousUser());
    $manager = $this->commerceWizardCreationManager();
    $errors = $manager->validateCreationPayload(User::getAnonymousUser(), $this->event, [
      'productisation_type' => VendorProductisationStudioManager::TYPE_MERCHANDISE,
      'event_id' => (int) $this->event->id(),
      'title' => 'X',
      'customer_summary' => 'Y',
      'price_amount' => '1.00',
      'currency_code' => 'AUD',
    ]);
    $this->assertNotSame([], $errors);
  }

  public function testVendorProductCreationWizardFailsClosedWithoutResolvableStore(): void {
    $owner = User::load($this->event->getOwnerId());
    $this->assertNotNull($owner);
    $this->container->get('current_user')->setAccount($owner);

    $bare = Node::create([
      'type' => 'event',
      'title' => 'Kernel event without store linkage',
      'uid' => $owner->id(),
      'status' => 1,
    ]);
    $bare->save();

    $manager = $this->commerceWizardCreationManager();
    $payload = [
      'productisation_type' => VendorProductisationStudioManager::TYPE_MERCHANDISE,
      'event_id' => (int) $bare->id(),
      'title' => 'Should not create',
      'customer_summary' => 'No resolvable Commerce store',
      'price_amount' => '1.00',
      'currency_code' => 'AUD',
      'customer_visibility' => 'visible',
      'fulfillment_mode' => 'counter',
      'reservation_mode' => 'operational_projection',
    ];
    $this->expectException(\InvalidArgumentException::class);
    $manager->createOperationalProductForEvent($owner, $bare, $payload);
  }

  public function testVendorProductCreationWizardApplyMergesCommerceIntoItems(): void {
    $owner = User::load($this->event->getOwnerId());
    $this->assertNotNull($owner);
    $this->container->get('current_user')->setAccount($owner);

    $manager = $this->commerceWizardCreationManager();
    $studio = new VendorProductisationStudioManager(
      new OperationalMerchandiseManager(
        $this->container->get('entity_type.manager'),
        $this->container->get('string_translation'),
        $this->container->get('logger.factory')->get('test'),
      ),
      $this->commerceWizardLinkManager(),
    );
    $items = [];
    foreach (VendorProductisationStudioManager::PRODUCTISATION_TYPES as $t) {
      $items[] = $studio->emptyItem($t);
    }
    $rows = [];
    foreach (VendorProductisationStudioManager::PRODUCTISATION_TYPES as $t) {
      $rows[$t] = ['commerce_mode' => 'link'];
    }
    $rows[VendorProductisationStudioManager::TYPE_MERCHANDISE] = [
      'commerce_mode' => 'create',
      'wizard_create' => [],
      'title' => 'Walk-up hat',
      'customer_summary' => 'Branded',
      'customer_visibility' => 'visible',
      'pickup_mode' => 'counter',
      'commerce_create_price' => '9.99',
      'commerce_create_currency' => 'AUD',
    ];
    $out = $manager->applyProductisationWizardCreates($owner, $this->event, $items, $rows);
    $idx = array_search(VendorProductisationStudioManager::TYPE_MERCHANDISE, VendorProductisationStudioManager::PRODUCTISATION_TYPES, TRUE);
    $this->assertIsInt($idx);
    $this->assertGreaterThan(0, $out[$idx]['commerce']['product_id']);
  }

  private function commerceWizardCreationManager(): VendorOperationalProductCreationManager {
    $etm = $this->container->get('entity_type.manager');
    $merch = new OperationalMerchandiseManager(
      $etm,
      $this->container->get('string_translation'),
      $this->container->get('logger.factory')->get('test'),
    );
    return new VendorOperationalProductCreationManager(
      $etm,
      $merch,
      $this->commerceWizardLinkManager(),
      new EventVendorAccessChecker(),
      new OperationalVariationStockResolver($this->container->get('string_translation')),
      $this->container->get('string_translation'),
      $this->container->get('logger.factory')->get('test'),
    );
  }

  private function commerceWizardLinkManager(): OperationalCapabilityCommerceLinkManager {
    $registry = new EntitlementCapabilityRegistry();
    $logger = $this->container->get('logger.factory')->get('commerce_wizard_kernel');
    $reservation = new InventoryReservationGovernanceManager($registry, $logger);
    $oecm = new OperationalEntitlementCapabilityManager($registry, $reservation, $logger);
    $fulfillment = new FulfillmentLifecycleManager($registry);
    return new OperationalCapabilityCommerceLinkManager(
      $this->container->get('entity_type.manager'),
      $this->container->get('current_user'),
      $registry,
      $oecm,
      $fulfillment,
      $reservation,
    );
  }

  private function commerceProductCountForWizard(): int {
    $ids = $this->container->get('entity_type.manager')
      ->getStorage('commerce_product')
      ->getQuery()
      ->accessCheck(FALSE)
      ->execute();
    return count($ids ?: []);
  }

  private function wizardProductHasStore(object $product, int $store_id): bool {
    if (!$product->hasField('stores') || $product->get('stores')->isEmpty()) {
      return FALSE;
    }
    foreach ($product->get('stores')->getValue() as $item) {
      if ((int) ($item['target_id'] ?? 0) === $store_id) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
