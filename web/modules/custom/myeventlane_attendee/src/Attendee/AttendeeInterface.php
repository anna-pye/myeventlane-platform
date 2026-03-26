<?php

declare(strict_types=1);

namespace Drupal\myeventlane_attendee\Attendee;

use Drupal\user\AccountInterface;

/**
 * Represents a single attendee, regardless of source (RSVP or ticket).
 */
interface AttendeeInterface {

  /**
   * Gets the event node ID.
   *
   * @return int
   *   The event node ID.
   */
  public function getEventId(): int;

  /**
   * Gets a stable, unique identifier for this attendee.
   *
   * @return string
   *   Identifier in format: "rsvp:{id}" or "ticket:{id}".
   */
  public function getAttendeeId(): string;

  /**
   * Gets the display name of the attendee.
   *
   * @return string
   *   The attendee's full name.
   */
  public function getDisplayName(): string;

  /**
   * Gets the attendee's email address.
   *
   * @return string
   *   The email address.
   */
  public function getEmail(): string;

  /**
   * Gets the ticket label/type, if applicable.
   *
   * @return string|null
   *   The ticket label (e.g., "Early Bird"), or NULL for RSVP attendees.
   */
  public function getTicketLabel(): ?string;

  /**
   * Checks if the attendee has checked in.
   *
   * @return bool
   *   TRUE if checked in.
   */
  public function isCheckedIn(): bool;

  /**
   * Gets the check-in timestamp.
   *
   * @return \DateTimeInterface|null
   *   The check-in time, or NULL if not checked in.
   */
  public function getCheckedInAt(): ?\DateTimeInterface;

  /**
   * Marks the attendee as checked in.
   *
   * @param \Drupal\user\AccountInterface $actor
   *   The user performing the check-in.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   If the save operation fails.
   */
  public function checkIn(AccountInterface $actor): void;

  /**
   * Undoes a check-in (marks as not checked in).
   *
   * @param \Drupal\user\AccountInterface $actor
   *   The user performing the undo.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   If the save operation fails.
   */
  public function undoCheckIn(AccountInterface $actor): void;

  /**
   * Converts the attendee to an export row array.
   *
   * @return array
   *   Associative array suitable for CSV export with keys:
   *   - name (string)
   *   - email (string)
   *   - phone (string)
   *   - ticket_type (string|null)
   *   - checked_in (bool)
   *   - checked_in_at (string|null, ISO 8601)
   *   - source (string: 'rsvp' or 'ticket')
   *   - ticket_code (string, optional; ticket source)
   *   - custom_answers (string; human-readable "Label: value" pairs for exports)
   */
  public function toExportRow(): array;

}
