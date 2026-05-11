<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\myeventlane_event\Value\EventCommercePurchasableType;

/**
 * Resolves event-commerce classification metadata without changing storage.
 */
final class EventCommerceClassificationRegistry {

  /**
   * Returns all canonical purchasable classification definitions.
   *
   * @return array<string, array<string, mixed>>
   */
  public function definitions(): array {
    return EventCommercePurchasableType::definitions();
  }

  /**
   * Returns metadata for a canonical purchasable classification.
   *
   * @return array<string, mixed>
   */
  public function definition(string $classification): array {
    return EventCommercePurchasableType::definition($classification);
  }

  /**
   * Returns TRUE when the supplied classification is canonical.
   */
  public function isKnown(string $classification): bool {
    return EventCommercePurchasableType::isValid($classification);
  }

  /**
   * Normalizes a supplied classification id.
   */
  public function normalize(string $classification): string {
    $classification = trim($classification);
    return $this->isKnown($classification) ? $classification : '';
  }

  /**
   * Classifies a Commerce product from an explicit bundle map.
   *
   * @param array<string, string> $product_bundle_classifications
   *   Map of Commerce product bundle id to canonical classification id.
   */
  public function classifyProduct(ProductInterface $product, array $product_bundle_classifications = []): string {
    return $this->normalize((string) ($product_bundle_classifications[$product->bundle()] ?? ''));
  }

  /**
   * Classifies a purchasable variation from explicit bundle maps.
   *
   * @param array<string, string> $variation_bundle_classifications
   *   Map of Commerce variation bundle id to canonical classification id.
   * @param array<string, string> $product_bundle_classifications
   *   Map of Commerce product bundle id to canonical classification id.
   */
  public function classifyPurchasable(
    ProductVariationInterface $variation,
    array $variation_bundle_classifications = [],
    array $product_bundle_classifications = [],
  ): string {
    $classification = $this->normalize((string) ($variation_bundle_classifications[$variation->bundle()] ?? ''));
    if ($classification !== '') {
      return $classification;
    }

    $product = method_exists($variation, 'getProduct') ? $variation->getProduct() : NULL;
    return $product instanceof ProductInterface
      ? $this->classifyProduct($product, $product_bundle_classifications)
      : '';
  }

}
