<?php

namespace Drupal\myeventlane_rsvp\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Repository service for querying user RSVP submissions.
 */
final class UserRsvpRepository {

  public function __construct(
    private readonly Connection $connection,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Load RSVP submissions for a specific user.
   *
   * @return array
   *   An array of RSVP submission rows.
   */
  public function loadByUser(AccountInterface $account): array {
    return $this->connection->select('rsvp_submission', 'r')
      ->fields('r')
      ->condition('user_id', $account->id())
      ->execute()
      ->fetchAllAssoc('id');
  }

  /**
   * Load RSVP submissions for a specific event node.
   *
   * @return array
   *   An array of RSVP submission rows.
   */
  public function loadByEvent(int $nid): array {
    return $this->connection->select('rsvp_submission', 'r')
      ->fields('r')
      ->condition('event_id', $nid)
      ->execute()
      ->fetchAllAssoc('id');
  }

  /**
   * Gets RSVP rows for an event in reporting format.
   *
   * Returns rows with first_name, last_name (split from attendee_name), email,
   * status, created, id. Excludes cancelled.
   *
   * @param int $event_id
   *   The event node ID.
   *
   * @return array
   *   Indexed array of rows.
   */
  public function getEventRsvps(int $event_id): array {
    $ids = $this->entityTypeManager->getStorage('rsvp_submission')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('event_id', $event_id)
      ->condition('status', 'cancelled', '<>')
      ->sort('created', 'ASC')
      ->execute();

    if (empty($ids)) {
      return [];
    }

    $entities = $this->entityTypeManager->getStorage('rsvp_submission')->loadMultiple($ids);
    $out = [];
    foreach ($entities as $e) {
      $name = trim((string) ($e->get('attendee_name')->value ?? $e->get('name')->value ?? ''));
      $space = strpos($name, ' ');
      $first = $space !== false ? substr($name, 0, $space) : $name;
      $last = $space !== false ? substr($name, $space + 1) : '';
      $out[] = [
        'id' => (int) $e->id(),
        'first_name' => $first,
        'last_name' => $last,
        'email' => (string) $e->get('email')->value,
        'status' => (string) ($e->get('status')->value ?? 'confirmed'),
        'created' => (int) $e->get('created')->value,
      ];
    }
    return $out;
  }

  /**
   * Gets RSVP count for an event by status.
   *
   * @param int $event_id
   *   The event node ID.
   * @param string $status
   *   Status filter: confirmed, waitlist, or cancelled.
   *
   * @return int
   *   The count.
   */
  public function getEventRsvpCount(int $event_id, string $status): int {
    return (int) $this->entityTypeManager->getStorage('rsvp_submission')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('event_id', $event_id)
      ->condition('status', $status)
      ->count()
      ->execute();
  }

}
