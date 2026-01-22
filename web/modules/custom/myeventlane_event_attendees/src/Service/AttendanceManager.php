<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_attendees\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\node\NodeInterface;

/**
 * Unified attendance management service for MyEventLane.
 *
 * This service is the canonical source for all attendance operations, whether
 * from RSVP submissions or ticket purchases. Other modules should use this
 * service rather than directly manipulating the event_attendee entity.
 */
final class AttendanceManager implements AttendanceManagerInterface {

  use StringTranslationTrait;

  /**
   * Constructs AttendanceManager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function createAttendance(NodeInterface $event, array $data, string $source): EventAttendee {
    if ($event->bundle() !== 'event') {
      throw new \InvalidArgumentException('Node must be an event.');
    }

    $values = [
      'event' => $event->id(),
      'name' => $data['name'] ?? '',
      'email' => $data['email'] ?? '',
      'phone' => $data['phone'] ?? NULL,
      'status' => $data['status'] ?? EventAttendee::STATUS_CONFIRMED,
      'source' => $source,
      'order_item' => $data['order_item'] ?? NULL,
      'extra_data' => $data['extra_data'] ?? [],
    ];

    // Generate ticket code if not provided.
    if (!empty($data['ticket_code'])) {
      $values['ticket_code'] = $data['ticket_code'];
    }
    else {
      $values['ticket_code'] = $this->generateTicketCode();
    }

    // Set user reference if provided or from current user.
    if (!empty($data['uid'])) {
      $values['uid'] = $data['uid'];
    }

    /** @var \Drupal\myeventlane_event_attendees\Entity\EventAttendee $attendee */
    $attendee = $this->entityTypeManager
      ->getStorage('event_attendee')
      ->create($values);

    $attendee->save();

    return $attendee;
  }

  /**
   * {@inheritdoc}
   */
  public function getAttendeeCount(int $eventId, array $statuses = [EventAttendee::STATUS_CONFIRMED]): int {
    $query = $this->entityTypeManager
      ->getStorage('event_attendee')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $eventId);

    if (!empty($statuses)) {
      $query->condition('status', $statuses, 'IN');
    }

    return (int) $query->count()->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function hasCapacity(NodeInterface $event): bool {
    $availability = $this->getAvailability($event);
    return $availability['available'];
  }

  /**
   * {@inheritdoc}
   */
  public function getAvailability(NodeInterface $event): array {
    if ($event->bundle() !== 'event') {
      return [
        'available' => FALSE,
        'reason' => (string) $this->t('Not an event.'),
        'capacity' => 0,
        'current_count' => 0,
        'remaining' => 0,
      ];
    }

    $capacity = 0;
    if ($event->hasField('field_capacity') && !$event->get('field_capacity')->isEmpty()) {
      $capacity = (int) $event->get('field_capacity')->value;
    }

    // No capacity limit.
    if ($capacity <= 0) {
      return [
        'available' => TRUE,
        'reason' => (string) $this->t('Unlimited spots available.'),
        'capacity' => 0,
        'current_count' => $this->getAttendeeCount((int) $event->id()),
        'remaining' => NULL,
      ];
    }

    $currentCount = $this->getAttendeeCount((int) $event->id());
    $remaining = $capacity - $currentCount;

    if ($remaining <= 0) {
      return [
        'available' => FALSE,
        'reason' => (string) $this->t('This event is at capacity.'),
        'capacity' => $capacity,
        'current_count' => $currentCount,
        'remaining' => 0,
      ];
    }

    return [
      'available' => TRUE,
      'reason' => (string) $this->t('@count spots remaining.', ['@count' => $remaining]),
      'capacity' => $capacity,
      'current_count' => $currentCount,
      'remaining' => $remaining,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getAttendeesForEvent(int $eventId, ?string $status = NULL, ?string $source = NULL): array {
    $query = $this->entityTypeManager
      ->getStorage('event_attendee')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $eventId)
      ->sort('created', 'ASC');

    if ($status !== NULL) {
      $query->condition('status', $status);
    }

    if ($source !== NULL) {
      $query->condition('source', $source);
    }

    $ids = $query->execute();

    if (empty($ids)) {
      return [];
    }

    return $this->entityTypeManager
      ->getStorage('event_attendee')
      ->loadMultiple($ids);
  }

  /**
   * {@inheritdoc}
   */
  public function findByEmail(int $eventId, string $email): ?EventAttendee {
    $ids = $this->entityTypeManager
      ->getStorage('event_attendee')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $eventId)
      ->condition('email', $email)
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return NULL;
    }

    return $this->entityTypeManager
      ->getStorage('event_attendee')
      ->load(reset($ids));
  }

  /**
   * {@inheritdoc}
   */
  public function findByTicketCode(string $ticketCode): ?EventAttendee {
    $ids = $this->entityTypeManager
      ->getStorage('event_attendee')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('ticket_code', $ticketCode)
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return NULL;
    }

    return $this->entityTypeManager
      ->getStorage('event_attendee')
      ->load(reset($ids));
  }

  /**
   * {@inheritdoc}
   */
  public function checkIn(EventAttendee $attendee): bool {
    if ($attendee->isCheckedIn()) {
      return FALSE;
    }

    $attendee->checkIn();
    $attendee->save();

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function cancel(EventAttendee $attendee): bool {
    if ($attendee->getStatus() === EventAttendee::STATUS_CANCELLED) {
      return FALSE;
    }

    $attendee->setStatus(EventAttendee::STATUS_CANCELLED);
    $attendee->save();

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function promoteFromWaitlist(int $eventId): ?EventAttendee {
    // Get the first waitlisted attendee.
    $ids = $this->entityTypeManager
      ->getStorage('event_attendee')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $eventId)
      ->condition('status', EventAttendee::STATUS_WAITLIST)
      ->sort('created', 'ASC')
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return NULL;
    }

    /** @var \Drupal\myeventlane_event_attendees\Entity\EventAttendee $attendee */
    $attendee = $this->entityTypeManager
      ->getStorage('event_attendee')
      ->load(reset($ids));

    $attendee->setStatus(EventAttendee::STATUS_CONFIRMED);

    // Track promotion timestamp.
    $attendee->set('promoted_at', \Drupal::time()->getRequestTime());

    $attendee->save();

    // Send promotion notification if service is available.
    try {
      $notificationService = \Drupal::service('myeventlane_event_attendees.waitlist_notification');
      $event = $this->entityTypeManager->getStorage('node')->load($eventId);
      if ($event) {
        $notificationService->sendPromotionNotification($attendee, $event);
      }
    }
    catch (\Exception $e) {
      // Log error but don't fail promotion.
      \Drupal::logger('myeventlane_event_attendees')->error('Failed to send waitlist promotion notification: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return $attendee;
  }

  /**
   * Generates a unique ticket code.
   */
  private function generateTicketCode(): string {
    return strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
  }

}
