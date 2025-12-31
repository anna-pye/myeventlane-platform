<?php

declare(strict_types=1);

namespace Drupal\myeventlane_capacity\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;

/**
 * Inspects orders to extract event capacity requirements.
 *
 * Builds a map of event_id => requested_quantity from order items,
 * excluding non-ticket items (e.g., donations).
 */
final class CapacityOrderInspector {

  /**
   * Extracts event capacity requirements from an order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order to inspect.
   *
   * @return array<int, int>
   *   Map of event_id => requested_quantity. Empty array if no ticket items.
   */
  public function extractEventQuantities(OrderInterface $order): array {
    $event_quantities = [];

    foreach ($order->getItems() as $item) {
      // Skip non-ticket items (donations, boosts, etc.).
      if ($this->isNonTicketItem($item)) {
        continue;
      }

      // Only process items with field_target_event set.
      if (!$item->hasField('field_target_event') || $item->get('field_target_event')->isEmpty()) {
        continue;
      }

      $event_id = (int) $item->get('field_target_event')->target_id;
      if ($event_id <= 0) {
        continue;
      }

      $quantity = (int) $item->getQuantity();
      if ($quantity <= 0) {
        continue;
      }

      // Aggregate quantities per event.
      if (!isset($event_quantities[$event_id])) {
        $event_quantities[$event_id] = 0;
      }
      $event_quantities[$event_id] += $quantity;
    }

    return $event_quantities;
  }

  /**
   * Checks if an order item is a non-ticket item (should be excluded).
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $item
   *   The order item.
   *
   * @return bool
   *   TRUE if the item should be excluded from capacity checks.
   */
  public function isNonTicketItem(OrderItemInterface $item): bool {
    $bundle = $item->bundle();

    // Exclude donation order items.
    if (in_array($bundle, ['checkout_donation', 'platform_donation', 'rsvp_donation'], TRUE)) {
      return TRUE;
    }

    // Exclude boost items.
    if ($bundle === 'boost') {
      return TRUE;
    }

    // All other items are considered tickets and subject to capacity checks.
    return FALSE;
  }

  /**
   * Gets all event IDs referenced by ticket items in an order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array<int>
   *   Array of event node IDs.
   */
  public function getEventIds(OrderInterface $order): array {
    $event_quantities = $this->extractEventQuantities($order);
    return array_keys($event_quantities);
  }

}

