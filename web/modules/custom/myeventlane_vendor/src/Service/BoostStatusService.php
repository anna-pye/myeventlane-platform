<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

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
      $nodeStorage = \Drupal::entityTypeManager()->getStorage('node');
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

}
