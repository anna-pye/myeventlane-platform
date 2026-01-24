<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_boost\BoostManager;

/**
 * Service for calculating trending scores for events.
 */
final class TrendingScoreService {

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ?BoostManager $boostManager = NULL,
  ) {}

  /**
   * Calculates trending score for an event.
   *
   * Uses canonical BoostManager to check boost status.
   *
   * @param int $event_nid
   *   The event node ID.
   *
   * @return int
   *   Trending score (recent RSVPs * 2 + boost bonus if active).
   */
  public function score(int $event_nid): int {
    $week_ago = $this->time->getRequestTime() - (7 * 86400);

    $recent = (int) $this->database->select('myeventlane_rsvp', 'r')
      ->condition('event_nid', $event_nid)
      ->condition('created', $week_ago, '>=')
      ->countQuery()
      ->execute()
      ->fetchField();

    // Check if event is actively boosted using canonical API.
    $boosted = FALSE;
    if ($this->boostManager) {
      try {
        $nodeStorage = $this->entityTypeManager->getStorage('node');
        $event = $nodeStorage->load($event_nid);
        if ($event && $event->bundle() === 'event') {
          $boosted = $this->boostManager->isBoosted($event);
        }
      }
      catch (\Exception) {
        // Entity manager not available or load failed.
      }
    }

    return ($recent * 2) + ($boosted ? 10 : 0);
  }

}
