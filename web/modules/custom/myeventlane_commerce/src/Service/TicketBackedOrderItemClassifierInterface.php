<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_order\Entity\OrderItemInterface;

/**
 * Contract for classifying ticket-backed Commerce order items.
 */
interface TicketBackedOrderItemClassifierInterface {

  /**
   * Whether the line item is a ticket-backed purchase for a mapped event tier.
   */
  public function isTicketBackedOrderItem(OrderItemInterface $order_item): bool;

}
