<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Phase7\Service;

use Drupal\myeventlane_analytics\Phase7\Exception\InvariantViolationException;

/**
 * Allocates ticket-only refund cents for vendor-visible analytics (Phase 7).
 *
 * Rules (Phase 7):
 * - Pure calculation only: no logging, no I/O, no database access.
 * - Donation-inclusive refunds must be bounded to the ticket subtotal for the
 *   event (donation remainder excluded from vendor view).
 *
 * @internal
 *   Phase 7 isolated API. Do not use outside Phase 7 without approval.
 */
final class TicketOnlyRefundAllocator implements TicketOnlyRefundAllocatorInterface {

  /**
   * {@inheritdoc}
   */
  public function allocateTicketOnlyRefundCents(
    int $refund_amount_cents,
    int $ticket_subtotal_cents_for_event,
    bool $donation_refunded,
    string $refund_type,
  ): int {
    if ($refund_amount_cents < 0) {
      throw new InvariantViolationException('Refund amount must be non-negative.');
    }

    if ($ticket_subtotal_cents_for_event < 0) {
      throw new InvariantViolationException('Ticket subtotal must be non-negative.');
    }

    // For donation-inclusive refunds, vendor-visible amount is capped at the
    // event's ticket subtotal to exclude donation remainder from vendor view.
    if ($donation_refunded) {
      return min($refund_amount_cents, $ticket_subtotal_cents_for_event);
    }

    // When donation is not refunded, treat the refund as ticket-only amount.
    // (Allocation rules for other refund types are defined in later steps.)
    return $refund_amount_cents;
  }

}

