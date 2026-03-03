<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Service\TicketMailer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Handles ticket resend actions.
 */
final class TicketResendController extends ControllerBase {

  public function __construct(
    private readonly TicketMailer $ticketMailer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_tickets.ticket_mailer'),
    );
  }

  /**
   * Resends ticket email for an assigned ticket.
   */
  public function resend(Ticket $myeventlane_ticket): RedirectResponse {
    if ($myeventlane_ticket->get('status')->value !== Ticket::STATUS_ASSIGNED && $myeventlane_ticket->get('status')->value !== Ticket::STATUS_CHECKED_IN) {
      $this->messenger()->addError($this->t('Only assigned or checked-in tickets can be resent.'));
    }
    elseif ($this->ticketMailer->sendAssignedTicket($myeventlane_ticket)) {
      $this->messenger()->addStatus($this->t('Ticket email has been resent to the holder.'));
    }
    else {
      $this->messenger()->addError($this->t('Failed to resend ticket email. Check logs for details.'));
    }

    $event_id = (int) $myeventlane_ticket->get('event_id')->target_id;
    if ($event_id > 0) {
      return new RedirectResponse(Url::fromRoute('myeventlane_tickets.ticket_checkin', ['event' => $event_id])->toString());
    }

    return new RedirectResponse(Url::fromRoute('<front>')->toString());
  }

}
