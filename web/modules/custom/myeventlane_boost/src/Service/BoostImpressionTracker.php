<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Tracks Boost impressions server-side.
 *
 * Increments impression counts when Boost placements are rendered.
 * One row per boost order item per placement.
 */
final class BoostImpressionTracker {

  /**
   * Constructs a BoostImpressionTracker.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Records an impression for a Boost placement.
   *
   * @param int $boost_order_item_id
   *   The Commerce order item ID (bundle: boost).
   * @param int $event_id
   *   The event node ID.
   * @param string $placement
   *   The placement identifier (e.g., homepage_discover, category_music).
   *
   * @return bool
   *   TRUE if impression was recorded, FALSE on error.
   */
  public function recordImpression(int $boost_order_item_id, int $event_id, string $placement): bool {
    if ($boost_order_item_id <= 0 || $event_id <= 0 || empty($placement)) {
      $this->logger->warning('Invalid impression tracking parameters', [
        'boost_order_item_id' => $boost_order_item_id,
        'event_id' => $event_id,
        'placement' => $placement,
      ]);
      return FALSE;
    }

    try {
      $now = $this->time->getRequestTime();

      $query = $this->database->merge('myeventlane_boost_stats')
        ->key([
          'boost_order_item_id' => $boost_order_item_id,
          'placement' => $placement,
        ])
        ->insertFields([
          'event_id' => $event_id,
          'impressions' => 1,
          'created' => $now,
          'changed' => $now,
        ])
        ->updateFields([
          'event_id' => $event_id,
          'changed' => $now,
        ]);
      $query->expression('impressions', 'impressions + 1');

      $query->execute();

      if ($this->database->schema()->tableExists('myeventlane_boost_stats_log')) {
        $this->database->insert('myeventlane_boost_stats_log')
          ->fields([
            'boost_order_item_id' => $boost_order_item_id,
            'event_id' => $event_id,
            'placement' => $placement,
            'kind' => 'impression',
            'occurred_at' => $now,
          ])
          ->execute();
      }

      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to record Boost impression', [
        'boost_order_item_id' => $boost_order_item_id,
        'event_id' => $event_id,
        'placement' => $placement,
        'error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

}
