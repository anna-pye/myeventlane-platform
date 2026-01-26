<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Phase7\Service;

/**
 * Allocates refund cents to ticket-only visibility (Phase 7).
 *
 * This contract exists to ensure donation-inclusive refunds are bounded
 * to ticket subtotal for vendor-visible refund metrics.
 *
 * @internal
 *   Phase 7 isolated API. Do not use outside Phase 7 without approval.
 */
interface TicketOnlyRefundAllocatorInterface {

  /**
   * Allocates refund cents to ticket-only amount (vendor-visible portion).
   *
   * @param int $refund_amount_cents
   *   Total refund amount in cents.
   * @param int $ticket_subtotal_cents_for_event
   *   Ticket subtotal in cents for the relevant event (order-item anchored).
   * @param bool $donation_refunded
   *   TRUE if donation portion was refunded; FALSE otherwise.
   * @param string $refund_type
   *   Refund type discriminator (implementation-defined).
   *
   * @return int
   *   Allocated ticket-only refund amount in cents.
   */
  public function allocateTicketOnlyRefundCents(
    int $refund_amount_cents,
    int $ticket_subtotal_cents_for_event,
    bool $donation_refunded,
    string $refund_type,
  ): int;

}

