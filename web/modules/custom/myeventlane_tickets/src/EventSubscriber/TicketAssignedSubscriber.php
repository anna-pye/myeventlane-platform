<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Ticket\TicketPdfGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\Entity\EntityInterface;

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
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MailManagerInterface $mailManager,
    private readonly TicketPdfGenerator $pdfGenerator,
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

    // Ensure we have holder details.
    $holderEmail = $ticket->get('holder_email')->value ?? NULL;
    $holderName = $ticket->get('holder_name')->value ?? NULL;

    if (empty($holderEmail) || empty($holderName)) {
      $this->logger->warning('Ticket @id assigned but missing holder details', [
        '@id' => $ticket->id(),
      ]);
      return;
    }

    // Get event details.
    $event = $ticket->get('event_id')->entity;
    if (!$event) {
      $this->logger->warning('Ticket @id assigned but event not found', [
        '@id' => $ticket->id(),
      ]);
      return;
    }

    // Format event date.
    $eventDate = '';
    if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
      $timestamp = strtotime($event->get('field_event_start')->value);
      $eventDate = date('F j, Y \a\t g:i A', $timestamp);
    }

    // Format location.
    $location = $this->formatEventLocation($event);

    // Generate ticket PDF attachment.
    $attachments = [];
    try {
      $pdfData = $this->pdfGenerator->getPdfContentForTicket($ticket);
      $attachments[] = $pdfData;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to generate PDF for ticket @id: @message', [
        '@id' => $ticket->id(),
        '@message' => $e->getMessage(),
      ]);
    }

    // Generate ICS calendar file.
    if (\Drupal::hasService('myeventlane_rsvp.ics_generator')) {
      try {
        $icsGenerator = \Drupal::service('myeventlane_rsvp.ics_generator');
        $icsContent = $icsGenerator->generate($event);
        $filename = 'event-' . $event->id() . '.ics';
        $attachments[] = [
          'filename' => $filename,
          'content' => $icsContent,
          'mime' => 'text/calendar',
        ];
      }
      catch (\Exception $e) {
        $this->logger->warning('Failed to generate ICS for ticket @id: @message', [
          '@id' => $ticket->id(),
          '@message' => $e->getMessage(),
        ]);
      }
    }

    // Build email params.
    $params = [
      'ticket' => $ticket,
      'event' => $event,
      'event_title' => $event->label(),
      'event_date' => $eventDate,
      'event_location' => $location,
      'holder_name' => $holderName,
      'holder_email' => $holderEmail,
      'ticket_code' => $ticket->get('ticket_code')->value,
      'attachments' => $attachments,
    ];

    // Send email.
    $result = $this->mailManager->mail(
      'myeventlane_tickets',
      'ticket_ready',
      $holderEmail,
      'en',
      $params
    );

    if ($result['result'] === TRUE) {
      $this->logger->info('Ticket ready email sent to @email for ticket @id', [
        '@email' => $holderEmail,
        '@id' => $ticket->id(),
      ]);
    }
    else {
      $this->logger->error('Failed to send ticket ready email to @email for ticket @id', [
        '@email' => $holderEmail,
        '@id' => $ticket->id(),
      ]);
    }
  }

  /**
   * Formats event location for display.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return string
   *   Formatted location string.
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

    if (!empty($address['address_line1'])) {
      $parts[] = $address['address_line1'];
    }
    if (!empty($address['locality'])) {
      $parts[] = $address['locality'];
    }
    if (!empty($address['administrative_area'])) {
      $parts[] = $address['administrative_area'];
    }

    return implode(', ', $parts);
  }

}
