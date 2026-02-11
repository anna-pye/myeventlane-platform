<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_refunds\Repository;

use Drupal\Core\Database\Connection;

/**
 * Queries myeventlane_refund_log and myeventlane_refund_request tables.
 *
 * READ-ONLY. All methods return associative arrays.
 * Gracefully returns empty arrays if tables do not exist.
 */
final class RefundsCorrelationRepository {

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * Loads refund log entries by order ID.
   *
   * @param int $order_id
   *   Commerce order ID.
   *
   * @return array
   *   Refund log rows as associative arrays.
   */
  public function findLogsByOrderId(int $order_id): array {
    return $this->queryTable('myeventlane_refund_log', 'order_id', $order_id);
  }

  /**
   * Loads refund log entries by event ID.
   *
   * @param int $event_id
   *   Event node ID.
   *
   * @return array
   *   Refund log rows as associative arrays.
   */
  public function findLogsByEventId(int $event_id): array {
    return $this->queryTable('myeventlane_refund_log', 'event_id', $event_id);
  }

  /**
   * Loads refund requests by order ID.
   *
   * @param int $order_id
   *   Commerce order ID.
   *
   * @return array
   *   Refund request rows as associative arrays.
   */
  public function findRequestsByOrderId(int $order_id): array {
    return $this->queryTable('myeventlane_refund_request', 'order_id', $order_id);
  }

  /**
   * Loads refund requests by event ID.
   *
   * @param int $event_id
   *   Event node ID.
   *
   * @return array
   *   Refund request rows as associative arrays.
   */
  public function findRequestsByEventId(int $event_id): array {
    return $this->queryTable('myeventlane_refund_request', 'event_id', $event_id);
  }

  /**
   * Loads vendor-level refund summary for a date window.
   *
   * @param int $vendor_uid
   *   The vendor owner's user ID.
   * @param int $window_days
   *   Number of days to look back.
   *
   * @return array{logs: array, requests: array}
   *   Vendor-scoped refund data.
   */
  public function findVendorSummary(int $vendor_uid, int $window_days = 90): array {
    $cutoff = time() - ($window_days * 86400);

    return [
      'logs' => $this->queryVendorTable('myeventlane_refund_log', $vendor_uid, $cutoff),
      'requests' => $this->queryVendorTable('myeventlane_refund_request', $vendor_uid, $cutoff),
    ];
  }

  /**
   * Queries a refund table by a single column match.
   */
  private function queryTable(string $table, string $column, int $value): array {
    try {
      if (!$this->database->schema()->tableExists($table)) {
        return [];
      }

      return $this->database->select($table, 't')
        ->fields('t')
        ->condition('t.' . $column, $value)
        ->orderBy('t.created', 'DESC')
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * Queries a refund table by vendor_uid with a created cutoff.
   */
  private function queryVendorTable(string $table, int $vendor_uid, int $cutoff): array {
    try {
      if (!$this->database->schema()->tableExists($table)) {
        return [];
      }

      return $this->database->select($table, 't')
        ->fields('t')
        ->condition('t.vendor_uid', $vendor_uid)
        ->condition('t.created', $cutoff, '>=')
        ->orderBy('t.created', 'DESC')
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);
    }
    catch (\Throwable) {
      return [];
    }
  }

}
