<?php

namespace Drupal\myeventlane_rsvp\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;

/**
 * Repository service for querying user RSVP submissions.
 */
final class UserRsvpRepository {

  /**
   * @var \Drupal\Core\Database\Connection
   */
  private Connection $connection;

  /**
   * Constructor.
   */
  public function __construct(Connection $connection) {
    $this->connection = $connection;
  }

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

}
