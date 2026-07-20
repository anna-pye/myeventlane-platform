<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_order\Entity\OrderInterface;
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
   * Order item types that are organiser donations.
   */
  private const ORGANISER_DONATION_TYPES = [
    'checkout_donation',
    'rsvp_donation',
  ];

  /**
   * Order item types that are platform donations.
   */
  private const PLATFORM_DONATION_TYPES = [
    'platform_donation',
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
   * Product variation type for ticket revenue.
   */
  private const TICKET_VARIATION_TYPE = 'ticket_variation';

  /**
   * Product variation type for MEL Pro.
   */
  private const MEL_PRO_VARIATION_TYPE = 'mel_pro_subscription_variation';

  /**
   * Order types that must never create payout ledger liabilities.
   */
  private const PAYOUT_LEDGER_EXCLUDED_ORDER_TYPES = [
    'platform_donation',
    'rsvp_donation',
    'recurring',
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

  /**
   * Returns organiser donation order item type IDs.
   *
   * @return array<string>
   *   List of organiser donation order item type IDs.
   */
  public function getOrganiserDonationTypes(): array {
    return self::ORGANISER_DONATION_TYPES;
  }

  /**
   * Returns platform donation order item type IDs.
   *
   * @return array<string>
   *   List of platform donation order item type IDs.
   */
  public function getPlatformDonationTypes(): array {
    return self::PLATFORM_DONATION_TYPES;
  }

  /**
   * Checks if an order item is an organiser donation.
   */
  public function isOrganiserDonation(OrderItemInterface $item): bool {
    return in_array($item->bundle(), self::ORGANISER_DONATION_TYPES, TRUE);
  }

  /**
   * Checks if an order item is a platform donation.
   */
  public function isPlatformDonation(OrderItemInterface $item): bool {
    return in_array($item->bundle(), self::PLATFORM_DONATION_TYPES, TRUE);
  }

  /**
   * Checks if an order item is MEL Pro subscription inventory.
   */
  public function isMelPro(OrderItemInterface $item): bool {
    $purchasedEntity = $item->getPurchasedEntity();
    if (!$purchasedEntity) {
      return FALSE;
    }
    return $purchasedEntity->getEntityTypeId() === 'commerce_product_variation'
      && $purchasedEntity->bundle() === self::MEL_PRO_VARIATION_TYPE;
  }

  /**
   * Checks if an order item is ticket variation revenue.
   */
  public function isTicketRevenue(OrderItemInterface $item): bool {
    if ($this->isBoost($item) || $this->isDonation($item) || $this->isMelPro($item)) {
      return FALSE;
    }
    $purchasedEntity = $item->getPurchasedEntity();
    if (!$purchasedEntity) {
      return FALSE;
    }
    return $purchasedEntity->getEntityTypeId() === 'commerce_product_variation'
      && $purchasedEntity->bundle() === self::TICKET_VARIATION_TYPE;
  }

  /**
   * Whether a line item may justify a vendor payout ledger row.
   *
   * Launch rules (CF-007 remediation):
   * - Ticket revenue (`ticket_variation`)
   * - Organiser donation line (`checkout_donation`)
   *
   * Explicitly not eligible: Boost, platform donation, RSVP donation lines,
   * MEL Pro, fees/adjustments (not order items).
   */
  public function isPayoutLedgerEligibleItem(OrderItemInterface $item): bool {
    if ($this->isBoost($item) || $this->isPlatformDonation($item) || $this->isMelPro($item)) {
      return FALSE;
    }
    // RSVP donation lines are organiser-labelled historically but belong to
    // non-vendor order types excluded at order level; do not allow alone.
    if ($item->bundle() === 'rsvp_donation') {
      return FALSE;
    }
    if ($item->bundle() === 'checkout_donation') {
      return TRUE;
    }
    return $this->isTicketRevenue($item);
  }

  /**
   * Whether an order may receive a payout ledger liability row.
   */
  public function isPayoutLedgerEligibleOrder(OrderInterface $order): bool {
    if (in_array($order->bundle(), self::PAYOUT_LEDGER_EXCLUDED_ORDER_TYPES, TRUE)) {
      return FALSE;
    }
    foreach ($order->getItems() as $item) {
      if ($this->isPayoutLedgerEligibleItem($item)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether the order should use the recurring Payment Element gateway.
   */
  public function requiresRecurringPaymentGateway(OrderInterface $order): bool {
    if ($order->bundle() === 'recurring') {
      return TRUE;
    }
    foreach ($order->getItems() as $item) {
      if ($this->isMelPro($item)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
