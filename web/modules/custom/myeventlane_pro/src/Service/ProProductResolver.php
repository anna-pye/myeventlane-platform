<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

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
   * 1. If pro_variation_sku is set, load that SKU when it is a published
   *    variation of bundle mel_pro_subscription_variation.
   * 2. Otherwise, or if step 1 fails (stale/wrong SKU, wrong bundle, etc.),
   *    load the first published variation of bundle mel_pro_subscription_variation.
   */
  public function findActiveVariation(): ?ProductVariationInterface {
    $configuredSku = $this->getConfiguredSku();
    if ($configuredSku !== '') {
      $bySku = $this->loadVariationBySku($configuredSku);
      if ($bySku instanceof ProductVariationInterface
        && $bySku->bundle() === self::VARIATION_TYPE
        && $this->isVariationActive($bySku)) {
        return $bySku;
      }
    }

    return $this->findFirstPublishedByProVariationType();
  }

  /**
   * Loads a product variation by exact SKU (any bundle, any publish state).
   */
  private function loadVariationBySku(string $sku): ?ProductVariationInterface {
    $ids = $this->entityTypeManager->getStorage('commerce_product_variation')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('sku', $sku)
      ->range(0, 1)
      ->execute();

    if ($ids === []) {
      return NULL;
    }

    $variation = $this->entityTypeManager
      ->getStorage('commerce_product_variation')
      ->load(reset($ids));

    return $variation instanceof ProductVariationInterface ? $variation : NULL;
  }

  /**
   * Loads the first published variation of the Pro subscription variation type.
   */
  private function findFirstPublishedByProVariationType(): ?ProductVariationInterface {
    $idKey = $this->entityTypeManager->getDefinition('commerce_product_variation')->getKey('id');
    $ids = $this->entityTypeManager->getStorage('commerce_product_variation')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::VARIATION_TYPE)
      ->condition('status', 1)
      ->sort($idKey, 'ASC')
      ->range(0, 1)
      ->execute();

    if ($ids === []) {
      return NULL;
    }

    $variation = $this->entityTypeManager
      ->getStorage('commerce_product_variation')
      ->load(reset($ids));

    return $variation instanceof ProductVariationInterface ? $variation : NULL;
  }

  /**
   * Determines whether a variation is active/published.
   */
  private function isVariationActive(ProductVariationInterface $variation): bool {
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
