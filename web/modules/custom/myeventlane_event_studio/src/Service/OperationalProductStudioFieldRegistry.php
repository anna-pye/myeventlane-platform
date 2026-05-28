<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\myeventlane_commerce\Service\OperationalMerchandiseManager;

/**
 * Read-only registry of operational Commerce field support for Studio UI.
 */
final class OperationalProductStudioFieldRegistry {

  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * @return array<string, bool>
   *   Keys: images, short_description, pickup_note, operational_json, event, status.
   */
  public function productFieldSupport(string $product_bundle): array {
    $defs = $this->entityFieldManager->getFieldDefinitions('commerce_product', $product_bundle);
    return [
      'images' => isset($defs['field_mel_extra_images']),
      'short_description' => isset($defs['field_mel_extra_short_desc']),
      'pickup_note' => isset($defs['field_mel_extra_pickup_note']),
      'operational_json' => isset($defs['field_mel_operational_product']),
      'event' => isset($defs['field_event']),
      'status' => isset($defs['status']),
      'stores' => isset($defs['stores']),
    ];
  }

  /**
   * @return array<string, bool>
   *   Keys: size, stock_quantity, limit_per_order, status.
   */
  public function variationFieldSupport(string $variation_bundle): array {
    $defs = $this->entityFieldManager->getFieldDefinitions('commerce_product_variation', $variation_bundle);
    return [
      'size' => isset($defs['field_mel_size']),
      'stock_quantity' => isset($defs['field_stock']) || isset($defs['commerce_stock']),
      'limit_per_order' => isset($defs['field_limit_per_order']),
      'status' => isset($defs['status']),
      'price' => isset($defs['price']),
      'sku' => isset($defs['sku']),
    ];
  }

  /**
   * Whether any operational product bundle exposes gallery images.
   */
  public function operationalProductsSupportImages(): bool {
    foreach (OperationalMerchandiseManager::OPERATIONAL_PRODUCT_BUNDLES as $bundle) {
      if ($this->productFieldSupport($bundle)['images']) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether any operational variation bundle exposes enforced stock.
   */
  public function operationalVariationsSupportStock(): bool {
    foreach ($this->operationalVariationBundles() as $variation_bundle) {
      if ($this->variationFieldSupport($variation_bundle)['stock_quantity']) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @return list<string>
   */
  public function operationalVariationBundles(): array {
    return [
      'operational_merchandise_var',
      'hospitality_package_var',
      'timed_collection_var',
      'operational_bundle_var',
    ];
  }

  /**
   * Maps a Commerce product bundle to its default variation bundle id.
   */
  public function variationBundleForProductBundle(string $product_bundle): string {
    return match ($product_bundle) {
      'hospitality_package' => 'hospitality_package_var',
      'timed_collection_product' => 'timed_collection_var',
      'operational_bundle' => 'operational_bundle_var',
      default => 'operational_merchandise_var',
    };
  }

}
