<?php

namespace Drupal\myeventlane_rsvp\Service;

use Drupal\Core\Database\Connection;

/**
 *
 */
final class RsvpCapacityService {

  public function __construct(
    private readonly Connection $db,
  ) {}

  /**
   *
   */
  public function getCurrentCount(int $event_id): int {
    return (int) $this->db->select('myeventlane_rsvp_submission', 'r')
      ->condition('event', $event_id)
      ->condition('status', 'confirmed')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   *
   */
  public function isAtCapacity(int $event_id, int $capacity): bool {
    return $this->getCurrentCount($event_id) >= $capacity;
  }

}
