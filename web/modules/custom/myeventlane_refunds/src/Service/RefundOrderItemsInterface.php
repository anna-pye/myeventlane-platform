<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Service;

use Drupal\commerce_order\Entity\OrderInterface;

/**
 * Order item extraction needed by refund access.
 */
interface RefundOrderItemsInterface {

  /**
   * Extracts order items that belong to the given event.
   *
   * @return list<\Drupal\commerce_order\Entity\OrderItemInterface>
   *   Matching items.
   */
  public function extractItemsForEvent(OrderInterface $order, int $event_nid): array;

}
