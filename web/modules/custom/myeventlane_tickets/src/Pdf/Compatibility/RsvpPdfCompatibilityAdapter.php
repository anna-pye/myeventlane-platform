<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Pdf\Compatibility;

use Drupal\myeventlane_tickets\Service\TicketPdfEventMetadataHelper;
use Drupal\myeventlane_tickets\Ticket\TicketPdfAttachmentRenderer;
use Drupal\node\NodeInterface;

/**
 * Bridges RSVP virtual-ticket PDF entry points into shared PDF preparation.
 *
 * Inward-only: adapts RSVP submission shapes; does not issue tickets.
 */
final class RsvpPdfCompatibilityAdapter {

  public function __construct(
    private readonly TicketPdfEventMetadataHelper $eventMetadataHelper,
    private readonly TicketPdfAttachmentRenderer $attachmentRenderer,
  ) {}

  /**
   * @param object|array<string, mixed> $rsvp
   *   RSVP submission entity or legacy array shape.
   *
   * @return array{content: string, filename: string, mime: string}
   */
  public function buildAttachment(mixed $rsvp, NodeInterface $event): array {
    $holderName = '';
    $holderEmail = '';
    $rsvpId = NULL;

    if (is_object($rsvp)) {
      if (method_exists($rsvp, 'id')) {
        $rsvpId = $rsvp->id();
      }
      if (method_exists($rsvp, 'get')) {
        if ($rsvp->hasField('field_name') && !$rsvp->get('field_name')->isEmpty()) {
          $holderName = $rsvp->get('field_name')->value;
        }
        elseif ($rsvp->hasField('field_first_name')) {
          $firstName = $rsvp->hasField('field_first_name') && !$rsvp->get('field_first_name')->isEmpty()
            ? $rsvp->get('field_first_name')->value : '';
          $lastName = $rsvp->hasField('field_last_name') && !$rsvp->get('field_last_name')->isEmpty()
            ? $rsvp->get('field_last_name')->value : '';
          $holderName = trim($firstName . ' ' . $lastName);
        }
        if ($rsvp->hasField('field_email') && !$rsvp->get('field_email')->isEmpty()) {
          $holderEmail = $rsvp->get('field_email')->value;
        }
      }
    }
    elseif (is_array($rsvp)) {
      $holderName = $rsvp['name'] ?? $rsvp['first_name'] ?? '';
      if (isset($rsvp['last_name'])) {
        $holderName = trim($holderName . ' ' . $rsvp['last_name']);
      }
      $holderEmail = $rsvp['email'] ?? '';
      $rsvpId = $rsvp['id'] ?? NULL;
    }

    if (empty($holderName)) {
      $holderName = 'Guest';
    }

    $rows = $this->eventMetadataHelper->legacyPdfRowsForEvent($event);

    $ticketCode = sprintf(
      'RSVP-%d-%s-%s',
      $event->id(),
      $rsvpId ?: time(),
      strtoupper(substr(md5($holderEmail . $event->id()), 0, 6))
    );

    $build = [
      '#theme' => 'ticket_pdf',
      '#event_title' => $rows['title'],
      '#event_start' => $rows['start'],
      '#location' => $rows['location'],
      '#holder' => $holderName,
      '#code' => $ticketCode,
      '#legacy' => FALSE,
      '#is_rsvp' => TRUE,
    ];

    $filename = 'rsvp-ticket-' . $event->id() . '-' . ($rsvpId ?: 'guest') . '.pdf';

    return [
      'content' => $this->attachmentRenderer->renderBytes($build),
      'filename' => $filename,
      'mime' => 'application/pdf',
    ];
  }

}
