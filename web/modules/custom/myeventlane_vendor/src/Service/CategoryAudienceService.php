<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\Service\EntityIdNormalizer;
use Drupal\myeventlane_event_studio\DTO\MelEventData;
use Drupal\myeventlane_event_studio\Service\EventRepository;
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
    private readonly EntityIdNormalizer $entityIdNormalizer,
    private readonly ?EventRepository $eventRepository,
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
    if ($this->eventRepository === NULL) {
      return [];
    }
    try {
      $userId = (int) $this->currentUser->id();
      $nodeStorage = $this->entityTypeManager->getStorage('node');

      $eventDtos = [];
      if ($event instanceof NodeInterface) {
        $one = $this->eventRepository->load((int) $event->id());
        if ($one instanceof MelEventData) {
          $eventDtos[] = $one;
        }
      }
      else {
        $eventIds = $nodeStorage->getQuery()
          ->accessCheck(FALSE)
          ->condition('type', 'event')
          ->condition('uid', $userId)
          ->execute();

        $eventIds = $this->entityIdNormalizer->normalizeNodeIds(array_values($eventIds));
        if ($eventIds === []) {
          return [];
        }
        $eventDtos = $this->eventRepository->loadMany($eventIds);
      }

      // Count events by category.
      $categoryCounts = [];
      foreach ($eventDtos as $dto) {
        $label = $dto->category_label;
        if ($label !== NULL && $label !== '') {
          $categoryCounts[$label] = ($categoryCounts[$label] ?? 0) + 1;
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

      $eventIds = $this->entityIdNormalizer->normalizeNodeIds(array_values($eventIds));
      if ($eventIds === []) {
        return 0;
      }

      // Count attendees for these events.
      $attendeeStorage = $this->entityTypeManager->getStorage('event_attendee');
      $total = (int) $attendeeStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('event', $eventIds, 'IN')
        ->count()
        ->execute();
    }
    catch (\Exception) {
      // Attendee storage may not be available.
    }

    return $total;
  }

  /**
   * Gets audience summary: total attendees and repeat attendees.
   *
   * Uses event_attendee and rsvp_submission to count unique attendees.
   * Repeat = attendees (by email) who appear in 2+ vendor events.
   *
   * @param int $userId
   *   The vendor user ID.
   *
   * @return array{total_attendees: int, repeat_attendees: int}
   *   Summary with total and repeat counts.
   */
  public function getAudienceSummary(int $userId): array {
    $total = 0;
    $repeat = 0;

    try {
      $nodeStorage = $this->entityTypeManager->getStorage('node');
      $eventIds = $nodeStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'event')
        ->condition('uid', $userId)
        ->execute();

      $eventIds = $this->entityIdNormalizer->normalizeNodeIds(array_values($eventIds));
      if ($eventIds === []) {
        return ['total_attendees' => 0, 'repeat_attendees' => 0];
      }

      $emailToEvents = [];

      // event_attendee: email → set of event IDs.
      if ($this->entityTypeManager->hasDefinition('event_attendee')) {
        $attendeeStorage = $this->entityTypeManager->getStorage('event_attendee');
        $attendees = $attendeeStorage->loadByProperties(['event' => $eventIds]);
        foreach ($attendees as $attendee) {
          $email = $attendee->getEmail();
          if ($email !== NULL && $email !== '') {
            $email = strtolower(trim($email));
            $eid = (int) ($attendee->get('event')->target_id ?? 0);
            if ($eid > 0) {
              $emailToEvents[$email] = $emailToEvents[$email] ?? [];
              $emailToEvents[$email][$eid] = TRUE;
            }
          }
        }
      }

      // rsvp_submission: include RSVPs not yet in event_attendee (best effort).
      if ($this->entityTypeManager->hasDefinition('rsvp_submission')) {
        $rsvpStorage = $this->entityTypeManager->getStorage('rsvp_submission');
        $eventRefField = 'event_id';
        $rsvpIds = $rsvpStorage->getQuery()
          ->accessCheck(FALSE)
          ->condition($eventRefField, $eventIds, 'IN')
          ->condition('status', 'confirmed')
          ->execute();
        $rsvpIds = $this->entityIdNormalizer->normalizeEntityIds(array_values($rsvpIds));
        foreach (($rsvpIds === [] ? [] : $rsvpStorage->loadMultiple($rsvpIds)) as $rsvp) {
          $email = NULL;
          if ($rsvp->hasField('email') && !$rsvp->get('email')->isEmpty()) {
            $email = $rsvp->get('email')->value;
          }
          elseif ($rsvp->hasField('attendee_email') && !$rsvp->get('attendee_email')->isEmpty()) {
            $email = $rsvp->get('attendee_email')->value;
          }
          if ($email !== NULL && $email !== '') {
            $email = strtolower(trim($email));
            $eid = (int) ($rsvp->get($eventRefField)->target_id ?? 0);
            if ($eid > 0) {
              $emailToEvents[$email] = $emailToEvents[$email] ?? [];
              $emailToEvents[$email][$eid] = TRUE;
            }
          }
        }
      }

      $total = count($emailToEvents);
      foreach ($emailToEvents as $events) {
        if (count($events) >= 2) {
          $repeat++;
        }
      }
    }
    catch (\Exception) {
      // Return zeros on error.
    }

    return [
      'total_attendees' => $total,
      'repeat_attendees' => $repeat,
    ];
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

      $eventIds = $this->entityIdNormalizer->normalizeNodeIds(array_values($eventIds));
      if ($eventIds === []) {
        return [];
      }

      $eventTitles = [];
      if ($this->eventRepository !== NULL) {
        foreach ($this->eventRepository->loadMany($eventIds) as $dto) {
          $eventTitles[$dto->id] = $dto->title;
        }
      }

      // Get attendees for these events.
      $attendeeStorage = $this->entityTypeManager->getStorage('event_attendee');
      $attendeeEntities = $attendeeStorage->loadByProperties([
        'event' => $eventIds,
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
