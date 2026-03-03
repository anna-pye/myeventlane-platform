<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Core\Mail\MailManagerInterface;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Ticket\TicketPdfGenerator;
use Psr\Log\LoggerInterface;

/**
 * Sends ticket-related emails with attachments.
 */
final class TicketMailer {

  public function __construct(
    private readonly MailManagerInterface $mailManager,
    private readonly TicketPdfGenerator $pdfGenerator,
    private readonly LoggerInterface $logger,
    private readonly ?object $icsGenerator = NULL,
  ) {}

  /**
   * Sends the assigned ticket email to the holder.
   */
  public function sendAssignedTicket(Ticket $ticket): bool {
    $holder_email = (string) ($ticket->get('holder_email')->value ?? '');
    $holder_name = (string) ($ticket->get('holder_name')->value ?? '');
    if ($holder_email === '' || $holder_name === '') {
      $this->logger->warning('Ticket @id is missing holder details; email not sent.', [
        '@id' => (string) $ticket->id(),
      ]);
      return FALSE;
    }

    $event = $ticket->get('event_id')->entity;
    if (!$event) {
      $this->logger->error('Ticket @id has no event; email not sent.', [
        '@id' => (string) $ticket->id(),
      ]);
      return FALSE;
    }

    $attachments = [];
    try {
      $attachments[] = $this->pdfGenerator->getPdfContentForTicket($ticket);
    }
    catch (\Throwable $e) {
      $this->logger->error('Ticket PDF generation failed for @id: @message', [
        '@id' => (string) $ticket->id(),
        '@message' => $e->getMessage(),
      ]);
    }

    if ($this->icsGenerator && method_exists($this->icsGenerator, 'generate')) {
      try {
        $attachments[] = [
          'filename' => 'event-' . $event->id() . '.ics',
          'content' => $this->icsGenerator->generate($event),
          'mime' => 'text/calendar',
        ];
      }
      catch (\Throwable $e) {
        $this->logger->warning('ICS generation failed for ticket @id: @message', [
          '@id' => (string) $ticket->id(),
          '@message' => $e->getMessage(),
        ]);
      }
    }

    $event_date = '';
    if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
      $timestamp = strtotime((string) $event->get('field_event_start')->value);
      if ($timestamp !== FALSE) {
        $event_date = date('F j, Y \a\t g:i A', $timestamp);
      }
    }

    $params = [
      'ticket' => $ticket,
      'event' => $event,
      'event_title' => $event->label(),
      'event_date' => $event_date,
      'event_location' => $this->formatEventLocation($event),
      'holder_name' => $holder_name,
      'holder_email' => $holder_email,
      'ticket_code' => (string) $ticket->get('ticket_code')->value,
      'attachments' => $attachments,
    ];

    $result = $this->mailManager->mail('myeventlane_tickets', 'ticket_ready', $holder_email, 'en', $params);
    if (!empty($result['result'])) {
      $this->logger->info('Ticket ready email sent for ticket @id to @email.', [
        '@id' => (string) $ticket->id(),
        '@email' => $holder_email,
      ]);
      return TRUE;
    }

    $this->logger->error('Ticket ready email failed for ticket @id to @email.', [
      '@id' => (string) $ticket->id(),
      '@email' => $holder_email,
    ]);
    return FALSE;
  }

  /**
   * Formats event location line.
   */
  private function formatEventLocation($event): string {
    if (!$event->hasField('field_location') || $event->get('field_location')->isEmpty()) {
      return '';
    }

    $address_field = $event->get('field_location')->first();
    if (!$address_field) {
      return '';
    }

    $address = $address_field->getValue();
    $parts = [];
    foreach (['address_line1', 'locality', 'administrative_area', 'postal_code'] as $key) {
      if (!empty($address[$key])) {
        $parts[] = $address[$key];
      }
    }
    return implode(', ', $parts);
  }

}
