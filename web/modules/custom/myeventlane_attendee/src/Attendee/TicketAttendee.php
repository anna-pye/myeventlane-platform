<?php

declare(strict_types=1);

namespace Drupal\myeventlane_attendee\Attendee;

use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\user\AccountInterface;

/**
 * Ticket attendee adapter.
 */
final class TicketAttendee implements AttendeeInterface {

  /**
   * Constructs the adapter.
   *
   * @param \Drupal\myeventlane_event_attendees\Entity\EventAttendee $attendee
   *   The event attendee entity.
   */
  public function __construct(
    private readonly EventAttendee $attendee,
  ) {
    // Ensure this is a ticket source.
    if ($attendee->getSource() !== EventAttendee::SOURCE_TICKET) {
      throw new \InvalidArgumentException('EventAttendee must have source "ticket".');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getEventId(): int {
    $eventId = $this->attendee->getEventId();
    if ($eventId === NULL) {
      throw new \RuntimeException('Event attendee has no event ID.');
    }
    return $eventId;
  }

  /**
   * {@inheritdoc}
   */
  public function getAttendeeId(): string {
    return 'ticket:' . $this->attendee->id();
  }

  /**
   * {@inheritdoc}
   */
  public function getDisplayName(): string {
    return $this->attendee->getName();
  }

  /**
   * {@inheritdoc}
   */
  public function getEmail(): string {
    return $this->attendee->getEmail();
  }

  /**
   * {@inheritdoc}
   */
  public function getTicketLabel(): ?string {
    // @todo Extract ticket type from order item / product variation.
    // For now, return NULL as we don't have direct access to the order item.
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function isCheckedIn(): bool {
    return $this->attendee->isCheckedIn();
  }

  /**
   * {@inheritdoc}
   */
  public function getCheckedInAt(): ?\DateTimeInterface {
    if (!$this->isCheckedIn()) {
      return NULL;
    }

    $timestamp = $this->attendee->get('checked_in_at')->value;
    if ($timestamp === NULL) {
      return NULL;
    }

    return \DateTime::createFromFormat('U', (string) $timestamp) ?: NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function checkIn(AccountInterface $actor): void {
    $this->attendee->checkIn();
    if ($this->attendee->hasField('checked_in_by')) {
      $this->attendee->set('checked_in_by', $actor->id());
    }
    $this->attendee->save();
  }

  /**
   * {@inheritdoc}
   */
  public function undoCheckIn(AccountInterface $actor): void {
    $this->attendee->set('checked_in', FALSE);
    $this->attendee->set('checked_in_at', NULL);
    if ($this->attendee->hasField('checked_in_by')) {
      $this->attendee->set('checked_in_by', NULL);
    }
    $this->attendee->save();
  }

  /**
   * {@inheritdoc}
   */
  public function toExportRow(): array {
    $checkedInAt = $this->getCheckedInAt();
    return [
      'name' => $this->getDisplayName(),
      'email' => $this->getEmail(),
      'ticket_type' => $this->getTicketLabel(),
      'checked_in' => $this->isCheckedIn(),
      'checked_in_at' => $checkedInAt?->format('c'),
      'source' => 'ticket',
      'ticket_code' => $this->attendee->getTicketCode(),
    ];
  }

  /**
   * Gets the underlying event attendee entity.
   *
   * @return \Drupal\myeventlane_event_attendees\Entity\EventAttendee
   *   The event attendee.
   */
  public function getEventAttendee(): EventAttendee {
    return $this->attendee;
  }

}
