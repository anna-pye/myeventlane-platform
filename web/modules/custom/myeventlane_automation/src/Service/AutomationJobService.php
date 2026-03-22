<?php

declare(strict_types=1);

namespace Drupal\myeventlane_automation\Service;

use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Tracks automation jobs for idempotency and audit.
 *
 * Prevents duplicate scheduling of the same automation for an event/window.
 */
final class AutomationJobService {

  private const STATUS_PENDING = 'pending';
  private const STATUS_PROCESSED = 'processed';
  private const STATUS_SKIPPED = 'skipped';

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Checks if an automation job was already scheduled for this event/window.
   *
   * @param int $eventId
   *   Event node ID.
   * @param string $automationKey
   *   Rule key (e.g. low_sales_boost, attendee_reminder).
   * @param string $recipientHash
   *   Hash of recipient for deduplication.
   * @param int $windowStart
   *   Start of time window (e.g. day boundary) to avoid re-scheduling.
   *
   * @return bool
   *   TRUE if already scheduled/processed.
   */
  public function isAlreadyScheduled(int $eventId, string $automationKey, string $recipientHash, int $windowStart): bool {
    $cutoff = $windowStart - (24 * 3600);
    $result = $this->database->select('myeventlane_automation_job', 'j')
      ->fields('j', ['id'])
      ->condition('event_id', $eventId)
      ->condition('automation_key', $automationKey)
      ->condition('recipient_hash', $recipientHash)
      ->condition('scheduled_at', $cutoff, '>=')
      ->range(0, 1)
      ->execute()
      ->fetchField();
    return (bool) $result;
  }

  /**
   * Creates an automation job record.
   *
   * @return int
   *   Job ID.
   */
  public function createJob(
    int $eventId,
    string $automationKey,
    string $recipientType,
    string $recipientHash,
    array $payload = [],
  ): int {
    $now = $this->time->getRequestTime();
    return (int) $this->database->insert('myeventlane_automation_job')
      ->fields([
        'event_id' => $eventId,
        'automation_key' => $automationKey,
        'recipient_type' => $recipientType,
        'recipient_hash' => $recipientHash,
        'status' => self::STATUS_PENDING,
        'scheduled_at' => $now,
        'processed_at' => NULL,
        'payload' => json_encode($payload),
        'created' => $now,
      ])
      ->execute();
  }

  /**
   * Marks a job as processed.
   */
  public function markProcessed(int $jobId): bool {
    return (bool) $this->database->update('myeventlane_automation_job')
      ->fields([
        'status' => self::STATUS_PROCESSED,
        'processed_at' => $this->time->getRequestTime(),
      ])
      ->condition('id', $jobId)
      ->execute();
  }

  /**
   * Marks a job as skipped.
   */
  public function markSkipped(int $jobId, string $reason = ''): bool {
    return (bool) $this->database->update('myeventlane_automation_job')
      ->fields([
        'status' => self::STATUS_SKIPPED,
        'processed_at' => $this->time->getRequestTime(),
        'metadata' => $reason,
      ])
      ->condition('id', $jobId)
      ->execute();
  }

  /**
   * Hashes a recipient identifier.
   */
  public function hashRecipient(string $identifier): string {
    return hash('sha256', strtolower(trim($identifier)));
  }

}
