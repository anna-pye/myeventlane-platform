<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Service;

use Drupal\commerce_order\Entity\OrderItemInterface;

/**
 * Canonical order item classifier for analytics and revenue attribution.
 *
 * Centralizes exclusion rules for donation and Boost order items.
 * Donations and Boost are platform revenue; ticket items are vendor revenue.
 *
 * Canonical exclusion list (not vendor revenue eligible):
 * - boost
 * - checkout_donation
 * - platform_donation
 * - rsvp_donation
 */
final class OrderItemClassifier {

  /**
   * Order item types that are Boost purchases (platform revenue only).
   */
  private const BOOST_TYPE = 'boost';

  /**
   * Order item types that are donations (platform revenue only).
   */
  private const DONATION_TYPES = [
    'checkout_donation',
    'platform_donation',
    'rsvp_donation',
  ];

  /**
   * All order item types excluded from vendor revenue and ticket counts.
   */
  private const EXCLUDED_TYPES = [
    'boost',
    'checkout_donation',
    'platform_donation',
    'rsvp_donation',
  ];

  /**
   * Checks if an order item is a Boost purchase.
   *
   * Boost purchases are platform revenue and must be excluded from vendor
   * metrics.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $item
   *   The order item.
   *
   * @return bool
   *   TRUE if the item is a Boost purchase, FALSE otherwise.
   */
  public function isBoost(OrderItemInterface $item): bool {
    if ($item->bundle() === self::BOOST_TYPE) {
      return TRUE;
    }

    $purchasedEntity = $item->getPurchasedEntity();
    if (!$purchasedEntity) {
      return FALSE;
    }

    $product = $purchasedEntity->getProduct();
    if ($product && $product->bundle() === 'boost_upgrade') {
      return TRUE;
    }
    if ($purchasedEntity->bundle() === 'boost_duration') {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Checks if an order item is a donation.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $item
   *   The order item.
   *
   * @return bool
   *   TRUE if the item is a donation, FALSE otherwise.
   */
  public function isDonation(OrderItemInterface $item): bool {
    return in_array($item->bundle(), self::DONATION_TYPES, TRUE);
  }

  /**
   * Checks if an order item is vendor revenue eligible.
   *
   * Vendor revenue eligible = NOT boost AND NOT donation.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $item
   *   The order item.
   *
   * @return bool
   *   TRUE if the item counts toward vendor revenue/tickets, FALSE otherwise.
   */
  public function isVendorRevenueEligible(OrderItemInterface $item): bool {
    return !$this->isBoost($item) && !$this->isDonation($item);
  }

  /**
   * Returns order item types excluded from vendor revenue and ticket counts.
   *
   * Use for SQL conditions: oi.type NOT IN (getExcludedTypes()).
   *
   * @return array<string>
   *   List of order item type (bundle) IDs.
   */
  public function getExcludedTypes(): array {
    return self::EXCLUDED_TYPES;
  }

  /**
   * Returns order item types that are donations (platform revenue).
   *
   * @return array<string>
   *   List of donation type IDs.
   */
  public function getDonationTypes(): array {
    return self::DONATION_TYPES;
  }

}
