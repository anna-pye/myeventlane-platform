<?php

namespace Drupal\myeventlane_rsvp\Service;

use Drupal\Core\Mail\MailManagerInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\myeventlane_rsvp\Entity\RsvpSubmission;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 *
 */
class RsvpMailer {

  protected $mailManager;
  protected $configFactory;

  public function __construct(MailManagerInterface $mailManager, ConfigFactoryInterface $configFactory) {
    $this->mailManager = $mailManager;
    $this->configFactory = $configFactory;
  }

  /**
   * Sends a confirmation email for an RSVP submission.
   *
   * @param \Drupal\myeventlane_rsvp\Entity\RsvpSubmission|array $submission
   *   The RSVP submission entity or an array with submission data.
   * @param \Drupal\node\NodeInterface|null $event
   *   The event node (optional, will be loaded from submission if not provided).
   */
  public function sendConfirmation($submission, ?NodeInterface $event = NULL) {
    // Handle RsvpSubmission entity.
    if ($submission instanceof RsvpSubmission) {
      if (!$event) {
        $event = $submission->getEvent();
      }

      if (!$event) {
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
      return;
    }

    $config = $this->configFactory->get('myeventlane_rsvp.settings');

    $params = [
      'event_title' => $event->label(),
      'event_date' => $event->get('field_event_start')->value ?? '',
      'event_location' => $event->get('field_location')->value ?? '',
      'name' => $name,
      'email' => $email,
      'event_nid' => $event_nid,
    ];

    $this->mailManager->mail(
      'myeventlane_rsvp',
      'rsvp_confirmation',
      $email,
      $config->get('langcode') ?? 'en',
      $params
    );

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

}
