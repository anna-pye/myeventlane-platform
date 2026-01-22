<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_attendees\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;

/**
 * Waitlist management service for MyEventLane.
 *
 * Handles waitlist-specific operations such as auto-promotion when spots
 * become available.
 */
final class AttendanceWaitlistManager {

  /**
   * Constructs AttendanceWaitlistManager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AttendanceManagerInterface $attendanceManager,
  ) {}

  /**
   * Gets all waitlisted attendees for an event.
   *
   * @param int $eventId
   *   The event node ID.
   *
   * @return \Drupal\myeventlane_event_attendees\Entity\EventAttendee[]
   *   Array of waitlisted attendees, ordered by creation time (FIFO).
   */
  public function getWaitlist(int $eventId): array {
    return $this->attendanceManager->getAttendeesForEvent(
      $eventId,
      EventAttendee::STATUS_WAITLIST
    );
  }

  /**
   * Gets the waitlist position for an attendee.
   *
   * @param \Drupal\myeventlane_event_attendees\Entity\EventAttendee $attendee
   *   The attendee to check.
   *
   * @return int|null
   *   The position (1-based) or NULL if not waitlisted.
   */
  public function getWaitlistPosition(EventAttendee $attendee): ?int {
    if (!$attendee->isWaitlisted()) {
      return NULL;
    }

    $eventId = $attendee->getEventId();
    if ($eventId === NULL) {
      return NULL;
    }

    $waitlist = $this->getWaitlist($eventId);
    $position = 1;

    foreach ($waitlist as $waitlisted) {
      if ((int) $waitlisted->id() === (int) $attendee->id()) {
        return $position;
      }
      $position++;
    }

    return NULL;
  }

  /**
   * Gets the waitlist count for an event.
   *
   * @param int $eventId
   *   The event node ID.
   *
   * @return int
   *   Number of people on the waitlist.
   */
  public function getWaitlistCount(int $eventId): int {
    return $this->attendanceManager->getAttendeeCount(
      $eventId,
      [EventAttendee::STATUS_WAITLIST]
    );
  }

  /**
   * Promotes attendees from waitlist when capacity becomes available.
   *
   * @param int $eventId
   *   The event node ID.
   * @param int $spots
   *   Number of spots to fill from waitlist.
   *
   * @return \Drupal\myeventlane_event_attendees\Entity\EventAttendee[]
   *   Array of promoted attendees.
   */
  public function promoteMultiple(int $eventId, int $spots = 1): array {
    $promoted = [];

    for ($i = 0; $i < $spots; $i++) {
      $attendee = $this->attendanceManager->promoteFromWaitlist($eventId);
      if ($attendee === NULL) {
        break;
      }
      $promoted[] = $attendee;
    }

    return $promoted;
  }

  /**
   * Checks if an event has a waitlist enabled.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return bool
   *   TRUE if waitlist is enabled.
   */
  public function isWaitlistEnabled(object $event): bool {
    // Check if the event has a waitlist field and it's enabled.
    if ($event->hasField('field_waitlist_enabled') && !$event->get('field_waitlist_enabled')->isEmpty()) {
      return (bool) $event->get('field_waitlist_enabled')->value;
    }

    // Default to TRUE if field doesn't exist (backward compatibility).
    return TRUE;
  }

  /**
   * Gets waitlist analytics for an event.
   *
   * @param int $eventId
   *   The event node ID.
   *
   * @return array
   *   Array with analytics data:
   *   - total_waitlist: Total number of waitlisted attendees
   *   - total_promoted: Number of attendees promoted from waitlist
   *   - conversion_rate: Percentage of waitlisted who were promoted
   *   - average_wait_time: Average time on waitlist (in hours)
   *   - current_waitlist: Current waitlist count
   */
  public function getWaitlistAnalytics(int $eventId): array {
    $storage = $this->entityTypeManager->getStorage('event_attendee');

    // Get all waitlisted attendees (current and historical).
    $waitlistQuery = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $eventId)
      ->condition('status', EventAttendee::STATUS_WAITLIST);
    $currentWaitlistCount = (int) $waitlistQuery->count()->execute();

    // Get all attendees who were ever on waitlist (including promoted).
    $allWaitlistQuery = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $eventId);
    $allWaitlistIds = $allWaitlistQuery->execute();

    $totalWaitlist = 0;
    $totalPromoted = 0;
    $totalWaitTime = 0;
    $promotedCount = 0;

    if (!empty($allWaitlistIds)) {
      $attendees = $storage->loadMultiple($allWaitlistIds);
      foreach ($attendees as $attendee) {
        // Check if attendee was ever waitlisted (by checking created time vs promoted time).
        if (!$attendee->get('promoted_at')->isEmpty()) {
          // Was promoted.
          $totalPromoted++;
          $promotedCount++;
          $created = (int) $attendee->get('created')->value;
          $promoted = (int) $attendee->get('promoted_at')->value;
          if ($promoted > $created) {
            $waitTime = $promoted - $created;
            $totalWaitTime += $waitTime;
          }
        }
        elseif ($attendee->isWaitlisted()) {
          // Currently waitlisted.
          $totalWaitlist++;
        }
      }
    }

    // Calculate conversion rate.
    $totalEverWaitlisted = $currentWaitlistCount + $totalPromoted;
    $conversionRate = $totalEverWaitlisted > 0
      ? round(($totalPromoted / $totalEverWaitlisted) * 100, 1)
      : 0.0;

    // Calculate average wait time.
    $averageWaitTime = $promotedCount > 0
    // Convert to hours.
      ? round($totalWaitTime / $promotedCount / 3600, 1)
      : 0.0;

    return [
      'total_waitlist' => $totalEverWaitlisted,
      'total_promoted' => $totalPromoted,
      'conversion_rate' => $conversionRate,
      'average_wait_time' => $averageWaitTime,
      'current_waitlist' => $currentWaitlistCount,
    ];
  }

}
