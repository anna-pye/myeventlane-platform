<?php

declare(strict_types=1);

namespace Drupal\myeventlane_attendee\Repository;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_attendee\Attendee\AttendeeInterface;
use Drupal\myeventlane_attendee\Attendee\TicketAttendee;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\node\NodeInterface;

/**
 * Repository for ticket attendees.
 */
final class TicketAttendeeRepository implements AttendeeRepositoryInterface {

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
    return 'ticket';
  }

  /**
   * {@inheritdoc}
   */
  public function supports(NodeInterface $event): bool {
    // Check if event supports paid tickets.
    // @todo Check event type field to determine if paid tickets are enabled.
    if (!$this->entityTypeManager->hasDefinition('event_attendee')) {
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
      $storage = $this->entityTypeManager->getStorage('event_attendee');
      $eventId = (int) $event->id();

      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('event', $eventId)
        ->condition('source', EventAttendee::SOURCE_TICKET)
        ->condition('status', EventAttendee::STATUS_CONFIRMED)
        ->execute();

      if (empty($ids)) {
        return [];
      }

      $attendees = $storage->loadMultiple($ids);
      $result = [];

      foreach ($attendees as $attendee) {
        if ($attendee instanceof EventAttendee) {
          $result[] = new TicketAttendee($attendee);
        }
      }

      return $result;
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
      $storage = $this->entityTypeManager->getStorage('event_attendee');
      $eventId = (int) $event->id();

      // Try by ID first (format: "ticket:123").
      if (str_starts_with($identifier, 'ticket:')) {
        $attendeeId = (int) substr($identifier, 7);
        $attendee = $storage->load($attendeeId);
        if ($attendee instanceof EventAttendee
          && $attendee->getEventId() === $eventId
          && $attendee->getSource() === EventAttendee::SOURCE_TICKET
          && $attendee->getStatus() === EventAttendee::STATUS_CONFIRMED) {
          return new TicketAttendee($attendee);
        }
        return NULL;
      }

      // Try by ticket code.
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('event', $eventId)
        ->condition('source', EventAttendee::SOURCE_TICKET)
        ->condition('status', EventAttendee::STATUS_CONFIRMED)
        ->condition('ticket_code', $identifier)
        ->range(0, 1)
        ->execute();

      if (!empty($ids)) {
        $attendee = $storage->load(reset($ids));
        if ($attendee instanceof EventAttendee) {
          return new TicketAttendee($attendee);
        }
      }

      // Try by email.
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('event', $eventId)
        ->condition('source', EventAttendee::SOURCE_TICKET)
        ->condition('status', EventAttendee::STATUS_CONFIRMED)
        ->condition('email', $identifier)
        ->range(0, 1)
        ->execute();

      if (!empty($ids)) {
        $attendee = $storage->load(reset($ids));
        if ($attendee instanceof EventAttendee) {
          return new TicketAttendee($attendee);
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
      $storage = $this->entityTypeManager->getStorage('event_attendee');
      $eventId = (int) $event->id();

      return (int) $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('event', $eventId)
        ->condition('source', EventAttendee::SOURCE_TICKET)
        ->condition('status', EventAttendee::STATUS_CONFIRMED)
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
      $storage = $this->entityTypeManager->getStorage('event_attendee');
      $eventId = (int) $event->id();

      return (int) $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('event', $eventId)
        ->condition('source', EventAttendee::SOURCE_TICKET)
        ->condition('status', EventAttendee::STATUS_CONFIRMED)
        ->condition('checked_in', TRUE)
        ->count()
        ->execute();
    }
    catch (\Exception $e) {
      return 0;
    }
  }

}
