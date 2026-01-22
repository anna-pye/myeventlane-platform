<?php

namespace Drupal\myeventlane_rsvp\Service;

use Drupal\Core\Database\Connection;

/**
 *
 */
class RsvpStorage {

  protected Connection $db;

  public function __construct(Connection $db) {
    $this->db = $db;
  }

  /**
   *
   */
  public function add(array $data): int {
    $data['created'] = time();
    return $this->db->insert('myeventlane_rsvp')->fields($data)->execute();
  }

  /**
   *
   */
  public function cancel(int $id): void {
    $this->db->update('myeventlane_rsvp')
      ->fields(['status' => 'cancelled'])
      ->condition('id', $id)
      ->execute();
  }

  /**
   *
   */
  public function countByEvent(int $nid): int {
    return (int) $this->db->select('myeventlane_rsvp', 'r')
      ->condition('event_nid', $nid)
      ->condition('status', 'active')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   *
   */
  public function listForEvent(int $nid): array {
    return $this->db->select('myeventlane_rsvp', 'r')
      ->fields('r')
      ->condition('event_nid', $nid)
      ->execute()
      ->fetchAllAssoc('id');
  }

  /**
   *
   */
  public function dailyCounts(int $nid, int $days = 14): array {
    $results = $this->db->select('myeventlane_rsvp', 'r')
      ->fields('r', ['created'])
      ->condition('event_nid', $nid)
      ->execute()
      ->fetchAll();

    $buckets = [];

    foreach ($results as $row) {
      $day = date('Y-m-d', $row->created);
      $buckets[$day] = ($buckets[$day] ?? 0) + 1;
    }

    return $buckets;
  }

}
