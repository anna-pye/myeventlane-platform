<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkin\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\myeventlane_attendee\Service\AttendeeRepositoryResolver;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Check-in storage service.
 */
final class CheckInStorage implements CheckInStorageInterface {

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly AttendeeRepositoryResolver $repositoryResolver,
    private readonly mixed $melAttendeeCheckinManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly CheckInAttendeeEventBinder $attendeeEventBinder,
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
  public function attendeeBelongsToEvent(NodeInterface $event, int $attendeeId, string $type): bool {
    return $this->attendeeEventBinder->belongsToEvent($event, $attendeeId, $type);
  }

  /**
   * {@inheritdoc}
   */
  public function toggleCheckIn(NodeInterface $event, int $attendeeId, string $type, int $checkedInBy): bool {
    if (!$this->attendeeBelongsToEvent($event, $attendeeId, $type)) {
      // Same outcome for missing and foreign IDs — no state change, no leak.
      throw new AccessDeniedHttpException();
    }

    try {
      // Convert integer ID to attendee identifier format.
      $identifier = $type === 'rsvp' ? "rsvp:{$attendeeId}" : "ticket:{$attendeeId}";

      // Try RSVP first if type is rsvp.
      if ($type === 'rsvp') {
        $storage = $this->entityTypeManager->getStorage('rsvp_submission');
        $rsvp = $storage->load($attendeeId);
        if ($rsvp && method_exists($rsvp, 'getEventId')) {
          $eventId = $rsvp->getEventId();
          if ($eventId && (int) $eventId === (int) $event->id()) {
            $loadedEvent = $this->entityTypeManager->getStorage('node')->load($eventId);
            if ($loadedEvent instanceof NodeInterface) {
              $repository = $this->repositoryResolver->getRepository($loadedEvent);
              $attendee = $repository->loadByIdentifier($loadedEvent, $identifier);
              if ($attendee) {
                $current = $attendee->isCheckedIn();
                $actor = $this->entityTypeManager->getStorage('user')->load($checkedInBy);
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
        $storage = $this->entityTypeManager->getStorage('event_attendee');
        $attendeeEntity = $storage->load($attendeeId);
        if ($attendeeEntity instanceof EventAttendee && $attendeeEntity->hasField('event') && !$attendeeEntity->get('event')->isEmpty()) {
          $eventId = (int) $attendeeEntity->get('event')->target_id;
          if ($eventId !== (int) $event->id()) {
            throw new AccessDeniedHttpException();
          }
          $loadedEvent = $this->entityTypeManager->getStorage('node')->load($eventId);
          if ($loadedEvent instanceof NodeInterface) {
            $actor = $this->entityTypeManager->getStorage('user')->load($checkedInBy);
            if ($actor && is_object($this->melAttendeeCheckinManager) && method_exists($this->melAttendeeCheckinManager, 'markManualOverride')) {
              $current = $attendeeEntity->isCheckedIn();
              $res = $this->melAttendeeCheckinManager->markManualOverride($attendeeEntity, $actor, !$current);
              if (!($res['success'] ?? FALSE)) {
                return $current;
              }
              $reloaded = $storage->load($attendeeId);
              return $reloaded instanceof EventAttendee && $reloaded->isCheckedIn();
            }
            $repository = $this->repositoryResolver->getRepository($loadedEvent);
            $attendee = $repository->loadByIdentifier($loadedEvent, $identifier);
            if ($attendee) {
              $current = $attendee->isCheckedIn();
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
    catch (AccessDeniedHttpException $e) {
      throw $e;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('myeventlane_checkin')->error('Failed to toggle check-in: @message', [
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
