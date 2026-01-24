<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\commerce_order\Entity\OrderItemInterface;

/**
 * Resolves ticket labels from order items.
 *
 * Always uses the product variation title, never the product title.
 * This ensures distinct ticket types are displayed correctly.
 */
final class TicketLabelResolver {

  /**
   * Gets the ticket label for an order item.
   *
   * Uses the purchased entity (variation) title when available.
   * Falls back to order item title only if variation is unavailable.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $orderItem
   *   The order item.
   *
   * @return string
   *   The ticket label (variation title).
   */
  public function getTicketLabel(OrderItemInterface $orderItem): string {
    $purchasedEntity = $orderItem->getPurchasedEntity();

    // Always prefer variation title over product title.
    if ($purchasedEntity) {
      return $purchasedEntity->label();
    }

    // Fallback to order item title if variation is unavailable.
    return $orderItem->label();
  }

  /**
   * Gets the ticket label for a product variation.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The product variation.
   *
   * @return string
   *   The variation title.
   */
  public function getTicketLabelFromVariation($variation): string {
    if (!$variation) {
      return '';
    }
    return $variation->label();
  }

}
