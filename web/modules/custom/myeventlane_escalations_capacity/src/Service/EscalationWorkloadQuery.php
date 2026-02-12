<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_capacity\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Queries escalation workload metrics (aggregate counts and age stats).
 *
 * All queries use accessCheck(TRUE); access is enforced by route permission.
 * No individual staff data is returned.
 */
final class EscalationWorkloadQuery {

  /**
   * Statuses considered "resolved" (i.e. not open).
   */
  private const CLOSED_STATUSES = ['resolved', 'closed'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Returns workload metrics for the current activity window.
   *
   * @return array
   *   Associative array with keys:
   *   - open_count: int
   *   - waiting_on_staff_count: int
   *   - new_in_window_count: int
   *   - avg_age_days_open: float
   *   - oldest_open_age_days: int
   */
  public function getWorkloadMetrics(): array {
    $config = $this->configFactory->get('myeventlane_escalations_capacity.settings');
    $activity_window_days = (int) $config->get('activity_window_days') ?: 7;
    $window_start = $this->time->getRequestTime() - ($activity_window_days * 86400);

    return [
      'open_count' => $this->getOpenCount(),
      'waiting_on_staff_count' => $this->getWaitingOnStaffCount(),
      'new_in_window_count' => $this->getNewInWindowCount($window_start),
      'avg_age_days_open' => $this->getAverageAgeOpenDays(),
      'oldest_open_age_days' => $this->getOldestOpenAgeDays(),
    ];
  }

  /**
   * Returns workload metrics for a specific past window (for trend comparison).
   *
   * @param int $offset_days
   *   Number of days to shift the window back.
   * @param int $window_days
   *   Length of the window in days.
   *
   * @return array
   *   Associative array with the same keys as getWorkloadMetrics() but scoped
   *   to the offset window. Age stats are omitted (not meaningful for past windows).
   */
  public function getWorkloadMetricsForWindow(int $offset_days, int $window_days): array {
    $now = $this->time->getRequestTime();
    $window_end = $now - ($offset_days * 86400);
    $window_start = $window_end - ($window_days * 86400);

    return [
      'new_in_window_count' => $this->getNewInWindowCount($window_start, $window_end),
    ];
  }

  /**
   * Returns all open escalation IDs.
   *
   * @return int[]
   *   Array of escalation entity IDs.
   */
  public function getOpenEscalationIds(): array {
    $query = $this->entityTypeManager->getStorage('escalation')->getQuery();
    $query->accessCheck(TRUE);
    $query->condition('status', self::CLOSED_STATUSES, 'NOT IN');

    return array_map('intval', $query->execute());
  }

  /**
   * Counts open escalations (status not resolved/closed).
   */
  private function getOpenCount(): int {
    $query = $this->entityTypeManager->getStorage('escalation')->getQuery();
    $query->accessCheck(TRUE);
    $query->condition('status', self::CLOSED_STATUSES, 'NOT IN');
    $query->count();

    return (int) $query->execute();
  }

  /**
   * Counts open escalations where field_waiting_on = 'staff'.
   */
  private function getWaitingOnStaffCount(): int {
    $query = $this->entityTypeManager->getStorage('escalation')->getQuery();
    $query->accessCheck(TRUE);
    $query->condition('status', self::CLOSED_STATUSES, 'NOT IN');
    $query->condition('field_waiting_on', 'staff');
    $query->count();

    return (int) $query->execute();
  }

  /**
   * Counts escalations created within the given window.
   *
   * @param int $window_start
   *   Unix timestamp for window start.
   * @param int|null $window_end
   *   Unix timestamp for window end (defaults to now).
   */
  private function getNewInWindowCount(int $window_start, ?int $window_end = NULL): int {
    $window_end = $window_end ?? $this->time->getRequestTime();

    $query = $this->entityTypeManager->getStorage('escalation')->getQuery();
    $query->accessCheck(TRUE);
    $query->condition('created', $window_start, '>=');
    $query->condition('created', $window_end, '<=');
    $query->count();

    return (int) $query->execute();
  }

  /**
   * Computes the average age in days of all currently open escalations.
   */
  private function getAverageAgeOpenDays(): float {
    $ids = $this->getOpenEscalationIds();

    if (empty($ids)) {
      return 0.0;
    }

    $now = $this->time->getRequestTime();
    $storage = $this->entityTypeManager->getStorage('escalation');
    $total_age = 0;

    // Load in chunks to avoid memory issues.
    foreach (array_chunk($ids, 50) as $chunk) {
      $entities = $storage->loadMultiple($chunk);
      foreach ($entities as $entity) {
        $created = (int) $entity->get('created')->value;
        $total_age += ($now - $created);
      }
    }

    return round($total_age / count($ids) / 86400, 1);
  }

  /**
   * Returns the age in days of the oldest open escalation.
   */
  private function getOldestOpenAgeDays(): int {
    $query = $this->entityTypeManager->getStorage('escalation')->getQuery();
    $query->accessCheck(TRUE);
    $query->condition('status', self::CLOSED_STATUSES, 'NOT IN');
    $query->sort('created', 'ASC');
    $query->range(0, 1);

    $ids = $query->execute();

    if (empty($ids)) {
      return 0;
    }

    $entity = $this->entityTypeManager->getStorage('escalation')->load(reset($ids));
    if (!$entity) {
      return 0;
    }

    $created = (int) $entity->get('created')->value;
    $now = $this->time->getRequestTime();

    return (int) floor(($now - $created) / 86400);
  }

}
