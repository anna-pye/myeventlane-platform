<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Utility;

use Drupal\Core\Entity\Query\QueryInterface;

/**
 * Aligns "upcoming / ongoing" filtering with Views: start >= now OR end >= now.
 */
final class UpcomingEventEntityQueryHelper {

  /**
   * Adds a condition group: field_event_start >= now OR field_event_end >= now.
   *
   * @param int $requestTime
   *   The request time as a Unix timestamp (same as Views relative date "now").
   */
  public static function addStartOrEndInFutureOrOngoing(QueryInterface $query, int $requestTime): void {
    $now = date('Y-m-d\TH:i:s', $requestTime);
    $or = $query->orConditionGroup();
    $or->condition('field_event_start', $now, '>=');
    $or->condition('field_event_end', $now, '>=');
    $query->condition($or);
  }

}
