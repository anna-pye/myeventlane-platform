<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkin\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\node\NodeInterface;

/**
 * Proves attendee/RSVP IDs belong to a route event before check-in mutation.
 */
final class CheckInAttendeeEventBinder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Whether the attendee/RSVP ID belongs to the given route event.
   *
   * Missing entities and foreign-event IDs both return FALSE (no disclosure).
   */
  public function belongsToEvent(NodeInterface $event, int $attendeeId, string $type): bool {
    if ($attendeeId <= 0 || !in_array($type, ['rsvp', 'ticket'], TRUE)) {
      return FALSE;
    }

    $routeEventId = (int) $event->id();
    if ($routeEventId <= 0) {
      return FALSE;
    }

    try {
      if ($type === 'rsvp') {
        if (!$this->entityTypeManager->hasDefinition('rsvp_submission')) {
          return FALSE;
        }
        $rsvp = $this->entityTypeManager->getStorage('rsvp_submission')->load($attendeeId);
        if (!$rsvp || !method_exists($rsvp, 'getEventId')) {
          return FALSE;
        }
        return (int) $rsvp->getEventId() === $routeEventId;
      }

      if (!$this->entityTypeManager->hasDefinition('event_attendee')) {
        return FALSE;
      }
      $attendeeEntity = $this->entityTypeManager->getStorage('event_attendee')->load($attendeeId);
      if (!$attendeeEntity instanceof EventAttendee) {
        return FALSE;
      }
      if (!$attendeeEntity->hasField('event') || $attendeeEntity->get('event')->isEmpty()) {
        return FALSE;
      }
      return (int) $attendeeEntity->get('event')->target_id === $routeEventId;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('myeventlane_checkin')->error('Failed to bind check-in attendee to event: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

}
