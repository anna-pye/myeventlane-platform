<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\EventSubscriber;

use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Service\TicketMailer;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Sends "Ticket Ready" email when a ticket is assigned with holder details.
 *
 * Each ticket holder receives their own email with their PDF ticket attached.
 */
final class TicketAssignedSubscriber implements EventSubscriberInterface {

  /**
   * Constructs TicketAssignedSubscriber.
   */
  public function __construct(
    private readonly TicketMailer $ticketMailer,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // We'll use hook_entity_update instead of events.
    // This is registered via hook implementation.
    return [];
  }

  /**
   * Handles ticket assignment.
   *
   * Called from hook_entity_update when a ticket transitions to assigned.
   *
   * @param \Drupal\myeventlane_tickets\Entity\Ticket $ticket
   *   The ticket entity.
   * @param \Drupal\myeventlane_tickets\Entity\Ticket|null $original
   *   The original ticket before update.
   */
  public function onTicketAssigned(Ticket $ticket, ?Ticket $original = NULL): void {
    // Only proceed if status changed TO assigned.
    $new_status = $ticket->get('status')->value;
    $old_status = $original ? $original->get('status')->value : NULL;

    if ($new_status !== Ticket::STATUS_ASSIGNED) {
      return;
    }

    // Only send email if this is a transition (not already assigned).
    if ($old_status === Ticket::STATUS_ASSIGNED) {
      return;
    }

    if ($ticket->get('holder_email')->isEmpty() || $ticket->get('holder_name')->isEmpty()) {
      $this->logger->warning('Ticket @id assigned but missing holder details', [
        '@id' => $ticket->id(),
      ]);
      return;
    }
    if (!$this->ticketMailer->sendAssignedTicket($ticket)) {
      $this->logger->error('Ticket assigned email failed for ticket @id.', [
        '@id' => (string) $ticket->id(),
      ]);
    }
  }

}
