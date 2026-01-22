<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;

/**
 * Audience + category insights for vendor console.
 *
 * Provides real data about event categories and attendee demographics
 * when available. Returns empty arrays when no tracking data exists.
 */
final class CategoryAudienceService {

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Returns taxonomy/category insights.
   *
   * Queries real event category data from taxonomy terms.
   *
   * @param \Drupal\node\NodeInterface|null $event
   *   The event node, or NULL for all vendor events.
   *
   * @return array
   *   Array of category breakdown with labels and values.
   */
  public function getCategoryBreakdown(?NodeInterface $event = NULL): array {
    try {
      $userId = (int) $this->currentUser->id();
      $nodeStorage = $this->entityTypeManager->getStorage('node');

      // Get events to analyze.
      if ($event) {
        $events = [$event];
      }
      else {
        $eventIds = $nodeStorage->getQuery()
          ->accessCheck(FALSE)
          ->condition('type', 'event')
          ->condition('uid', $userId)
          ->execute();

        if (empty($eventIds)) {
          return [];
        }
        $events = $nodeStorage->loadMultiple($eventIds);
      }

      // Count events by category.
      $categoryCounts = [];
      foreach ($events as $eventNode) {
        if ($eventNode->hasField('field_event_category') && !$eventNode->get('field_event_category')->isEmpty()) {
          $term = $eventNode->get('field_event_category')->entity;
          if ($term) {
            $termName = $term->label();
            $categoryCounts[$termName] = ($categoryCounts[$termName] ?? 0) + 1;
          }
        }
      }

      // Sort by count descending.
      arsort($categoryCounts);

      // Format for display.
      $breakdown = [];
      foreach ($categoryCounts as $label => $count) {
        $breakdown[] = [
          'label' => $label,
          'value' => $count,
        ];
      }

      return $breakdown;
    }
    catch (\Exception) {
      return [];
    }
  }

  /**
   * Returns audience geo/location insights.
   *
   * Currently returns empty as we don't collect geographic data from attendees.
   * Would require address data from ticket purchases or RSVP submissions.
   *
   * @param \Drupal\node\NodeInterface|null $event
   *   The event node, or NULL for all vendor events.
   *
   * @return array
   *   Array of geographic breakdown (empty until data tracking is implemented).
   */
  public function getGeoBreakdown(?NodeInterface $event = NULL): array {
    // Geographic data would require:
    // 1. Collecting attendee location data during registration/purchase
    // 2. Geocoding addresses to regions
    // This is not currently implemented, so return empty array.
    return [];
  }

  /**
   * Gets total attendee count across all vendor events.
   *
   * @param int $userId
   *   The vendor user ID.
   *
   * @return int
   *   Total attendee count.
   */
  public function getVendorAudienceCount(int $userId): int {
    $total = 0;

    try {
      // Get all events owned by this user.
      $nodeStorage = $this->entityTypeManager->getStorage('node');
      $eventIds = $nodeStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'event')
        ->condition('uid', $userId)
        ->execute();

      if (empty($eventIds)) {
        return 0;
      }

      // Count attendees for these events.
      $attendeeStorage = $this->entityTypeManager->getStorage('event_attendee');
      $total = (int) $attendeeStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('event', array_values($eventIds), 'IN')
        ->count()
        ->execute();
    }
    catch (\Exception) {
      // Attendee storage may not be available.
    }

    return $total;
  }

  /**
   * Gets attendees for the audience page.
   *
   * @param int $userId
   *   The vendor user ID.
   *
   * @return array
   *   Array of attendee data for display.
   */
  public function getVendorAudience(int $userId): array {
    $attendees = [];

    try {
      // Get all events owned by this user.
      $nodeStorage = $this->entityTypeManager->getStorage('node');
      $eventIds = $nodeStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'event')
        ->condition('uid', $userId)
        ->execute();

      if (empty($eventIds)) {
        return [];
      }

      // Load events for titles.
      $events = $nodeStorage->loadMultiple($eventIds);
      $eventTitles = [];
      foreach ($events as $event) {
        $eventTitles[$event->id()] = $event->label();
      }

      // Get attendees for these events.
      $attendeeStorage = $this->entityTypeManager->getStorage('event_attendee');
      $attendeeEntities = $attendeeStorage->loadByProperties([
        'event' => array_values($eventIds),
      ]);

      foreach ($attendeeEntities as $attendee) {
        $eventId = $attendee->get('event')->target_id;
        $attendees[] = [
          'name' => $attendee->getName(),
          'email' => $attendee->getEmail(),
          'event' => $eventTitles[$eventId] ?? 'Unknown Event',
          'event_id' => $eventId,
          'source' => ucfirst($attendee->getSource()),
          'status' => ucfirst($attendee->getStatus()),
          'created' => date('M j, Y', (int) $attendee->getCreatedTime()),
        ];
      }

      // Sort by most recent first.
      usort($attendees, fn($a, $b) => strtotime($b['created']) <=> strtotime($a['created']));

    }
    catch (\Exception) {
      // Attendee storage may not be available.
    }

    return $attendees;
  }

}
