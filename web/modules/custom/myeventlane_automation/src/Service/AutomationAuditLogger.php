<?php

declare(strict_types=1);

namespace Drupal\myeventlane_automation\Service;

use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Service for writing automation audit log entries.
 */
final class AutomationAuditLogger {

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Logs an automation action to the audit log.
   *
   * @param int|null $eventId
   *   The event ID (nullable for global actions).
   * @param string $action
   *   Action type (e.g., 'notification_sent', 'notification_failed').
   * @param string|null $notificationType
   *   Notification type if applicable.
   * @param string|null $recipientHash
   *   Hash of recipient identifier if applicable.
   * @param array|null $metadata
   *   Optional metadata.
   *
   * @return int
   *   The audit log entry ID.
   */
  public function log(
    ?int $eventId,
    string $action,
    ?string $notificationType = NULL,
    ?string $recipientHash = NULL,
    ?array $metadata = NULL,
  ): int {
    $fields = [
      'event_id' => $eventId,
      'action' => $action,
      'notification_type' => $notificationType,
      'recipient_hash' => $recipientHash,
      'created' => $this->time->getRequestTime(),
    ];

    if ($metadata !== NULL) {
      $fields['metadata'] = json_encode($metadata);
    }

    return (int) $this->database->insert('myeventlane_automation_audit')
      ->fields($fields)
      ->execute();
  }

}
