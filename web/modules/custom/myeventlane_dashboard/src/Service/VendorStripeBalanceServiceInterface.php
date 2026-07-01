<?php

declare(strict_types=1);

namespace Drupal\myeventlane_dashboard\Service;

use Drupal\commerce_store\Entity\StoreInterface;

/**
 * Interface for vendor Stripe available balance display on the dashboard.
 */
interface VendorStripeBalanceServiceInterface {

  /**
   * Gets available Stripe balance formatted as currency string.
   *
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The store.
   *
   * @return string
   *   Formatted balance (e.g. "$1,234.56").
   */
  public function getAvailableBalanceFormatted(StoreInterface $store): string;

}
