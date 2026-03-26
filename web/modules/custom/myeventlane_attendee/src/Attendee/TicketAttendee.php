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
    private readonly string $resolvedTicketTypeLabel = '',
    private readonly string $resolvedPhone = '',
    private readonly string $resolvedCustomAnswersCsv = '',
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
    return $this->resolvedTicketTypeLabel !== '' ? $this->resolvedTicketTypeLabel : NULL;
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
    $custom = $this->resolvedCustomAnswersCsv !== ''
      ? $this->resolvedCustomAnswersCsv
      : self::formatExtraDataForCsv($this->attendee);
    return [
      'name' => $this->getDisplayName(),
      'email' => $this->getEmail(),
      'phone' => $this->resolvedPhone,
      'ticket_type' => $this->resolvedTicketTypeLabel !== '' ? $this->resolvedTicketTypeLabel : NULL,
      'checked_in' => $this->isCheckedIn(),
      'checked_in_at' => $checkedInAt?->format('c'),
      'source' => 'ticket',
      'ticket_code' => $this->attendee->getTicketCode(),
      'custom_answers' => $custom,
    ];
  }

  /**
   * Flattens event_attendee.extra_data for CSV (aligned with vendor list wording).
   */
  private static function formatExtraDataForCsv(EventAttendee $attendee): string {
    if (!$attendee->hasField('extra_data') || $attendee->get('extra_data')->isEmpty()) {
      return '';
    }
    $map = $attendee->getExtraDataMap();
    if ($map === []) {
      return '';
    }
    $parts = [];
    foreach ($map as $key => $item) {
      if (is_array($item) && isset($item['label'], $item['value'])) {
        $parts[] = trim((string) $item['label']) . ': ' . trim((string) $item['value']);
      }
      else {
        $parts[] = (string) $key . ': ' . trim((string) $item);
      }
    }
    return implode('; ', array_filter($parts, static fn ($p) => $p !== ''));
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
