<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_order\Entity\OrderItemInterface;

/**
 * Canonical order item classifier for Commerce revenue and checkout logic.
 */
class OrderItemClassifier {

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
   */
  public function isDonation(OrderItemInterface $item): bool {
    return in_array($item->bundle(), self::DONATION_TYPES, TRUE);
  }

  /**
   * Checks if an order item is vendor revenue eligible.
   */
  public function isVendorRevenueEligible(OrderItemInterface $item): bool {
    return !$this->isBoost($item) && !$this->isDonation($item);
  }

  /**
   * Returns order item types excluded from vendor revenue and ticket counts.
   *
   * @return array<string>
   *   List of order item type (bundle) IDs.
   */
  public function getExcludedTypes(): array {
    return self::EXCLUDED_TYPES;
  }

  /**
   * Returns donation order item type IDs.
   *
   * @return array<string>
   *   List of donation order item type IDs.
   */
  public function getDonationTypes(): array {
    return self::DONATION_TYPES;
  }

}
