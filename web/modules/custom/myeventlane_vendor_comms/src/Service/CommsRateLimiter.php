<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor_comms\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Rate limiting for vendor event communications.
 */
final class CommsRateLimiter {

  /**
   * Constructs CommsRateLimiter.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Checks if vendor can send a message for an event.
   *
   * @param int $eventId
   *   Event ID.
   * @param int $vendorUid
   *   Vendor user ID.
   *
   * @return array{allowed: bool, reason: string|null, count: int, limit: int}
   *   Rate limit check result.
   */
  public function checkRateLimit(int $eventId, int $vendorUid): array {
    $now = $this->time->getRequestTime();
    $oneHourAgo = $now - 3600;
    $oneDayAgo = $now - 86400;

    // Get configurable limits (defaults: 5 per hour, 20 per day per event).
    $hourlyLimit = \Drupal::config('myeventlane_vendor_comms.settings')->get('rate_limit_hourly') ?? 5;
    $dailyLimit = \Drupal::config('myeventlane_vendor_comms.settings')->get('rate_limit_daily') ?? 20;

    // Check hourly limit.
    $hourlyCount = $this->database->select('myeventlane_event_comms_log', 'log')
      ->condition('event_id', $eventId)
      ->condition('vendor_uid', $vendorUid)
      ->condition('sent_at', $oneHourAgo, '>=')
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($hourlyCount >= $hourlyLimit) {
      return [
        'allowed' => FALSE,
        'reason' => "Hourly limit reached ({$hourlyCount}/{$hourlyLimit}). Please wait before sending another message.",
        'count' => (int) $hourlyCount,
        'limit' => $hourlyLimit,
      ];
    }

    // Check daily limit.
    $dailyCount = $this->database->select('myeventlane_event_comms_log', 'log')
      ->condition('event_id', $eventId)
      ->condition('vendor_uid', $vendorUid)
      ->condition('sent_at', $oneDayAgo, '>=')
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($dailyCount >= $dailyLimit) {
      return [
        'allowed' => FALSE,
        'reason' => "Daily limit reached ({$dailyCount}/{$dailyLimit}). Please try again tomorrow.",
        'count' => (int) $dailyCount,
        'limit' => $dailyLimit,
      ];
    }

    return [
      'allowed' => TRUE,
      'reason' => NULL,
      'count' => (int) $dailyCount,
      'limit' => $dailyLimit,
    ];
  }

}
