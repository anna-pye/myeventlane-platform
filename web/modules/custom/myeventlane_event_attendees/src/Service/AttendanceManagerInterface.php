<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_attendees\Service;

use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\node\NodeInterface;

/**
 * Interface for the unified attendance management service.
 */
interface AttendanceManagerInterface {

  /**
   * Creates an attendance record.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param array $data
   *   Attendee data with keys:
   *   - name: (required) Attendee's full name.
   *   - email: (required) Attendee's email address.
   *   - phone: (optional) Phone number.
   *   - status: (optional) Status, defaults to 'confirmed'.
   *   - order_item: (optional) Commerce order item ID.
   *   - ticket_code: (optional) Pre-generated ticket code.
   *   - extra_data: (optional) Array of custom field values.
   *   - uid: (optional) User ID if attendee is registered.
   * @param string $source
   *   Source of attendance: 'rsvp', 'ticket', or 'manual'.
   *
   * @return \Drupal\myeventlane_event_attendees\Entity\EventAttendee
   *   The created attendance record.
   *
   * @throws \InvalidArgumentException
   *   If the node is not an event.
   */
  public function createAttendance(NodeInterface $event, array $data, string $source): EventAttendee;

  /**
   * Gets the count of attendees for an event.
   *
   * @param int $eventId
   *   The event node ID.
   * @param array $statuses
   *   Status values to include. Defaults to ['confirmed'].
   *
   * @return int
   *   The attendee count.
   */
  public function getAttendeeCount(int $eventId, array $statuses = [EventAttendee::STATUS_CONFIRMED]): int;

  /**
   * Checks if the event has available capacity.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return bool
   *   TRUE if spots are available or unlimited, FALSE if at capacity.
   */
  public function hasCapacity(NodeInterface $event): bool;

  /**
   * Gets detailed availability information for an event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Array with keys:
   *   - available: bool - Whether spots are available.
   *   - reason: string - Human-readable explanation.
   *   - capacity: int - Total capacity (0 = unlimited).
   *   - current_count: int - Current confirmed attendees.
   *   - remaining: int|null - Spots remaining (null if unlimited).
   */
  public function getAvailability(NodeInterface $event): array;

  /**
   * Gets all attendees for an event.
   *
   * @param int $eventId
   *   The event node ID.
   * @param string|null $status
   *   Optional status filter.
   * @param string|null $source
   *   Optional source filter.
   *
   * @return \Drupal\myeventlane_event_attendees\Entity\EventAttendee[]
   *   Array of attendee entities.
   */
  public function getAttendeesForEvent(int $eventId, ?string $status = NULL, ?string $source = NULL): array;

  /**
   * Finds an attendee by email for a specific event.
   *
   * @param int $eventId
   *   The event node ID.
   * @param string $email
   *   The email address to search for.
   *
   * @return \Drupal\myeventlane_event_attendees\Entity\EventAttendee|null
   *   The attendee entity or NULL if not found.
   */
  public function findByEmail(int $eventId, string $email): ?EventAttendee;

  /**
   * Finds an attendee by ticket code.
   *
   * @param string $ticketCode
   *   The ticket code.
   *
   * @return \Drupal\myeventlane_event_attendees\Entity\EventAttendee|null
   *   The attendee entity or NULL if not found.
   */
  public function findByTicketCode(string $ticketCode): ?EventAttendee;

  /**
   * Marks an attendee as checked in.
   *
   * @param \Drupal\myeventlane_event_attendees\Entity\EventAttendee $attendee
   *   The attendee to check in.
   *
   * @return bool
   *   TRUE if check-in was successful, FALSE if already checked in.
   */
  public function checkIn(EventAttendee $attendee): bool;

  /**
   * Cancels an attendance record.
   *
   * @param \Drupal\myeventlane_event_attendees\Entity\EventAttendee $attendee
   *   The attendee to cancel.
   *
   * @return bool
   *   TRUE if cancellation was successful, FALSE if already cancelled.
   */
  public function cancel(EventAttendee $attendee): bool;

  /**
   * Promotes the next waitlisted attendee to confirmed status.
   *
   * @param int $eventId
   *   The event node ID.
   *
   * @return \Drupal\myeventlane_event_attendees\Entity\EventAttendee|null
   *   The promoted attendee or NULL if no one is waitlisted.
   */
  public function promoteFromWaitlist(int $eventId): ?EventAttendee;

}
