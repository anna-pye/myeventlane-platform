<?php

declare(strict_types=1);

namespace Drupal\myeventlane_dashboard\Service;

use Drupal\commerce_store\Entity\StoreInterface;

/**
 * Interface for vendor events service.
 */
interface VendorEventsServiceInterface {

  /**
   * Gets dashboard events for a store and date range.
   *
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The store.
   * @param array $range
   *   Date range with keys: start (timestamp), end (timestamp), label (string).
   *
   * @return array
   *   Events array with 'items', 'all_url', and 'cache_tags'.
   */
  public function getDashboardEvents(StoreInterface $store, array $range): array;

}
