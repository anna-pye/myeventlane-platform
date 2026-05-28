<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Commerce;

/**
 * Canonical Commerce product bundles for operational merchandise architecture.
 *
 * Shared by capacity, commerce, and event studio so bundle lists cannot diverge
 * across modules with different dependency directions.
 *
 * Classification ids match
 * \Drupal\myeventlane_event\Value\EventCommercePurchasableType constants.
 */
final class OperationalProductBundles {

  /**
   * @var list<string>
   */
  public const BUNDLES = [
    'operational_merchandise',
    'operational_bundle',
    'hospitality_package',
    'timed_collection_product',
  ];

  public static function isOperationalProductBundle(string $bundle): bool {
    return in_array($bundle, self::BUNDLES, TRUE);
  }

  /**
   * Maps operational Commerce product bundles to canonical classifications.
   *
   * @return array<string, string>
   *   Commerce product bundle id => classification id (merchandise, addon, …).
   */
  public static function productBundleClassifications(): array {
    return [
      'operational_merchandise' => 'merchandise',
      // Grouped operational packages (parking bundles, VIP packages, etc.).
      'operational_bundle' => 'addon',
      'hospitality_package' => 'addon',
      'timed_collection_product' => 'addon',
    ];
  }

  /**
   * Returns the canonical classification for an operational product bundle.
   *
   * Returns an empty string when the bundle is not operational or unmapped.
   */
  public static function classificationForProductBundle(string $bundle): string {
    return self::productBundleClassifications()[$bundle] ?? '';
  }

}
