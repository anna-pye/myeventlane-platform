<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Service;

use Drupal\commerce_order\Entity\OrderInterface;

/**
 * Calculates event-ticket and payment balances used by refund workflows.
 */
interface RefundOrderInspectorInterface extends RefundOrderItemsInterface {

  /**
   * Calculates the refund for selected attendees from one event.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The paid order.
   * @param int $event_nid
   *   The event node ID.
   * @param list<int> $selectedAttendeeIds
   *   Event attendee entity IDs selected for refund.
   */
  public function calculateSelectedAttendeeRefundCents(
    OrderInterface $order,
    int $event_nid,
    array $selectedAttendeeIds,
  ): int;

  /**
   * Calculates the ticket subtotal for one event in an order.
   */
  public function calculateTicketSubtotalCents(OrderInterface $order, int $event_nid): int;

  /**
   * Calculates the donation total for an order.
   */
  public function calculateDonationTotalCents(OrderInterface $order): int;

  /**
   * Calculates the remaining refundable payment balance for an order.
   */
  public function calculateRefundableAmountCents(OrderInterface $order): int;

}
