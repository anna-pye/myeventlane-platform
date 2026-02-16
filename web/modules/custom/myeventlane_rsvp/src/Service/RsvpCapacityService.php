<?php

namespace Drupal\myeventlane_rsvp\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Canonical capacity checks for RSVP submissions.
 */
final class RsvpCapacityService {

  /**
   * Constructs RsvpCapacityService.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Counts confirmed RSVPs for an event.
   *
   * @param int $event_nid
   *   The event node ID.
   *
   * @return int
   *   The count of confirmed RSVPs.
   */
  public function countConfirmedRsvps(int $event_nid): int {
    return (int) $this->entityTypeManager
      ->getStorage('rsvp_submission')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('event_id', $event_nid)
      ->condition('status', 'confirmed')
      ->count()
      ->execute();
  }

  /**
   * Returns the current confirmed count (alias for countConfirmedRsvps).
   *
   * @deprecated Use countConfirmedRsvps() instead.
   */
  public function getCurrentCount(int $event_id): int {
    return $this->countConfirmedRsvps($event_id);
  }

  /**
   * Checks if the event is at or over capacity.
   *
   * @param int $event_id
   *   The event node ID.
   * @param int $capacity
   *   The capacity limit.
   *
   * @return bool
   *   TRUE if at or over capacity.
   */
  public function isAtCapacity(int $event_id, int $capacity): bool {
    return $this->countConfirmedRsvps($event_id) >= $capacity;
  }

}
