<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Resolves the active Pro subscription product variation.
 */
final class ProProductResolver {

  private const VARIATION_TYPE = 'mel_pro_subscription_variation';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns the configured Pro variation SKU.
   */
  public function getConfiguredSku(): string {
    $sku = (string) $this->configFactory
      ->get('myeventlane_pro.settings')
      ->get('pro_variation_sku');
    return trim($sku);
  }

  /**
   * Finds the first active Pro subscription product variation.
   *
   * Resolution order:
   * 1. If pro_variation_sku is set, load that SKU (case-insensitive fallback)
   *    when it is a sellable mel_pro_subscription_variation.
   * 2. Otherwise, or if step 1 fails, load the first sellable variation of
   *    bundle mel_pro_subscription_variation.
   */
  public function findActiveVariation(): ?ProductVariationInterface {
    $configuredSku = $this->getConfiguredSku();
    if ($configuredSku !== '') {
      $bySku = $this->loadVariationBySkuCaseInsensitive($configuredSku);
      if ($bySku instanceof ProductVariationInterface
        && $bySku->bundle() === self::VARIATION_TYPE
        && $this->isVariationSellable($bySku)) {
        return $bySku;
      }
    }

    return $this->findFirstSellableByProVariationType();
  }

  /**
   * Finds the sellable Pro variation for a first trial or paid restart.
   */
  public function findVariationForEligibility(bool $trialEligible): ?ProductVariationInterface {
    $scheduleId = $trialEligible
      ? ProBillingSchedule::TRIAL
      : ProBillingSchedule::RESTART;

    return $this->findSellableByBillingSchedule($scheduleId);
  }

  /**
   * Finds a sellable Pro variation assigned to a billing schedule.
   */
  public function findSellableByBillingSchedule(string $scheduleId): ?ProductVariationInterface {
    $storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::VARIATION_TYPE)
      ->condition('billing_schedule', $scheduleId)
      ->condition('status', 1)
      ->execute();

    foreach ($storage->loadMultiple($ids) as $variation) {
      if ($variation instanceof ProductVariationInterface && $this->isVariationSellable($variation)) {
        return $variation;
      }
    }

    return NULL;
  }

  /**
   * Loads a variation by SKU, trying exact match then common case variants.
   */
  private function loadVariationBySkuCaseInsensitive(string $sku): ?ProductVariationInterface {
    $storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $candidates = array_values(array_unique(array_filter([
      $sku,
      strtoupper($sku),
      strtolower($sku),
    ])));

    foreach ($candidates as $candidate) {
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('sku', $candidate)
        ->range(0, 1)
        ->execute();
      if ($ids === []) {
        continue;
      }
      $variation = $storage->load(reset($ids));
      if ($variation instanceof ProductVariationInterface) {
        return $variation;
      }
    }

    return NULL;
  }

  /**
   * Loads the first sellable variation of the Pro subscription variation type.
   */
  private function findFirstSellableByProVariationType(): ?ProductVariationInterface {
    $storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $idKey = $this->entityTypeManager->getDefinition('commerce_product_variation')->getKey('id');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::VARIATION_TYPE)
      ->condition('status', 1)
      ->sort($idKey, 'ASC')
      ->execute();

    foreach ($ids as $id) {
      $variation = $storage->load($id);
      if ($variation instanceof ProductVariationInterface && $this->isVariationSellable($variation)) {
        return $variation;
      }
    }

    return NULL;
  }

  /**
   * TRUE when the variation and its parent product are published/sellable.
   */
  private function isVariationSellable(ProductVariationInterface $variation): bool {
    if (!$this->isVariationPublished($variation)) {
      return FALSE;
    }
    $product = $variation->getProduct();
    if (!$product instanceof ProductInterface) {
      return FALSE;
    }
    if ($product instanceof EntityPublishedInterface) {
      return $product->isPublished();
    }
    if ($product->hasField('status')) {
      return (int) $product->get('status')->value === 1;
    }
    return TRUE;
  }

  /**
   * Determines whether a variation is published.
   */
  private function isVariationPublished(ProductVariationInterface $variation): bool {
    if ($variation instanceof EntityPublishedInterface) {
      return $variation->isPublished();
    }
    if ($variation->hasField('status')) {
      return (int) $variation->get('status')->value === 1;
    }
    return FALSE;
  }

  /**
   * Returns the formatted price string for the Pro variation.
   */
  public function getFormattedPrice(): ?string {
    $variation = $this->findActiveVariation();
    if (!$variation) {
      return NULL;
    }

    $price = $variation->getPrice();
    if (!$price) {
      return NULL;
    }

    return '$' . number_format((float) $price->getNumber(), 0);
  }

}
