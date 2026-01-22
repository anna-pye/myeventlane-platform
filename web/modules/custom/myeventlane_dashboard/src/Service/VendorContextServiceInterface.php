<?php

declare(strict_types=1);

namespace Drupal\myeventlane_dashboard\Service;

use Drupal\commerce_store\Entity\StoreInterface;

/**
 * Interface for vendor context service.
 */
interface VendorContextServiceInterface {

  /**
   * Gets the current vendor's store.
   *
   * @return \Drupal\commerce_store\Entity\StoreInterface
   *   The vendor store.
   *
   * @throws \Drupal\Core\Access\AccessDeniedHttpException
   *   If no store is found or access is denied.
   */
  public function getCurrentVendorStore(): StoreInterface;

  /**
   * Gets the vendor display name for a store.
   *
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The store.
   *
   * @return string
   *   The display name.
   */
  public function getVendorDisplayName(StoreInterface $store): string;

}
