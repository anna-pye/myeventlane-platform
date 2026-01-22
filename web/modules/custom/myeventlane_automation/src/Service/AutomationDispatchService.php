<?php

declare(strict_types=1);

namespace Drupal\myeventlane_automation\Service;

use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Service for managing automation dispatch records (idempotency).
 */
final class AutomationDispatchService {

  /**
   * Notification type constants.
   */
  const TYPE_SALES_OPEN = 'sales_open';
  const TYPE_REMINDER_24H = 'reminder_24h';
  const TYPE_REMINDER_2H = 'reminder_2h';
  const TYPE_WAITLIST_INVITE = 'waitlist_invite';
  const TYPE_EVENT_CANCELLED = 'event_cancelled';
  const TYPE_EXPORT_READY_CSV = 'export_ready_csv';
  const TYPE_EXPORT_READY_ICS = 'export_ready_ics';
  const TYPE_WEEKLY_CATEGORY_DIGEST = 'weekly_category_digest';

  /**
   * Status constants.
   */
  const STATUS_SCHEDULED = 'scheduled';
  const STATUS_SENT = 'sent';
  const STATUS_FAILED = 'failed';
  const STATUS_SKIPPED = 'skipped';

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Creates a dispatch record for idempotency.
   *
   * @param int|null $eventId
   *   The event ID (nullable for global notifications).
   * @param string $notificationType
   *   Notification type constant.
   * @param string $recipientHash
   *   Hash of recipient identifier.
   * @param int|null $scheduledFor
   *   Timestamp when scheduled (defaults to now).
   * @param array|null $metadata
   *   Optional metadata to store.
   *
   * @return int
   *   The dispatch ID.
   */
  public function createDispatch(
    ?int $eventId,
    string $notificationType,
    string $recipientHash,
    ?int $scheduledFor = NULL,
    ?array $metadata = NULL,
  ): int {
    $scheduledFor = $scheduledFor ?? $this->time->getRequestTime();

    $fields = [
      'event_id' => $eventId,
      'notification_type' => $notificationType,
      'recipient_hash' => $recipientHash,
      'scheduled_for' => $scheduledFor,
      'status' => self::STATUS_SCHEDULED,
      'attempts' => 0,
    ];

    if ($metadata !== NULL) {
      $fields['metadata'] = json_encode($metadata);
    }

    return (int) $this->database->insert('myeventlane_automation_dispatch')
      ->fields($fields)
      ->execute();
  }

  /**
   * Checks if a notification was already sent (idempotency check).
   *
   * @param int|null $eventId
   *   The event ID.
   * @param string $notificationType
   *   Notification type constant.
   * @param string $recipientHash
   *   Hash of recipient identifier.
   *
   * @return bool
   *   TRUE if already sent successfully.
   */
  public function isAlreadySent(?int $eventId, string $notificationType, string $recipientHash): bool {
    $query = $this->database->select('myeventlane_automation_dispatch', 'd')
      ->fields('d', ['id'])
      ->condition('notification_type', $notificationType)
      ->condition('recipient_hash', $recipientHash)
      ->condition('status', self::STATUS_SENT)
      ->range(0, 1);

    if ($eventId !== NULL) {
      $query->condition('event_id', $eventId);
    }
    else {
      $query->isNull('event_id');
    }

    $result = $query->execute()->fetchField();
    return (bool) $result;
  }

  /**
   * Marks a dispatch as sent.
   *
   * @param int $dispatchId
   *   The dispatch ID.
   *
   * @return bool
   *   TRUE on success.
   */
  public function markSent(int $dispatchId): bool {
    return (bool) $this->database->update('myeventlane_automation_dispatch')
      ->fields([
        'status' => self::STATUS_SENT,
        'sent_at' => $this->time->getRequestTime(),
        'attempts' => $this->database->expression('attempts + 1'),
      ])
      ->condition('id', $dispatchId)
      ->execute();
  }

  /**
   * Marks a dispatch as failed.
   *
   * @param int $dispatchId
   *   The dispatch ID.
   * @param string $error
   *   Error message.
   *
   * @return bool
   *   TRUE on success.
   */
  public function markFailed(int $dispatchId, string $error): bool {
    return (bool) $this->database->update('myeventlane_automation_dispatch')
      ->fields([
        'status' => self::STATUS_FAILED,
        'last_error' => $error,
        'attempts' => $this->database->expression('attempts + 1'),
      ])
      ->condition('id', $dispatchId)
      ->execute();
  }

  /**
   * Marks a dispatch as skipped.
   *
   * @param int $dispatchId
   *   The dispatch ID.
   * @param string $reason
   *   Reason for skipping.
   *
   * @return bool
   *   TRUE on success.
   */
  public function markSkipped(int $dispatchId, string $reason): bool {
    return (bool) $this->database->update('myeventlane_automation_dispatch')
      ->fields([
        'status' => self::STATUS_SKIPPED,
        'last_error' => $reason,
      ])
      ->condition('id', $dispatchId)
      ->execute();
  }

  /**
   * Gets dispatch records for an event and type.
   *
   * @param int|null $eventId
   *   The event ID.
   * @param string $notificationType
   *   Notification type constant.
   *
   * @return array
   *   Array of dispatch records.
   */
  public function getDispatches(?int $eventId, string $notificationType): array {
    $query = $this->database->select('myeventlane_automation_dispatch', 'd')
      ->fields('d')
      ->condition('notification_type', $notificationType)
      ->orderBy('scheduled_for', 'DESC');

    if ($eventId !== NULL) {
      $query->condition('event_id', $eventId);
    }
    else {
      $query->isNull('event_id');
    }

    return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Hashes a recipient identifier for privacy.
   *
   * @param string $identifier
   *   Email address or user ID.
   *
   * @return string
   *   SHA256 hash.
   */
  public function hashRecipient(string $identifier): string {
    return hash('sha256', strtolower(trim($identifier)));
  }

}
