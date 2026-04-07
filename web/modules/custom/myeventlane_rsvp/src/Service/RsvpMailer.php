<?php

declare(strict_types=1);

namespace Drupal\myeventlane_rsvp\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\myeventlane_messaging\Service\MessagingManager;
use Drupal\myeventlane_rsvp\Entity\RsvpSubmission;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Queues RSVP confirmation (and optional vendor copy) via MessagingManager.
 */
class RsvpMailer {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly MessagingManager $messagingManager,
    private readonly ?LoggerInterface $logger = NULL,
  ) {}

  /**
   * Queues a confirmation email for an RSVP submission.
   *
   * @param \Drupal\myeventlane_rsvp\Entity\RsvpSubmission|array $submission
   *   The RSVP submission entity or an array with submission data.
   * @param \Drupal\node\NodeInterface|null $event
   *   The event node (optional, will be loaded from submission if not provided).
   */
  public function sendConfirmation($submission, ?NodeInterface $event = NULL): void {
    if ($submission instanceof RsvpSubmission) {
      if (!$event) {
        $event = $submission->getEvent();
      }

      if (!$event) {
        $this->log('warning', 'RSVP confirmation skipped: no event found for submission @id', [
          '@id' => $submission->id(),
        ]);
        return;
      }

      $email = $submission->getEmail();
      $name = $submission->getAttendeeName() ?? $submission->get('name')->value ?? '';
      $event_nid = $submission->getEventId() ?? $event->id();
      $guests = $submission->hasField('quantity')
        ? max(1, (int) ($submission->get('quantity')->value ?? 1))
        : ($submission->hasField('guests') ? max(1, (int) ($submission->get('guests')->value ?? 1)) : 1);
      $submission_id = (int) $submission->id();
    }
    elseif (is_array($submission)) {
      if (!$event) {
        $event = Node::load($submission['event_nid'] ?? NULL);
      }

      if (!$event) {
        $this->log('warning', 'RSVP confirmation skipped: no event found for array submission');
        return;
      }

      $email = $submission['email'] ?? '';
      $name = $submission['name'] ?? '';
      $event_nid = $submission['event_nid'] ?? $event->id();
      $guests = max(1, (int) ($submission['quantity'] ?? $submission['guests'] ?? 1));
      $submission_id = (int) ($submission['rsvp_submission_id'] ?? $submission['id'] ?? 0);
    }
    else {
      return;
    }

    if ($email === '') {
      $this->log('warning', 'RSVP confirmation skipped: no email address for event @event', [
        '@event' => $event->id(),
      ]);
      return;
    }

    $config = $this->configFactory->get('myeventlane_rsvp.settings');
    $langcode = $config->get('langcode') ?? 'en';

    $event_date = '';
    if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
      $start_timestamp = strtotime($event->get('field_event_start')->value);
      $event_date = date('F j, Y \a\t g:i A', $start_timestamp);
    }

    $event_location = $this->formatEventLocation($event);

    $attachments = [];
    $ticketAttachment = $this->generateTicketAttachment($submission, $event);
    if ($ticketAttachment) {
      $attachments[] = $ticketAttachment;
    }
    $icsAttachment = $this->generateIcsAttachment($event);
    if ($icsAttachment) {
      $attachments[] = $icsAttachment;
    }

    $context = [
      'first_name' => $name !== '' ? $name : 'there',
      'event_title' => $event->label(),
      'event_name' => $event->label(),
      'event_date' => $event_date,
      'event_location' => $event_location,
      'guests' => $guests,
      'event_id' => (int) $event_nid,
      'attendee_email' => $email,
    ];
    if ($submission_id > 0) {
      $context['submission_id'] = $submission_id;
    }

    $opts = [
      'langcode' => $langcode,
      'attachments' => $attachments,
    ];

    try {
      $queued_id = $this->messagingManager->queue('rsvp_confirmation', $email, $context, $opts);
      if ($queued_id === NULL) {
        $this->log('info', 'RSVP confirmation queue skipped (duplicate idempotent or empty) for @email event @event', [
          '@email' => $email,
          '@event' => $event->label(),
          'event_id' => (int) $event_nid,
          'submission_id' => $submission_id > 0 ? $submission_id : NULL,
          'template_key' => 'rsvp_confirmation',
        ]);
      }
      else {
        $this->log('info', 'RSVP confirmation queued for @email for event @event', [
          '@email' => $email,
          '@event' => $event->label(),
          'message_id' => $queued_id,
        ]);
      }
    }
    catch (\Throwable $e) {
      $this->log('error', 'RSVP confirmation queue failed: @message', [
        '@message' => $e->getMessage(),
        'event_id' => (int) $event_nid,
        'submission_id' => $submission_id > 0 ? $submission_id : NULL,
        'template_key' => 'rsvp_confirmation',
      ]);
      throw $e;
    }

    if ($config->get('send_vendor_copy') && $event->getOwner()?->getEmail()) {
      $vendorEmail = (string) $event->getOwner()->getEmail();
      $vendorContext = [
        'event_title' => $event->label(),
        'event_date' => $event_date,
        'event_location' => $event_location,
        'attendee_name' => $name !== '' ? $name : 'Guest',
        'attendee_email' => $email,
        'event_id' => (int) $event_nid,
      ];
      if ($submission_id > 0) {
        $vendorContext['submission_id'] = $submission_id;
      }
      try {
        $this->messagingManager->queue('rsvp_vendor_copy', $vendorEmail, $vendorContext, [
          'langcode' => $langcode,
        ]);
      }
      catch (\Throwable $e) {
        $this->log('warning', 'RSVP vendor copy queue failed: @message', [
          '@message' => $e->getMessage(),
          'event_id' => (int) $event_nid,
          'template_key' => 'rsvp_vendor_copy',
        ]);
      }
    }
  }

  /**
   * Generates ticket PDF attachment for RSVP.
   *
   * @param \Drupal\myeventlane_rsvp\Entity\RsvpSubmission|array $submission
   *   The RSVP submission.
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array|null
   *   Attachment array or NULL on failure.
   */
  protected function generateTicketAttachment($submission, NodeInterface $event): ?array {
    if (!\Drupal::hasService('myeventlane_tickets.pdf_generator')) {
      return NULL;
    }

    try {
      $pdfGenerator = \Drupal::service('myeventlane_tickets.pdf_generator');
      return $pdfGenerator->getPdfContentForRsvp($submission, $event);
    }
    catch (\Exception $e) {
      $this->log('error', 'Failed to generate ticket PDF for RSVP: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Generates ICS calendar attachment.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array|null
   *   Attachment array or NULL on failure.
   */
  protected function generateIcsAttachment(NodeInterface $event): ?array {
    if (!\Drupal::hasService('myeventlane_rsvp.ics_generator')) {
      return NULL;
    }

    try {
      $icsGenerator = \Drupal::service('myeventlane_rsvp.ics_generator');
      $icsContent = $icsGenerator->generate($event);

      $filename = 'event-' . $event->id() . '-' . preg_replace('/[^a-z0-9]/i', '-', strtolower($event->label())) . '.ics';

      return [
        'filename' => $filename,
        'content' => $icsContent,
        'mime' => 'text/calendar',
      ];
    }
    catch (\Exception $e) {
      $this->log('error', 'Failed to generate ICS for event @event: @message', [
        '@event' => $event->id(),
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Formats event location for display.
   */
  protected function formatEventLocation(NodeInterface $event): string {
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

  /**
   * Logs a message.
   */
  protected function log(string $level, string $message, array $context = []): void {
    if ($this->logger) {
      $this->logger->$level($message, $context);
    }
  }

}
