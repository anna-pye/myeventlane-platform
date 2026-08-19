<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductType;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationType;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_recurring\Entity\BillingSchedule;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Creates the default MEL Pro subscription product when catalog is missing.
 *
 * Safe to run repeatedly: no-op when a sellable Pro variation already exists.
 */
final class ProSubscriptionCatalogEnsurer {

  private const PRODUCT_TYPE = 'mel_pro_subscription';

  private const VARIATION_TYPE = 'mel_pro_subscription_variation';

  private const DEFAULT_SKU = 'MEL-Pro';

  private const RESTART_SKU_SUFFIX = '-restart';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ProProductResolver $productResolver,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Ensures at least one sellable Pro subscription variation exists.
   *
   * @return bool
   *   TRUE on success or if catalog was already OK.
   */
  public function ensureDefaultCatalog(): bool {
    $trialVariation = $this->productResolver
      ->findSellableByBillingSchedule(ProBillingSchedule::TRIAL);
    if ($trialVariation !== NULL) {
      return $this->ensureRestartVariation($trialVariation);
    }

    if (!ProductType::load(self::PRODUCT_TYPE)) {
      $this->logger->error('Commerce product type @type is missing. Import configuration (commerce_product).', [
        '@type' => self::PRODUCT_TYPE,
      ]);
      return FALSE;
    }

    if (!ProductVariationType::load(self::VARIATION_TYPE)) {
      $this->logger->error('Commerce variation type @type is missing. Import configuration (commerce_product).', [
        '@type' => self::VARIATION_TYPE,
      ]);
      return FALSE;
    }

    $billingSchedule = BillingSchedule::load(ProBillingSchedule::TRIAL);
    if ($billingSchedule === NULL) {
      $this->logger->error('Billing schedule @id is missing. Import commerce_recurring configuration.', [
        '@id' => ProBillingSchedule::TRIAL,
      ]);
      return FALSE;
    }

    $store = $this->loadDefaultStore();
    if (!$store instanceof StoreInterface) {
      $this->logger->error('No Commerce store found. Create a store before running this.');
      return FALSE;
    }

    $currency = $store->getDefaultCurrencyCode();
    if ($currency === '') {
      $this->logger->error('Default store @id has no default currency.', ['@id' => (string) $store->id()]);
      return FALSE;
    }

    $configuredSku = $this->productResolver->getConfiguredSku();
    $sku = $this->pickUniqueSku($configuredSku !== '' ? $configuredSku : self::DEFAULT_SKU);

    $proPrice = $this->configFactory->get('myeventlane_pro.settings')->get('pro_price');
    $priceNumber = number_format((float) ($proPrice ?? 49), 2, '.', '');

    try {
      $product = Product::create([
        'type' => self::PRODUCT_TYPE,
        'title' => 'MEL Pro',
        'status' => 1,
        'stores' => [$store->id()],
      ]);
      $product->save();

      $variation = ProductVariation::create([
        'type' => self::VARIATION_TYPE,
        'sku' => $sku,
        'title' => 'MEL Pro Monthly',
        'status' => 1,
        'price' => [
          'number' => $priceNumber,
          'currency_code' => $currency,
        ],
        'billing_schedule' => $billingSchedule,
        'subscription_type' => [
          'target_plugin_id' => 'product_variation',
        ],
      ]);
      $variation->save();

      $product->addVariation($variation);
      $product->save();

      $editable = $this->configFactory->getEditable('myeventlane_pro.settings');
      if (trim((string) $editable->get('pro_variation_sku')) === '') {
        $editable->set('pro_variation_sku', $sku)->save();
      }

      $this->logger->notice(
        'Created MEL Pro product @pid with variation @vid (SKU @sku, @price @currency).',
        [
          '@pid' => (string) $product->id(),
          '@vid' => (string) $variation->id(),
          '@sku' => $sku,
          '@price' => $priceNumber,
          '@currency' => $currency,
        ],
      );

      return $this->ensureRestartVariation($variation);
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to create MEL Pro catalog: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Ensures a paid-from-today sibling variation exists for returning users.
   */
  public function ensureRestartVariation(ProductVariationInterface $trialVariation): bool {
    if ($this->productResolver->findVariationForEligibility(FALSE) !== NULL) {
      return TRUE;
    }

    $billingSchedule = BillingSchedule::load(ProBillingSchedule::RESTART);
    if ($billingSchedule === NULL) {
      $this->logger->error('Restart billing schedule @id is missing.', [
        '@id' => ProBillingSchedule::RESTART,
      ]);
      return FALSE;
    }

    $product = $trialVariation->getProduct();
    $price = $trialVariation->getPrice();
    if ($product === NULL || $price === NULL) {
      return FALSE;
    }

    try {
      $variation = ProductVariation::create([
        'type' => self::VARIATION_TYPE,
        'sku' => $this->pickUniqueSku($trialVariation->getSku() . self::RESTART_SKU_SUFFIX),
        'title' => 'MEL Pro Monthly Restart',
        'status' => 1,
        'price' => $price,
        'billing_schedule' => $billingSchedule,
        'subscription_type' => [
          'target_plugin_id' => 'product_variation',
        ],
      ]);
      $variation->save();
      $product->addVariation($variation);
      $product->save();
      return TRUE;
    }
    catch (\Throwable $exception) {
      $this->logger->error('Failed to create MEL Pro restart variation: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Loads the default Commerce store, falling back to the first store.
   */
  private function loadDefaultStore(): ?StoreInterface {
    $storage = $this->entityTypeManager->getStorage('commerce_store');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('is_default', 1)
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->range(0, 1)
        ->execute();
    }
    if ($ids === []) {
      return NULL;
    }
    $store = $storage->load(reset($ids));
    return $store instanceof StoreInterface ? $store : NULL;
  }

  /**
   * Returns an available SKU derived from the provided base value.
   */
  private function pickUniqueSku(string $baseSku): string {
    $baseSku = trim($baseSku) !== '' ? trim($baseSku) : self::DEFAULT_SKU;
    $sku = $baseSku;
    $suffix = 0;
    $storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    while ($this->variationSkuInUse($storage, $sku)) {
      $suffix++;
      $sku = $baseSku . '-' . $suffix;
    }
    return $sku;
  }

  /**
   * Checks whether a product variation already uses the SKU.
   */
  private function variationSkuInUse(EntityStorageInterface $storage, string $sku): bool {
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('sku', $sku)
      ->range(0, 1)
      ->execute();
    return $ids !== [];
  }

}
