<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Service for RSVP statistics.
 *
 * Provides defensive, predictable RSVP counts and summaries.
 */
final class RsvpStatsService {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Gets total RSVP count for a vendor across all their events.
   *
   * Counts: Confirmed RSVPs only (status='confirmed' or 'active').
   * Excludes: Draft events, cancelled/pending RSVPs.
   * Tables: rsvp_submission entity or legacy myeventlane_rsvp table.
   *
   * @param int $vendor_uid
   *   The vendor user ID.
   *
   * @return int
   *   Total RSVP count. Returns 0 if no RSVPs, invalid vendor, or on error.
   */
  public function getVendorRsvpCount(int $vendor_uid): int {
    if ($vendor_uid <= 0) {
      return 0;
    }

    try {
      // Get all published events owned by this vendor.
      // NOTE: Only published events are included in analytics.
      $eventIds = $this->entityTypeManager
        ->getStorage('node')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'event')
        ->condition('uid', $vendor_uid)
        ->condition('status', 1)
        ->execute();

      if (empty($eventIds)) {
        return 0;
      }

      $total = 0;
      foreach ($eventIds as $eventId) {
        $total += $this->getEventRsvpCount((int) $eventId);
      }

      return $total;
    }
    catch (\Exception $e) {
      return 0;
    }
  }

  /**
   * Gets RSVP count for a specific event.
   *
   * Counts: Confirmed RSVPs only (status='confirmed' or 'active').
   * Excludes: Cancelled/pending RSVPs.
   * Tables: rsvp_submission entity or legacy myeventlane_rsvp table.
   * NOTE: Does not check if event is published - caller should filter.
   *
   * @param int $event_nid
   *   The event node ID.
   *
   * @return int
   *   RSVP count. Returns 0 if no RSVPs, invalid event ID, or on error.
   */
  public function getEventRsvpCount(int $event_nid): int {
    if ($event_nid <= 0) {
      return 0;
    }

    try {
      // Try entity storage first (rsvp_submission).
      if ($this->entityTypeManager->hasDefinition('rsvp_submission')) {
        $storage = $this->entityTypeManager->getStorage('rsvp_submission');
        $count = (int) $storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('event_id', $event_nid)
          ->condition('status', 'confirmed')
          ->count()
          ->execute();
        if ($count > 0) {
          return $count;
        }
      }
    }
    catch (\Exception $e) {
      // Fallback to legacy table.
    }

    // Fallback: check legacy myeventlane_rsvp table.
    try {
      if ($this->database->schema()->tableExists('myeventlane_rsvp')) {
        $count = (int) $this->database->select('myeventlane_rsvp', 'r')
          ->condition('event_nid', $event_nid)
          ->condition('status', 'active')
          ->countQuery()
          ->execute()
          ->fetchField();
        return $count;
      }
    }
    catch (\Exception $e) {
      // Table doesn't exist or error.
    }

    return 0;
  }

  /**
   * Gets RSVP summary for an event.
   *
   * REQUIRED by MetricsAggregator.
   * Counts: Confirmed RSVPs only (status='confirmed' or 'active').
   * Excludes: Cancelled/pending RSVPs.
   * Must always return a stable structure.
   *
   * @param int $event_nid
   *   The event node ID.
   *
   * @return array
   *   Summary array with 'count' key (int). Never null, count defaults to 0.
   */
  public function getRsvpSummary(int $event_nid): array {
    return [
      'count' => $this->getEventRsvpCount($event_nid),
    ];
  }

  /**
   * Gets detailed RSVP stats for an event.
   *
   * REQUIRED by VendorDashboardController.
   * Counts: Confirmed RSVPs only (status='confirmed' or 'active').
   * Excludes: Cancelled/pending RSVPs.
   * Recent: RSVPs created in last 7 days (also confirmed only).
   *
   * @param int $event_nid
   *   The event node ID.
   *
   * @return array
   *   Array with 'total' (int) and 'recent' (int) keys. Never null, defaults to 0.
   */
  public function getStatsForEvent(int $event_nid): array {
    if ($event_nid <= 0) {
      return [
        'total' => 0,
        'recent' => 0,
      ];
    }

    $total = $this->getEventRsvpCount($event_nid);
    $recent = 0;

    // Calculate recent RSVPs (last 7 days).
    try {
      $sevenDaysAgo = $this->time->getRequestTime() - (7 * 24 * 60 * 60);

      // Try entity storage first.
      if ($this->entityTypeManager->hasDefinition('rsvp_submission')) {
        $storage = $this->entityTypeManager->getStorage('rsvp_submission');
        $recent = (int) $storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('event_id', $event_nid)
          ->condition('status', 'confirmed')
          ->condition('created', $sevenDaysAgo, '>=')
          ->count()
          ->execute();
      }
      else {
        // Fallback to legacy table.
        if ($this->database->schema()->tableExists('myeventlane_rsvp')) {
          $recent = (int) $this->database->select('myeventlane_rsvp', 'r')
            ->condition('event_nid', $event_nid)
            ->condition('status', 'active')
            ->condition('created', $sevenDaysAgo, '>=')
            ->countQuery()
            ->execute()
            ->fetchField();
        }
      }
    }
    catch (\Exception $e) {
      // Return 0 for recent on error.
    }

    return [
      'total' => $total,
      'recent' => $recent,
    ];
  }

  /**
   * Gets event RSVP summary (alias for getRsvpSummary for consistency).
   *
   * Counts: Confirmed RSVPs only (status='confirmed' or 'active').
   * Excludes: Cancelled/pending RSVPs.
   *
   * @param int $event_nid
   *   The event node ID.
   *
   * @return array
   *   Summary array with 'count' key (int). Never null, count defaults to 0.
   */
  public function getEventRsvpSummary(int $event_nid): array {
    return $this->getRsvpSummary($event_nid);
  }

}
