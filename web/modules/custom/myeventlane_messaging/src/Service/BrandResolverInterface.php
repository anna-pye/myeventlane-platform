<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Service;

use Drupal\myeventlane_messaging\ValueObject\Brand;
use Drupal\myeventlane_vendor\Entity\Vendor;

/**
 * Interface for resolving messaging brand settings.
 *
 * The resolver follows a canonical resolution order:
 * 1. If vendor_id provided → load vendor → brand from vendor fields
 * 2. Else if vendor entity provided → brand from vendor fields
 * 3. Else if event_id provided → load event → field_event_vendor → brand
 * 4. Else if order provided → resolve vendor via store/event → brand
 * 5. Else → MEL platform defaults
 */
interface BrandResolverInterface {

  /**
   * Resolves brand settings from a context array.
   *
   * @param array $context
   *   Context array with optional keys: vendor_id, vendor, event_id, event, order.
   *
   * @return \Drupal\myeventlane_messaging\ValueObject\Brand
   *   The resolved brand settings.
   */
  public function resolve(array $context): Brand;

  /**
   * Resolves brand settings for a specific vendor.
   *
   * @param \Drupal\myeventlane_vendor\Entity\Vendor $vendor
   *   The vendor entity.
   *
   * @return \Drupal\myeventlane_messaging\ValueObject\Brand
   *   The resolved brand settings.
   */
  public function resolveForVendor(Vendor $vendor): Brand;

  /**
   * Returns MEL platform default brand settings.
   *
   * @return \Drupal\myeventlane_messaging\ValueObject\Brand
   *   Default brand settings.
   */
  public function getDefaultBrand(): Brand;

}
