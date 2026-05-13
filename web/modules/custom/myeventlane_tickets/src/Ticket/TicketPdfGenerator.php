<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Ticket;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Pdf\Compatibility\EventAttendeePdfCompatibilityAdapter;
use Drupal\myeventlane_tickets\Pdf\Compatibility\OrderItemPdfCompatibilityAdapter;
use Drupal\myeventlane_tickets\Pdf\Compatibility\RsvpPdfCompatibilityAdapter;
use Drupal\myeventlane_tickets\Service\TicketPdfEventMetadataHelper;
use Drupal\myeventlane_tickets\Service\UniversalTicketViewModelBuilder;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Generates PDF output for tickets.
 *
 * Issued tickets are rendered through the canonical view model. Legacy
 * order-item, attendee, and RSVP paths are isolated in compatibility adapters
 * that delegate inward to shared render preparation only.
 */
final class TicketPdfGenerator {

  public function __construct(
    private readonly TicketPdfAttachmentRenderer $attachmentRenderer,
    private readonly TicketPdfEventMetadataHelper $eventMetadataHelper,
    private readonly OrderItemPdfCompatibilityAdapter $orderItemPdfAdapter,
    private readonly RsvpPdfCompatibilityAdapter $rsvpPdfAdapter,
    private readonly EventAttendeePdfCompatibilityAdapter $attendeePdfAdapter,
    private readonly ?UniversalTicketViewModelBuilder $viewModelBuilder = NULL,
  ) {}

  /**
   * LEGACY: Generate PDF for an order item.
   *
   * ⚠️ May represent multiple tickets.
   */
  public function generatePdfForOrderItem(OrderItemInterface $order_item): Response {
    $pdf = $this->getPdfContentForOrderItem($order_item);
    return $this->renderPdfFromArray($pdf);
  }

  /**
   * PDF bytes for attachment (legacy order-item template).
   *
   * @return array{content: string, filename: string, mime: string}
   */
  public function getPdfContentForOrderItem(OrderItemInterface $order_item): array {
    return $this->orderItemPdfAdapter->buildAttachment($order_item);
  }

  /**
   * Wraps a PDF attachment array as an HTTP download response.
   *
   * @param array{content: string, filename: string, mime: string} $pdf
   *   From getPdfContentForOrderItem or getPdfContentForTicket.
   */
  private function renderPdfFromArray(array $pdf): Response {
    $response = new Response($pdf['content']);
    $response->headers->set('Content-Type', $pdf['mime'] ?? 'application/pdf');
    $response->headers->set(
      'Content-Disposition',
      ResponseHeaderBag::DISPOSITION_ATTACHMENT . '; filename="' . ($pdf['filename'] ?? 'ticket.pdf') . '"'
    );

    return $response;
  }

  /**
   * CANONICAL v2: Generate PDF for a single issued ticket.
   *
   * Derives all operational entitlement data from the canonical view model.
   */
  public function generatePdfForTicket(Ticket $ticket): Response {
    $pdf = $this->getPdfContentForTicket($ticket);
    return $this->renderPdfFromArray($pdf);
  }

  /**
   * Gets PDF content as bytes for a ticket (for email attachments).
   *
   * When the canonical view model builder is available, all operational
   * entitlement data is derived from the normalized model instead of manual
   * entity field extraction.
   *
   * @return array{content: string, filename: string, mime: string}
   *
   * @throws \LogicException
   *   If ticket holder details are not assigned.
   */
  public function getPdfContentForTicket(Ticket $ticket): array {
    if (
      $ticket->get('holder_name')->isEmpty() ||
      $ticket->get('holder_email')->isEmpty()
    ) {
      throw new \LogicException('Ticket holder details must be assigned before PDF generation.');
    }

    if ($this->viewModelBuilder !== NULL) {
      return $this->buildPdfFromCanonicalModel($ticket);
    }

    return $this->buildPdfFromLegacyExtraction($ticket);
  }

  /**
   * Builds PDF content using the canonical view model.
   *
   * @return array{content: string, filename: string, mime: string}
   */
  private function buildPdfFromCanonicalModel(Ticket $ticket): array {
    $model = $this->viewModelBuilder->build($ticket);

    $build = [
      '#theme' => 'ticket_pdf',
      '#ticket' => $ticket,
      '#event_title' => $model['event']['label'],
      '#event_start' => $model['event']['start']['raw'] ?: NULL,
      '#location' => $model['event']['location'],
      '#holder' => $model['holder']['name'],
      '#code' => $model['ticket']['code'],
      '#legacy' => FALSE,
    ];

    $ticketCode = $model['ticket']['code'];
    $filename = 'ticket-' . $ticketCode . '.pdf';

    return [
      'content' => $this->attachmentRenderer->renderBytes($build),
      'filename' => $filename,
      'mime' => 'application/pdf',
    ];
  }

  /**
   * Legacy extraction path for when the view model builder is unavailable.
   *
   * @return array{content: string, filename: string, mime: string}
   */
  private function buildPdfFromLegacyExtraction(Ticket $ticket): array {
    $event = $ticket->get('event_id')->entity;
    if (!$event) {
      throw new \LogicException('Event not found for ticket.');
    }

    $ticketCode = $ticket->get('ticket_code')->value;
    $holderName = $ticket->get('holder_name')->value;

    $rows = $this->eventMetadataHelper->legacyPdfRowsForEvent($event);

    $build = [
      '#theme' => 'ticket_pdf',
      '#ticket' => $ticket,
      '#event_title' => $rows['title'],
      '#event_start' => $rows['start'],
      '#location' => $rows['location'],
      '#holder' => $holderName,
      '#code' => $ticketCode,
      '#legacy' => FALSE,
    ];

    $filename = 'ticket-' . $ticketCode . '.pdf';

    return [
      'content' => $this->attachmentRenderer->renderBytes($build),
      'filename' => $filename,
      'mime' => 'application/pdf',
    ];
  }

  /**
   * Gets PDF content for an RSVP confirmation ticket.
   *
   * For RSVP, we create a virtual ticket representation since there may
   * not be a formal ticket entity.
   *
   * @param object $rsvp
   *   The RSVP submission entity or data object.
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Array with 'content' (PDF bytes), 'filename', and 'mime'.
   */
  public function getPdfContentForRsvp($rsvp, NodeInterface $event): array {
    return $this->rsvpPdfAdapter->buildAttachment($rsvp, $event);
  }

  /**
   * PDF for an event attendee when commerce order lines are missing (deleted).
   */
  public function generatePdfForEventAttendee(EventAttendee $attendee): Response {
    $pdf = $this->getPdfContentForEventAttendee($attendee);
    return $this->renderPdfFromArray($pdf);
  }

  /**
   * @return array{content: string, filename: string, mime: string}
   */
  public function getPdfContentForEventAttendee(EventAttendee $attendee): array {
    return $this->attendeePdfAdapter->buildAttachment($attendee);
  }

}
