<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * RSVP stats provider for vendor console.
 *
 * Queries real RSVP submission data for accurate metrics.
 */
final class RsvpStatsService {

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns RSVP counts for an event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   RSVP summary with total, confirmed, pending, declined, checkins.
   */
  public function getRsvpSummary(NodeInterface $event): array {
    $eventId = (int) $event->id();

    $summary = [
      'total' => 0,
      'confirmed' => 0,
      'pending' => 0,
      'waitlisted' => 0,
      'cancelled' => 0,
      'checkins' => 0,
    ];

    try {
      $rsvpStorage = $this->entityTypeManager->getStorage('rsvp_submission');

      // Get all RSVPs for this event.
      $rsvps = $rsvpStorage->loadByProperties([
        'event_id' => $eventId,
      ]);

      foreach ($rsvps as $rsvp) {
        $summary['total']++;

        $status = $rsvp->get('status')->value ?? 'pending';
        if (isset($summary[$status])) {
          $summary[$status]++;
        }

        // Check for check-ins.
        if ($rsvp->hasField('checked_in') && $rsvp->get('checked_in')->value) {
          $summary['checkins']++;
        }
      }
    }
    catch (\Exception) {
      // RSVP module may not be available.
    }

    // Also check event_attendee entities for RSVP source.
    try {
      $attendeeStorage = $this->entityTypeManager->getStorage('event_attendee');
      $attendees = $attendeeStorage->loadByProperties([
        'event' => $eventId,
        'source' => 'rsvp',
      ]);

      // If we have attendee records but no RSVP submissions, use attendee data.
      if (empty($summary['total']) && !empty($attendees)) {
        foreach ($attendees as $attendee) {
          $summary['total']++;

          $status = $attendee->getStatus();
          if ($status === 'confirmed') {
            $summary['confirmed']++;
          }
          elseif ($status === 'pending') {
            $summary['pending']++;
          }
          elseif ($status === 'waitlisted') {
            $summary['waitlisted']++;
          }
          elseif ($status === 'cancelled') {
            $summary['cancelled']++;
          }

          if ($attendee->isCheckedIn()) {
            $summary['checkins']++;
          }
        }
      }
    }
    catch (\Exception) {
      // Event attendee module may not be available.
    }

    return $summary;
  }

  /**
   * Returns a daily RSVP series for charting.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Array of daily RSVP data for charting.
   */
  public function getDailyRsvpSeries(NodeInterface $event): array {
    $eventId = (int) $event->id();

    // Initialize last 14 days with zero values.
    $days = [];
    for ($i = 13; $i >= 0; $i--) {
      $date = date('Y-m-d', strtotime("-{$i} days"));
      $days[$date] = ['rsvps' => 0, 'checkins' => 0];
    }

    try {
      $rsvpStorage = $this->entityTypeManager->getStorage('rsvp_submission');
      $rsvps = $rsvpStorage->loadByProperties([
        'event_id' => $eventId,
      ]);

      foreach ($rsvps as $rsvp) {
        $created = $rsvp->getCreatedTime();
        $date = date('Y-m-d', (int) $created);

        if (isset($days[$date])) {
          $days[$date]['rsvps']++;

          if ($rsvp->hasField('checked_in') && $rsvp->get('checked_in')->value) {
            $days[$date]['checkins']++;
          }
        }
      }
    }
    catch (\Exception) {
      // RSVP module may not be available - try event_attendee.
      try {
        $attendeeStorage = $this->entityTypeManager->getStorage('event_attendee');
        $attendees = $attendeeStorage->loadByProperties([
          'event' => $eventId,
          'source' => 'rsvp',
        ]);

        foreach ($attendees as $attendee) {
          $created = $attendee->getCreatedTime();
          $date = date('Y-m-d', (int) $created);

          if (isset($days[$date])) {
            $days[$date]['rsvps']++;

            if ($attendee->isCheckedIn()) {
              $days[$date]['checkins']++;
            }
          }
        }
      }
      catch (\Exception) {
        // Neither module available.
      }
    }

    // Convert to series format.
    $series = [];
    foreach ($days as $date => $data) {
      $series[] = [
        'date' => date('M j', strtotime($date)),
        'rsvps' => $data['rsvps'],
        'checkins' => $data['checkins'],
      ];
    }

    return $series;
  }

  /**
   * Returns the top attendee segments (based on available data).
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Array of audience segments.
   */
  public function getAudienceSegments(NodeInterface $event): array {
    // This would require additional attendee profile data.
    // For now, return empty since we don't have demographic data.
    return [];
  }

  /**
   * Gets total RSVP count for a vendor (all events).
   *
   * @param int $userId
   *   The vendor user ID.
   *
   * @return int
   *   Total RSVP count.
   */
  public function getVendorRsvpCount(int $userId): int {
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

      // Count RSVPs for these events.
      $rsvpStorage = $this->entityTypeManager->getStorage('rsvp_submission');
      $total = (int) $rsvpStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('event_id', array_values($eventIds), 'IN')
        ->condition('status', 'confirmed')
        ->count()
        ->execute();
    }
    catch (\Exception) {
      // RSVP module may not be available.
    }

    return $total;
  }

}
