<?php

declare(strict_types=1);

namespace Drupal\myeventlane_attendee\Attendee;

use Drupal\myeventlane_rsvp\Entity\RsvpSubmissionInterface;
use Drupal\user\AccountInterface;

/**
 * RSVP attendee adapter.
 */
final class RsvpAttendee implements AttendeeInterface {

  /**
   * Constructs the adapter.
   *
   * @param \Drupal\myeventlane_rsvp\Entity\RsvpSubmissionInterface $rsvp
   *   The RSVP submission entity.
   */
  public function __construct(
    private readonly RsvpSubmissionInterface $rsvp,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getEventId(): int {
    $eventId = $this->rsvp->getEventId();
    if ($eventId === NULL) {
      throw new \RuntimeException('RSVP submission has no event ID.');
    }
    return $eventId;
  }

  /**
   * {@inheritdoc}
   */
  public function getAttendeeId(): string {
    return 'rsvp:' . $this->rsvp->id();
  }

  /**
   * {@inheritdoc}
   */
  public function getDisplayName(): string {
    return $this->rsvp->getAttendeeName() ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function getEmail(): string {
    return $this->rsvp->getEmail() ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function getTicketLabel(): ?string {
    // RSVPs don't have ticket labels.
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function isCheckedIn(): bool {
    return $this->rsvp->isCheckedIn();
  }

  /**
   * {@inheritdoc}
   */
  public function getCheckedInAt(): ?\DateTimeInterface {
    if (!$this->isCheckedIn()) {
      return NULL;
    }

    $timestamp = $this->rsvp->get('checked_in_at')->value;
    if ($timestamp === NULL) {
      return NULL;
    }

    return \DateTime::createFromFormat('U', (string) $timestamp) ?: NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function checkIn(AccountInterface $actor): void {
    $this->rsvp->checkIn();
    $this->rsvp->save();
  }

  /**
   * {@inheritdoc}
   */
  public function undoCheckIn(AccountInterface $actor): void {
    $this->rsvp->set('checked_in', FALSE);
    $this->rsvp->set('checked_in_at', NULL);
    $this->rsvp->save();
  }

  /**
   * {@inheritdoc}
   */
  public function toExportRow(): array {
    $checkedInAt = $this->getCheckedInAt();
    $phone = '';
    if ($this->rsvp->hasField('phone') && !$this->rsvp->get('phone')->isEmpty()) {
      $phone = trim((string) ($this->rsvp->get('phone')->value ?? ''));
    }
    return [
      'name' => $this->getDisplayName(),
      'email' => $this->getEmail(),
      'phone' => $phone,
      'ticket_type' => NULL,
      'checked_in' => $this->isCheckedIn(),
      'checked_in_at' => $checkedInAt?->format('c'),
      'source' => 'rsvp',
      'ticket_code' => '',
      'custom_answers' => '',
    ];
  }

  /**
   * Gets the underlying RSVP entity.
   *
   * @return \Drupal\myeventlane_rsvp\Entity\RsvpSubmissionInterface
   *   The RSVP submission.
   */
  public function getRsvp(): RsvpSubmissionInterface {
    return $this->rsvp;
  }

}
