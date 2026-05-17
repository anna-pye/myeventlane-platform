<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Commerce;

/**
 * Canonical Commerce product bundles for operational merchandise architecture.
 *
 * Shared by capacity, commerce, and event studio so bundle lists cannot diverge
 * across modules with different dependency directions.
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

}
