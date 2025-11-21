<?php

namespace Drupal\myeventlane_rsvp\Service;

use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\node\Entity\Node;
use Drupal\myeventlane_rsvp\Service\RsvpStorage;
use Drupal\Core\Config\ConfigFactoryInterface;

class RsvpMailer {

  protected $mailManager;
  protected $configFactory;

  public function __construct(MailManagerInterface $mailManager, ConfigFactoryInterface $configFactory) {
    $this->mailManager = $mailManager;
    $this->configFactory = $configFactory;
  }

  public function sendConfirmation(array $submission) {
    $event = Node::load($submission['event_nid']);
    if (!$event) {
      return;
    }

    $config = $this->configFactory->get('myeventlane_rsvp.settings');

    $params = [
      'event_title' => $event->label(),
      'event_date' => $event->get('field_event_date')->value ?? '',
      'event_location' => $event->get('field_location')->value ?? '',
      'name' => $submission['name'],
      'email' => $submission['email'],
      'event_nid' => $event->id(),
    ];

    $this->mailManager->mail(
      'myeventlane_rsvp',
      'rsvp_confirmation',
      $submission['email'],
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

public function sendWaitlistPromotion(RsvpSubmission $submission, NodeInterface $event): void {
    $to = $this->getRecipientEmail($submission);

    $params['subject'] = $this->t('A spot opened up for @event', ['@event' => $event->label()]);
    $params['message'] = $this->renderer->renderPlain([
      '#theme' => 'myeventlane_rsvp_waitlist_promotion_email',
      '#event' => $event,
      '#submission' => $submission,
    ]);

    $this->mailManager->mail('myeventlane_rsvp', 'promotion', $to, 'en', $params);
  }
}