<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Pdf\Compatibility;

use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\myeventlane_tickets\Service\TicketPdfEventMetadataHelper;
use Drupal\myeventlane_tickets\Ticket\TicketPdfAttachmentRenderer;
use Drupal\node\NodeInterface;

/**
 * Bridges event_attendee fallback PDF entry points into shared PDF preparation.
 *
 * Inward-only: uses attendee roster fields for display; does not issue tickets.
 */
final class EventAttendeePdfCompatibilityAdapter {

  public function __construct(
    private readonly TicketPdfEventMetadataHelper $eventMetadataHelper,
    private readonly TicketPdfAttachmentRenderer $attachmentRenderer,
  ) {}

  /**
   * @return array{content: string, filename: string, mime: string}
   */
  public function buildAttachment(EventAttendee $attendee): array {
    $event = $attendee->get('event')->entity;
    if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
      throw new \LogicException('Event not found for attendee.');
    }
    if ($attendee->get('ticket_code')->isEmpty()) {
      throw new \LogicException('Ticket code required.');
    }

    $ticketCode = trim((string) $attendee->get('ticket_code')->value);
    $holderName = trim((string) ($attendee->get('name')->value ?? ''));
    if ($holderName === '') {
      $holderName = 'Guest';
    }

    $rows = $this->eventMetadataHelper->legacyPdfRowsForEvent($event);

    $build = [
      '#theme' => 'ticket_pdf',
      '#event_title' => $rows['title'],
      '#event_start' => $rows['start'],
      '#location' => $rows['location'],
      '#holder' => $holderName,
      '#code' => $ticketCode,
      '#legacy' => FALSE,
      '#is_rsvp' => FALSE,
      '#is_attendee' => TRUE,
      '#ticket' => NULL,
    ];

    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $ticketCode) ?: 'ticket';

    return [
      'content' => $this->attachmentRenderer->renderBytes($build),
      'filename' => 'ticket-' . $safeName . '.pdf',
      'mime' => 'application/pdf',
    ];
  }

}
