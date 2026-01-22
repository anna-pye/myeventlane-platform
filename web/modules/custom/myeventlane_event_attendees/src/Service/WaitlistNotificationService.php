<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_attendees\Service;

use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\node\NodeInterface;

/**
 * Service for sending waitlist promotion notifications.
 */
final class WaitlistNotificationService {

  use StringTranslationTrait;

  /**
   * Constructs WaitlistNotificationService.
   */
  public function __construct(
    private readonly MailManagerInterface $mailManager,
    private readonly RendererInterface $renderer,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Sends a promotion notification to a waitlisted attendee.
   *
   * @param \Drupal\myeventlane_event_attendees\Entity\EventAttendee $attendee
   *   The attendee who was promoted.
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return bool
   *   TRUE if email was sent successfully, FALSE otherwise.
   */
  public function sendPromotionNotification(EventAttendee $attendee, NodeInterface $event): bool {
    $email = $attendee->getEmail();
    if (empty($email)) {
      return FALSE;
    }

    // Build event URL for ticket purchase.
    $eventUrl = $event->toUrl('canonical', ['absolute' => TRUE]);
    $purchaseUrl = $eventUrl->toString();

    // If event has tickets enabled, link directly to ticket selection.
    if ($event->hasField('field_product_target') && !$event->get('field_product_target')->isEmpty()) {
      $purchaseUrl = $eventUrl->toString() . '#tickets';
    }

    // Get event details.
    $eventTitle = $event->label();
    $eventStart = NULL;
    if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
      $dateItem = $event->get('field_event_start');
      if ($dateItem->date) {
        $eventStart = $dateItem->date->format('l, F j, Y \a\t g:ia');
      }
    }

    $attendeeName = $attendee->getName();

    // Build email parameters.
    $params = [
      'subject' => $this->t('A spot opened up for @event', ['@event' => $eventTitle]),
      'event' => $event,
      'attendee' => $attendee,
      'event_title' => $eventTitle,
      'event_start' => $eventStart,
      'event_url' => $eventUrl->toString(),
      'purchase_url' => $purchaseUrl,
      'attendee_name' => $attendeeName,
    ];

    // Render email template.
    $build = [
      '#theme' => 'myeventlane_waitlist_promotion_email',
      '#event' => $event,
      '#attendee' => $attendee,
      '#event_title' => $eventTitle,
      '#event_start' => $eventStart,
      '#event_url' => $eventUrl->toString(),
      '#purchase_url' => $purchaseUrl,
      '#attendee_name' => $attendeeName,
    ];

    $body = $this->renderer->renderPlain($build);
    $params['body'] = $body;

    // Send email.
    $result = $this->mailManager->mail(
      'myeventlane_event_attendees',
      'waitlist_promotion',
      $email,
      $this->languageManager->getDefaultLanguage()->getId(),
      $params
    );

    return $result['result'] === TRUE;
  }

}
