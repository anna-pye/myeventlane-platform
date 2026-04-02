<?php

namespace Drupal\myeventlane_rsvp\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;

/**
 * Repository service for querying user RSVP submissions.
 */
final class UserRsvpRepository {

  public function __construct(
    private readonly Connection $connection,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Load RSVP submissions for a specific user.
   *
   * @return array
   *   An array of RSVP submission rows.
   */
  public function loadByUser(AccountInterface $account): array {
    return $this->connection->select('rsvp_submission', 'r')
      ->fields('r')
      ->condition('user_id', $account->id())
      ->execute()
      ->fetchAllAssoc('id');
  }

  /**
   * Load RSVP submissions for a specific event node.
   *
   * @return array
   *   An array of RSVP submission rows.
   */
  public function loadByEvent(int $nid): array {
    return $this->connection->select('rsvp_submission', 'r')
      ->fields('r')
      ->condition('event_id', $nid)
      ->execute()
      ->fetchAllAssoc('id');
  }

  /**
   * Confirmed count: legacy rsvp_submission + Commerce-backed event_attendee (source rsvp).
   */
  public function countTotalConfirmedRsvpsForEvent(int $event_id): int {
    $legacyIds = $this->entityTypeManager->getStorage('rsvp_submission')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('event_id', $event_id)
      ->condition('status', 'confirmed')
      ->execute();
    $legacy = 0;
    if ($legacyIds) {
      $entities = $this->entityTypeManager->getStorage('rsvp_submission')->loadMultiple($legacyIds);
      foreach ($entities as $entity) {
        $qty = $entity->hasField('quantity')
          ? (int) ($entity->get('quantity')->value ?? 1)
          : (int) ($entity->get('guests')->value ?? 1);
        $legacy += $qty > 0 ? $qty : 1;
      }
    }

    $commerce = 0;
    if ($this->entityTypeManager->hasDefinition('event_attendee')) {
      $commerce = (int) $this->entityTypeManager->getStorage('event_attendee')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('event', $event_id)
        ->condition('source', EventAttendee::SOURCE_RSVP)
        ->condition('status', EventAttendee::STATUS_CONFIRMED)
        ->count()
        ->execute();
    }

    return $legacy + $commerce;
  }

  /**
   * Count Commerce RSVP attendees for an event with a given list status.
   */
  public function countCommerceRsvpAttendeesByStatus(int $event_id, string $status): int {
    if (!$this->entityTypeManager->hasDefinition('event_attendee')) {
      return 0;
    }
    return (int) $this->entityTypeManager->getStorage('event_attendee')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $event_id)
      ->condition('source', EventAttendee::SOURCE_RSVP)
      ->condition('status', $status)
      ->count()
      ->execute();
  }

  /**
   * Gets RSVP rows for an event in reporting format.
   *
   * Returns rows with first_name, last_name (split from attendee_name), email,
   * status, created, id, kind (legacy|commerce). Excludes cancelled.
   *
   * @param int $event_id
   *   The event node ID.
   *
   * @return array
   *   Indexed array of rows.
   */
  public function getEventRsvps(int $event_id): array {
    $legacy = $this->loadLegacyEventRsvpRows($event_id);
    $commerce = $this->loadCommerceRsvpRowsForEvent($event_id);
    $combined = array_merge($legacy, $commerce);
    usort($combined, static function (array $a, array $b): int {
      return ($a['created'] ?? 0) <=> ($b['created'] ?? 0);
    });
    return $combined;
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function loadLegacyEventRsvpRows(int $event_id): array {
    $ids = $this->entityTypeManager->getStorage('rsvp_submission')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('event_id', $event_id)
      ->condition('status', 'cancelled', '<>')
      ->sort('created', 'ASC')
      ->execute();

    if (empty($ids)) {
      return [];
    }

    $entities = $this->entityTypeManager->getStorage('rsvp_submission')->loadMultiple($ids);
    $out = [];
    foreach ($entities as $e) {
      $name = trim((string) ($e->get('attendee_name')->value ?? $e->get('name')->value ?? ''));
      $space = strpos($name, ' ');
      $first = $space !== FALSE ? substr($name, 0, $space) : $name;
      $last = $space !== FALSE ? substr($name, $space + 1) : '';
      $out[] = [
        'id' => (int) $e->id(),
        'kind' => 'legacy',
        'first_name' => $first,
        'last_name' => $last,
        'email' => (string) $e->get('email')->value,
        'status' => (string) ($e->get('status')->value ?? 'confirmed'),
        'created' => (int) $e->get('created')->value,
        'updated' => (int) $e->get('changed')->value,
        'is_updated' => (int) $e->get('changed')->value > (int) $e->get('created')->value,
        'quantity' => (int) (
          $e->hasField('quantity')
            ? (($e->get('quantity')->value ?? 1) > 0 ? $e->get('quantity')->value : 1)
            : (($e->get('guests')->value ?? 1) > 0 ? $e->get('guests')->value : 1)
        ),
      ];
    }
    return $out;
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function loadCommerceRsvpRowsForEvent(int $event_id): array {
    if (!$this->entityTypeManager->hasDefinition('event_attendee')) {
      return [];
    }

    $ids = $this->entityTypeManager->getStorage('event_attendee')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $event_id)
      ->condition('source', EventAttendee::SOURCE_RSVP)
      ->condition('status', EventAttendee::STATUS_CANCELLED, '<>')
      ->sort('created', 'ASC')
      ->execute();

    if (empty($ids)) {
      return [];
    }

    /** @var \Drupal\myeventlane_event_attendees\Entity\EventAttendee[] $entities */
    $entities = $this->entityTypeManager->getStorage('event_attendee')->loadMultiple($ids);
    $out = [];
    foreach ($entities as $e) {
      $name = trim((string) $e->label());
      $space = strpos($name, ' ');
      $first = $space !== FALSE ? substr($name, 0, $space) : $name;
      $last = $space !== FALSE ? substr($name, $space + 1) : '';
      $created = (int) ($e->get('created')->value ?? $e->getChangedTime());
      $out[] = [
        'id' => (int) $e->id(),
        'kind' => 'commerce',
        'first_name' => $first,
        'last_name' => $last,
        'email' => (string) ($e->get('email')->value ?? ''),
        'status' => (string) ($e->get('status')->value ?? EventAttendee::STATUS_CONFIRMED),
        'created' => $created,
        'updated' => (int) $e->getChangedTime(),
        'is_updated' => (int) $e->getChangedTime() > $created,
        'quantity' => 1,
      ];
    }
    return $out;
  }

  /**
   * Gets RSVP count for an event by status.
   *
   * @param int $event_id
   *   The event node ID.
   * @param string $status
   *   Status filter: confirmed, waitlist, or cancelled.
   *
   * @return int
   *   The count.
   */
  public function getEventRsvpCount(int $event_id, string $status): int {
    return (int) $this->entityTypeManager->getStorage('rsvp_submission')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('event_id', $event_id)
      ->condition('status', $status)
      ->count()
      ->execute();
  }

}
