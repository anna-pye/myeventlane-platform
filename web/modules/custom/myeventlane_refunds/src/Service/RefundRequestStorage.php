<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Service;

use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Storage for buyer-initiated refund requests.
 *
 * Minimal CRUD; no business logic. Reused by RefundProcessor.
 */
final class RefundRequestStorage {

  /**
   * Status: buyer submitted, awaiting vendor approval.
   */
  public const STATUS_REQUESTED = 'requested';

  /**
   * Status: vendor approved; refund queued for execution.
   */
  public const STATUS_APPROVED = 'approved';

  /**
   * Status: vendor rejected.
   */
  public const STATUS_REJECTED = 'rejected';

  /**
   * Status: Stripe refund completed.
   */
  public const STATUS_COMPLETED = 'completed';

  /**
   * Constructs RefundRequestStorage.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Creates a refund request.
   *
   * @return int
   *   The refund request ID.
   */
  public function create(array $fields): int {
    $fields['created'] = $fields['created'] ?? $this->time->getRequestTime();
    $id = $this->database->insert('myeventlane_refund_request')
      ->fields($fields)
      ->execute();
    return (int) $id;
  }

  /**
   * Loads a refund request by ID.
   *
   * @return array|null
   *   The row or NULL.
   */
  public function load(int $id): ?array {
    $row = $this->database->select('myeventlane_refund_request', 'r')
      ->fields('r')
      ->condition('id', $id)
      ->execute()
      ->fetchAssoc();
    return $row ?: NULL;
  }

  /**
   * Updates a refund request.
   */
  public function update(int $id, array $fields): void {
    $fields['updated'] = $this->time->getRequestTime();
    $this->database->update('myeventlane_refund_request')
      ->fields($fields)
      ->condition('id', $id)
      ->execute();
  }

  /**
   * Loads pending refund requests for an event.
   *
   * @return array
   *   Rows with status = requested.
   */
  public function loadPendingByEvent(int $eventId): array {
    return $this->database->select('myeventlane_refund_request', 'r')
      ->fields('r')
      ->condition('event_id', $eventId)
      ->condition('status', self::STATUS_REQUESTED)
      ->orderBy('created', 'DESC')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

}
