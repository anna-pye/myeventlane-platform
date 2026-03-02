<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Service;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves published boost variations for purchase flows.
 */
final class BoostVariationResolver {

  private const PRODUCT_TYPE = 'boost_upgrade';

  private const PREFERRED_PRODUCT_LABEL = 'Event Boost';

  private const FIELD_BOOST_DAYS = 'field_boost_days';

  /**
   * Constructs a BoostVariationResolver service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The module logger.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns valid, published boost variations keyed by variation ID.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface[]
   *   A list of valid boost variations.
   */
  public function resolvePublishedVariations(): array {
    $productStorage = $this->entityTypeManager->getStorage('commerce_product');
    $productIds = $productStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::PRODUCT_TYPE)
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->execute();

    if (empty($productIds)) {
      $this->logger->debug('No published boost products found while resolving variations.');
      return [];
    }

    $products = $productStorage->loadMultiple($productIds);
    $product = $this->pickPreferredProduct($products);
    if (!$product instanceof ProductInterface) {
      return [];
    }

    $valid = [];
    foreach ($product->getVariations() as $variation) {
      if (!$variation instanceof ProductVariationInterface) {
        continue;
      }
      if (!$this->isValidVariation($variation)) {
        continue;
      }
      $valid[(int) $variation->id()] = $variation;
    }

    return $valid;
  }

  /**
   * Selects preferred product from candidates.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface[] $products
   *   Candidate products.
   *
   * @return \Drupal\commerce_product\Entity\ProductInterface|null
   *   Selected product.
   */
  private function pickPreferredProduct(array $products): ?ProductInterface {
    foreach ($products as $product) {
      if ($product instanceof ProductInterface && $product->label() === self::PREFERRED_PRODUCT_LABEL) {
        return $product;
      }
    }

    foreach ($products as $product) {
      if ($product instanceof ProductInterface) {
        return $product;
      }
    }

    $this->logger->debug('No valid boost product entity found in resolver candidates.');
    return NULL;
  }

  /**
   * Validates whether a variation can be purchased as a boost option.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The variation.
   *
   * @return bool
   *   TRUE when valid for display and purchase.
   */
  private function isValidVariation(ProductVariationInterface $variation): bool {
    if (!$variation->isPublished()) {
      return FALSE;
    }

    if (!$variation->hasField(self::FIELD_BOOST_DAYS)) {
      return FALSE;
    }

    $days = (int) ($variation->get(self::FIELD_BOOST_DAYS)->value ?? 0);
    if ($days <= 0) {
      return FALSE;
    }

    return $variation->getPrice() !== NULL;
  }

}
