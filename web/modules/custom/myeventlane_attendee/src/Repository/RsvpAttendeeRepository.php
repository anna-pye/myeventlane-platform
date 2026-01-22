<?php

declare(strict_types=1);

namespace Drupal\myeventlane_attendee\Repository;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_attendee\Attendee\AttendeeInterface;
use Drupal\myeventlane_attendee\Attendee\RsvpAttendee;
use Drupal\node\NodeInterface;

/**
 * Repository for RSVP attendees.
 */
final class RsvpAttendeeRepository implements AttendeeRepositoryInterface {

  /**
   * Constructs the repository.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getSourceType(): string {
    return 'rsvp';
  }

  /**
   * {@inheritdoc}
   */
  public function supports(NodeInterface $event): bool {
    // Check if event supports RSVP.
    // @todo Check event type field to determine if RSVP is enabled.
    // For now, always support RSVP events.
    if (!$this->entityTypeManager->hasDefinition('rsvp_submission')) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function loadByEvent(NodeInterface $event): array {
    if (!$this->supports($event)) {
      return [];
    }

    try {
      $storage = $this->entityTypeManager->getStorage('rsvp_submission');
      $eventId = (int) $event->id();

      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('event_id', $eventId)
        ->condition('status', 'confirmed')
        ->execute();

      if (empty($ids)) {
        return [];
      }

      $rsvps = $storage->loadMultiple($ids);
      $attendees = [];

      foreach ($rsvps as $rsvp) {
        $attendees[] = new RsvpAttendee($rsvp);
      }

      return $attendees;
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function loadByIdentifier(NodeInterface $event, string $identifier): ?AttendeeInterface {
    if (!$this->supports($event)) {
      return NULL;
    }

    try {
      $storage = $this->entityTypeManager->getStorage('rsvp_submission');
      $eventId = (int) $event->id();

      // Try by ID first (format: "rsvp:123").
      if (str_starts_with($identifier, 'rsvp:')) {
        $rsvpId = (int) substr($identifier, 5);
        $rsvp = $storage->load($rsvpId);
        if ($rsvp && $rsvp->getEventId() === $eventId && $rsvp->getStatus() === 'confirmed') {
          return new RsvpAttendee($rsvp);
        }
        return NULL;
      }

      // Try by email.
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('event_id', $eventId)
        ->condition('status', 'confirmed')
        ->condition('email', $identifier)
        ->range(0, 1)
        ->execute();

      if (!empty($ids)) {
        $rsvp = $storage->load(reset($ids));
        if ($rsvp) {
          return new RsvpAttendee($rsvp);
        }
      }
    }
    catch (\Exception $e) {
      // Return NULL on error.
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function countByEvent(NodeInterface $event): int {
    if (!$this->supports($event)) {
      return 0;
    }

    try {
      $storage = $this->entityTypeManager->getStorage('rsvp_submission');
      $eventId = (int) $event->id();

      return (int) $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('event_id', $eventId)
        ->condition('status', 'confirmed')
        ->count()
        ->execute();
    }
    catch (\Exception $e) {
      return 0;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function countCheckedIn(NodeInterface $event): int {
    if (!$this->supports($event)) {
      return 0;
    }

    try {
      $storage = $this->entityTypeManager->getStorage('rsvp_submission');
      $eventId = (int) $event->id();

      return (int) $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('event_id', $eventId)
        ->condition('status', 'confirmed')
        ->condition('checked_in', TRUE)
        ->count()
        ->execute();
    }
    catch (\Exception $e) {
      return 0;
    }
  }

}
