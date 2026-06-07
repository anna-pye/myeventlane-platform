<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_boost\BoostManager;
use Drupal\node\NodeInterface;

/**
 * Service for boost status information.
 *
 * Provides structured boost status data with eligibility checks.
 * Uses BoostManager as the canonical source of truth.
 */
final class BoostStatusService {

  public function __construct(
    private readonly BoostManager $boostManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Gets boost statuses for an event.
   *
   * REQUIRED by MetricsAggregator.
   * Returns structured data with eligible, active, and reason keys.
   *
   * @param int $event_nid
   *   The event node ID.
   *
   * @return array
   *   Array with keys:
   *   - eligible: bool - Whether event is eligible for boosting
   *   - active: bool - Whether boost is currently active
   *   - reason: string|null - Reason if not eligible
   *   - types: array - Boost types available
   *   - expires: string|null - Expiration date if active
   */
  public function getBoostStatuses(int $event_nid): array {
    // Guard against invalid event ID.
    if ($event_nid <= 0) {
      return [
        'eligible' => FALSE,
        'active' => FALSE,
        'reason' => 'missing_event',
        'types' => [],
        'expires' => NULL,
      ];
    }

    try {
      $nodeStorage = $this->entityTypeManager->getStorage('node');
      $event = $nodeStorage->load($event_nid);

      if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
        return [
          'eligible' => FALSE,
          'active' => FALSE,
          'reason' => 'invalid_event',
          'types' => [],
          'expires' => NULL,
        ];
      }

      return $this->getBoostStatusesForEvent($event);
    }
    catch (\Exception $e) {
      // Return safe defaults on error.
      return [
        'eligible' => FALSE,
        'active' => FALSE,
        'reason' => 'error',
        'types' => [],
        'expires' => NULL,
      ];
    }
  }

  /**
   * Gets boost statuses for a loaded event node (avoids redundant entity load).
   *
   * @return array{
   *   eligible: bool,
   *   active: bool,
   *   reason: string|null,
   *   types: list<string>,
   *   expires: string|null,
   * }
   */
  public function getBoostStatusesForEvent(NodeInterface $event): array {
    if ($event->bundle() !== 'event') {
      return [
        'eligible' => FALSE,
        'active' => FALSE,
        'reason' => 'invalid_event',
        'types' => [],
        'expires' => NULL,
      ];
    }

    // Check if event is published (required for boost).
    $isPublished = $event->isPublished();
    if (!$isPublished) {
      return [
        'eligible' => FALSE,
        'active' => FALSE,
        'reason' => 'unpublished',
        'types' => [],
        'expires' => NULL,
      ];
    }

    // Use canonical API to get boost status.
    $boostStatus = $this->boostManager->getBoostStatusForEvent($event);

    // Event is eligible if published.
    // Additional eligibility checks (e.g., Stripe connection) should be
    // handled at the route access level.
    return [
      'eligible' => TRUE,
      'active' => $boostStatus['active'],
      'reason' => NULL,
      'types' => $this->getAvailableBoostTypes(),
      'expires' => $boostStatus['end_timestamp']
        ? date('Y-m-d\TH:i:s', $boostStatus['end_timestamp'])
        : NULL,
    ];
  }

  /**
   * Builds vendor-facing boost visibility payload for Twig / view models.
   *
   * @return array{
   *   active: bool,
   *   expires: string|null,
   *   days_remaining: int|null,
   * }
   */
  public function getVisibilityPayload(NodeInterface $event): array {
    if ($event->bundle() !== 'event') {
      return $this->emptyVisibilityPayload();
    }

    try {
      $boostStatus = $this->boostManager->getBoostStatusForEvent($event);
      return $this->formatVisibilityPayload($boostStatus);
    }
    catch (\Exception) {
      return $this->emptyVisibilityPayload();
    }
  }

  /**
   * Gets available boost types for events.
   *
   * @return array
   *   Array of boost type identifiers.
   */
  private function getAvailableBoostTypes(): array {
    // Default boost types. Can be extended based on configuration.
    return [
      'featured',
      'homepage',
      'category',
    ];
  }

  /**
   * @param array<string, mixed> $boostStatus
   *
   * @return array{
   *   active: bool,
   *   expires: string|null,
   *   days_remaining: int|null,
   * }
   */
  private function formatVisibilityPayload(array $boostStatus): array {
    $active = !empty($boostStatus['active']);
    $endTimestamp = $boostStatus['end_timestamp'] ?? NULL;

    if (!$active || $endTimestamp === NULL) {
      return $this->emptyVisibilityPayload();
    }

    $now = (int) $this->time->getRequestTime();
    $secondsRemaining = max(0, (int) $endTimestamp - $now);
    $daysRemaining = max(1, (int) ceil($secondsRemaining / 86400));

    return [
      'active' => TRUE,
      'expires' => $this->dateFormatter->format((int) $endTimestamp, 'custom', 'j M'),
      'days_remaining' => $daysRemaining,
    ];
  }

  /**
   * @return array{
   *   active: bool,
   *   expires: string|null,
   *   days_remaining: int|null,
   * }
   */
  private function emptyVisibilityPayload(): array {
    return [
      'active' => FALSE,
      'expires' => NULL,
      'days_remaining' => NULL,
    ];
  }

}
