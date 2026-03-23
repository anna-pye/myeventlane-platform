<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_pro\Kernel;

use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_price\Entity\Currency;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductType;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_product\Entity\ProductVariationType;
use Drupal\commerce_store\Entity\Store;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_pro\Service\ProProductResolver;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel tests for Pro product variation resolution.
 *
 * @group myeventlane_pro
 */
#[RunTestsInSeparateProcesses]
final class ProProductResolverTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'options',
    'address',
    'path',
    'path_alias',
    'entity_reference_revisions',
    'profile',
    'commerce',
    'commerce_price',
    'commerce_store',
    'commerce_order',
    'commerce_product',
    'state_machine',
  ];

  /**
   * Shared store used for product fixtures.
   */
  private Store $store;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('commerce_currency');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('commerce_store');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installEntitySchema('commerce_product');
    $this->installEntitySchema('commerce_product_variation');

    $this->ensureCommerceBaseEntities();
    $this->store = $this->createStore();
  }

  /**
   * Resolver returns configured SKU when variation is active.
   */
  public function testResolverUsesConfiguredActiveSku(): void {
    $this->ensureCatalogTypes('mel_pro_subscription_variation', 'mel_pro_subscription');
    $expected = $this->createVariation(
      variationType: 'mel_pro_subscription_variation',
      productType: 'mel_pro_subscription',
      sku: 'PRO-SKU-ACTIVE',
      active: TRUE,
    );

    $resolver = $this->getResolver('PRO-SKU-ACTIVE');
    $resolved = $resolver->findActiveVariation();

    $this->assertInstanceOf(ProductVariationInterface::class, $resolved);
    $this->assertSame((int) $expected->id(), (int) $resolved->id());
  }

  /**
   * Resolver returns NULL when configured SKU maps to inactive variation.
   */
  public function testResolverReturnsNullForConfiguredInactiveSku(): void {
    $this->ensureCatalogTypes('mel_pro_subscription_variation', 'mel_pro_subscription');
    $this->createVariation(
      variationType: 'mel_pro_subscription_variation',
      productType: 'mel_pro_subscription',
      sku: 'PRO-SKU-INACTIVE',
      active: FALSE,
    );

    $resolver = $this->getResolver('PRO-SKU-INACTIVE');
    $this->assertNull($resolver->findActiveVariation());
  }

  /**
   * Resolver falls back to legacy type query when SKU is not configured.
   */
  public function testResolverFallsBackToLegacyVariationType(): void {
    $this->ensureCatalogTypes('mel_pro_subscription_variation', 'mel_pro_subscription');
    $expected = $this->createVariation(
      variationType: 'mel_pro_subscription_variation',
      productType: 'mel_pro_subscription',
      sku: 'PRO-SKU-FALLBACK',
      active: TRUE,
    );

    $resolver = $this->getResolver('');
    $resolved = $resolver->findActiveVariation();

    $this->assertInstanceOf(ProductVariationInterface::class, $resolved);
    $this->assertSame((int) $expected->id(), (int) $resolved->id());
  }

  /**
   * Wrong configured SKU still resolves a published Pro variation by type.
   */
  public function testResolverResolvesSkuWithDifferentCasing(): void {
    $this->ensureCatalogTypes('mel_pro_subscription_variation', 'mel_pro_subscription');
    $expected = $this->createVariation(
      variationType: 'mel_pro_subscription_variation',
      productType: 'mel_pro_subscription',
      sku: 'MEL-PRO-UPPER',
      active: TRUE,
    );

    $resolver = $this->getResolver('mel-pro-upper');
    $resolved = $resolver->findActiveVariation();

    $this->assertInstanceOf(ProductVariationInterface::class, $resolved);
    $this->assertSame((int) $expected->id(), (int) $resolved->id());
  }

  /**
   * Wrong configured SKU still resolves a published Pro variation by type.
   */
  public function testResolverFallsBackWhenConfiguredSkuDoesNotMatch(): void {
    $this->ensureCatalogTypes('mel_pro_subscription_variation', 'mel_pro_subscription');
    $expected = $this->createVariation(
      variationType: 'mel_pro_subscription_variation',
      productType: 'mel_pro_subscription',
      sku: 'PRO-SKU-ACTUAL',
      active: TRUE,
    );

    $resolver = $this->getResolver('WRONG-SKU-FROM-CONFIG');
    $resolved = $resolver->findActiveVariation();

    $this->assertInstanceOf(ProductVariationInterface::class, $resolved);
    $this->assertSame((int) $expected->id(), (int) $resolved->id());
  }

  /**
   * Creates required baseline commerce entities.
   */
  private function ensureCommerceBaseEntities(): void {
    if (!Currency::load('AUD')) {
      Currency::create([
        'currencyCode' => 'AUD',
        'name' => 'Australian Dollar',
        'numericCode' => '036',
        'symbol' => '$',
        'fractionDigits' => 2,
      ])->save();
    }

    $storeTypeStorage = $this->container->get('entity_type.manager')->getStorage('commerce_store_type');
    if (!$storeTypeStorage->load('online')) {
      $storeTypeStorage->create([
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
   * Ensures product and variation types for fixture creation.
   */
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

  /**
   * Creates a store fixture.
   */
  private function createStore(): Store {
    $owner = User::create([
      'name' => 'pro_resolver_owner',
      'mail' => 'pro-resolver-owner@example.test',
      'status' => 1,
    ]);
    $owner->save();

    $store = Store::create([
      'type' => 'online',
      'uid' => $owner->id(),
      'name' => 'Resolver Store',
      'mail' => 'resolver-store@example.test',
      'default_currency' => 'AUD',
      'status' => TRUE,
      'is_default' => TRUE,
    ]);
    $store->save();
    return $store;
  }

  /**
   * Creates a variation and attaches it to a product.
   */
  private function createVariation(string $variationType, string $productType, string $sku, bool $active): ProductVariation {
    $product = Product::create([
      'type' => $productType,
      'title' => $sku . ' product',
      'status' => 1,
      'stores' => [$this->store->id()],
    ]);
    $product->save();

    $variation = ProductVariation::create([
      'type' => $variationType,
      'sku' => $sku,
      'title' => $sku . ' variation',
      'status' => $active ? 1 : 0,
      'price' => new Price('49.00', 'AUD'),
    ]);
    $variation->save();

    $product->addVariation($variation);
    $product->save();

    return $variation;
  }

  /**
   * Returns the resolver service under test.
   */
  private function getResolver(string $configuredSku): ProProductResolver {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('pro_variation_sku')
      ->willReturn($configuredSku);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('myeventlane_pro.settings')
      ->willReturn($config);

    return new ProProductResolver(
      $this->container->get('entity_type.manager'),
      $configFactory,
    );
  }

}
