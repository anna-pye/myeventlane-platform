<?php

declare(strict_types=1);

namespace Drupal\myeventlane_rsvp\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\myeventlane_rsvp\Entity\RsvpSubmission;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends RSVP confirmation emails with ticket attachments.
 */
class RsvpMailer {

  /**
   * The mail manager.
   */
  protected MailManagerInterface $mailManager;

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The logger.
   */
  protected ?LoggerInterface $logger;

  /**
   * Constructs RsvpMailer.
   */
  public function __construct(
    MailManagerInterface $mailManager,
    ConfigFactoryInterface $configFactory,
    ?LoggerInterface $logger = NULL,
  ) {
    $this->mailManager = $mailManager;
    $this->configFactory = $configFactory;
    $this->logger = $logger;
  }

  /**
   * Sends a confirmation email for an RSVP submission.
   *
   * @param \Drupal\myeventlane_rsvp\Entity\RsvpSubmission|array $submission
   *   The RSVP submission entity or an array with submission data.
   * @param \Drupal\node\NodeInterface|null $event
   *   The event node (optional, will be loaded from submission if not provided).
   */
  public function sendConfirmation($submission, ?NodeInterface $event = NULL): void {
    // Handle RsvpSubmission entity.
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
    }
    // Handle legacy array format.
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
    }
    else {
      return;
    }

    if (empty($email)) {
      $this->log('warning', 'RSVP confirmation skipped: no email address for event @event', [
        '@event' => $event->id(),
      ]);
      return;
    }

    $config = $this->configFactory->get('myeventlane_rsvp.settings');

    // Format event date for display.
    $event_date = '';
    if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
      $start_timestamp = strtotime($event->get('field_event_start')->value);
      $event_date = date('F j, Y \a\t g:i A', $start_timestamp);
    }

    // Format location.
    $event_location = $this->formatEventLocation($event);

    $params = [
      'event_title' => $event->label(),
      'event_date' => $event_date,
      'event_location' => $event_location,
      'name' => $name,
      'email' => $email,
      'event_nid' => $event_nid,
      'attachments' => [],
    ];

    // Generate ticket PDF attachment.
    $ticketAttachment = $this->generateTicketAttachment($submission, $event);
    if ($ticketAttachment) {
      $params['attachments'][] = $ticketAttachment;
    }

    // Generate ICS calendar attachment.
    $icsAttachment = $this->generateIcsAttachment($event);
    if ($icsAttachment) {
      $params['attachments'][] = $icsAttachment;
    }

    // Send confirmation to attendee.
    $this->mailManager->mail(
      'myeventlane_rsvp',
      'rsvp_confirmation',
      $email,
      $config->get('langcode') ?? 'en',
      $params
    );

    $this->log('info', 'RSVP confirmation sent to @email for event @event', [
      '@email' => $email,
      '@event' => $event->label(),
    ]);

    // Optionally send vendor copy.
    if ($config->get('send_vendor_copy') && $event->getOwner()?->getEmail()) {
      $this->mailManager->mail(
        'myeventlane_rsvp',
        'rsvp_vendor_copy',
        $event->getOwner()->getEmail(),
        $config->get('langcode') ?? 'en',
        $params
      );
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
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return string
   *   Formatted location string.
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
   *
   * @param string $level
   *   Log level.
   * @param string $message
   *   Message.
   * @param array $context
   *   Context.
   */
  protected function log(string $level, string $message, array $context = []): void {
    if ($this->logger) {
      $this->logger->$level($message, $context);
    }
  }

}
