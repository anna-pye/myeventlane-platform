<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
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
   */
  public function findActiveVariation(): ?ProductVariationInterface {
    $configuredSku = $this->getConfiguredSku();
    if ($configuredSku !== '') {
      $ids = $this->entityTypeManager->getStorage('commerce_product_variation')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('sku', $configuredSku)
        ->range(0, 1)
        ->execute();

      if ($ids !== []) {
        $variation = $this->entityTypeManager
          ->getStorage('commerce_product_variation')
          ->load(reset($ids));
        if ($variation instanceof ProductVariationInterface && $this->isVariationActive($variation)) {
          return $variation;
        }
      }

      // Explicit SKU configuration is authoritative.
      return NULL;
    }

    $ids = $this->entityTypeManager->getStorage('commerce_product_variation')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::VARIATION_TYPE)
      ->condition('status', 1)
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
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
    if (!$variation->hasField('status')) {
      return FALSE;
    }
    return (int) $variation->get('status')->value === 1;
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
