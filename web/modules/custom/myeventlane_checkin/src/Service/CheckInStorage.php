<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkin\Service;

use Drupal\myeventlane_attendee\Service\AttendeeRepositoryResolver;
use Drupal\node\NodeInterface;

/**
 * Check-in storage service.
 */
final class CheckInStorage implements CheckInStorageInterface {

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly AttendeeRepositoryResolver $repositoryResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getAttendees(NodeInterface $event): array {
    $repository = $this->repositoryResolver->getRepository($event);
    $attendees = $repository->loadByEvent($event);

    // Convert to the format expected by the check-in UI.
    $result = [];
    foreach ($attendees as $attendee) {
      $attendeeId = $attendee->getAttendeeId();
      $checkedInAt = $attendee->getCheckedInAt();

      // Extract numeric ID from identifier (e.g., "rsvp:123" -> 123).
      $numericId = 0;
      $type = 'rsvp';
      if (str_starts_with($attendeeId, 'rsvp:')) {
        $numericId = (int) substr($attendeeId, 5);
        $type = 'rsvp';
      }
      elseif (str_starts_with($attendeeId, 'ticket:')) {
        $numericId = (int) substr($attendeeId, 7);
        $type = 'ticket';
      }

      $result[] = [
        'id' => $numericId,
        'identifier' => $attendeeId,
        'type' => $type,
        'name' => $attendee->getDisplayName(),
        'email' => $attendee->getEmail(),
        'checked_in' => $attendee->isCheckedIn(),
        'checked_in_at' => $checkedInAt?->getTimestamp(),
      // @todo Store actor ID if needed.
        'checked_in_by' => NULL,
      ];
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function toggleCheckIn(int $attendeeId, string $type, int $checkedInBy): bool {
    try {
      // Convert integer ID to attendee identifier format.
      $identifier = $type === 'rsvp' ? "rsvp:{$attendeeId}" : "ticket:{$attendeeId}";

      // Load the entity to get the event.
      $entityTypeManager = \Drupal::entityTypeManager();

      // Try RSVP first if type is rsvp.
      if ($type === 'rsvp') {
        $storage = $entityTypeManager->getStorage('rsvp_submission');
        $rsvp = $storage->load($attendeeId);
        if ($rsvp) {
          $eventId = $rsvp->getEventId();
          if ($eventId) {
            $event = $entityTypeManager->getStorage('node')->load($eventId);
            if ($event instanceof NodeInterface) {
              $repository = $this->repositoryResolver->getRepository($event);
              $attendee = $repository->loadByIdentifier($event, $identifier);
              if ($attendee) {
                $current = $attendee->isCheckedIn();
                $actor = $entityTypeManager->getStorage('user')->load($checkedInBy);
                if ($actor) {
                  if ($current) {
                    $attendee->undoCheckIn($actor);
                    return FALSE;
                  }
                  else {
                    $attendee->checkIn($actor);
                    return TRUE;
                  }
                }
              }
            }
          }
        }
      }
      else {
        // Try event_attendee.
        $storage = $entityTypeManager->getStorage('event_attendee');
        $attendeeEntity = $storage->load($attendeeId);
        if ($attendeeEntity && $attendeeEntity->hasField('event') && !$attendeeEntity->get('event')->isEmpty()) {
          $eventId = (int) $attendeeEntity->get('event')->target_id;
          $event = $entityTypeManager->getStorage('node')->load($eventId);
          if ($event instanceof NodeInterface) {
            $repository = $this->repositoryResolver->getRepository($event);
            $attendee = $repository->loadByIdentifier($event, $identifier);
            if ($attendee) {
              $current = $attendee->isCheckedIn();
              $actor = $entityTypeManager->getStorage('user')->load($checkedInBy);
              if ($actor) {
                if ($current) {
                  $attendee->undoCheckIn($actor);
                  return FALSE;
                }
                else {
                  $attendee->checkIn($actor);
                  return TRUE;
                }
              }
            }
          }
        }
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('myeventlane_checkin')->error('Failed to toggle check-in: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function searchAttendees(NodeInterface $event, string $query): array {
    $all = $this->getAttendees($event);
    $query = strtolower(trim($query));

    if (empty($query)) {
      return $all;
    }

    return array_filter($all, function ($attendee) use ($query) {
      $name = strtolower($attendee['name'] ?? '');
      $email = strtolower($attendee['email'] ?? '');
      return strpos($name, $query) !== FALSE || strpos($email, $query) !== FALSE;
    });
  }

}
