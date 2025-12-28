<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Service for boost status information.
 *
 * Provides structured boost status data with eligibility checks.
 */
final class BoostStatusService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
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

      // Check if boost is currently active.
      $promoted = (bool) ($event->get('field_promoted')->value ?? FALSE);
      $expiresValue = $event->get('field_promo_expires')->value ?? NULL;
      $isActive = FALSE;

      if ($promoted && $expiresValue) {
        try {
          $expires = new \DateTimeImmutable($expiresValue, new \DateTimeZone('UTC'));
          $now = new \DateTimeImmutable('@' . $this->time->getRequestTime());
          $isActive = $expires > $now;
        }
        catch (\Exception $e) {
          // Invalid date format.
          $isActive = FALSE;
        }
      }

      // Event is eligible if published.
      // Additional eligibility checks (e.g., Stripe connection) should be
      // handled at the route access level.
      return [
        'eligible' => TRUE,
        'active' => $isActive,
        'reason' => NULL,
        'types' => $this->getAvailableBoostTypes(),
        'expires' => $expiresValue,
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
