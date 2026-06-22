<?php

declare(strict_types=1);

namespace Drupal\myeventlane_capacity\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\myeventlane_capacity\Exception\CapacityExceededException;
use Psr\Log\LoggerInterface;

/**
 * Capacity service for events.
 *
 * INVARIANT:
 * Ticket capacity MUST be enforced here (getCapacityTotal, getSoldCount,
 * assertCanBook). Do not rely on node edit form validation or UI state.
 * This protects Ticket UX (Phase 3A).
 *
 * assertCanBook() acquires a per-event database row lock, counts authoritative
 * sold tickets/RSVPs (never cache), includes active provisional reservations,
 * and writes a reservation row when capacity allows. Callers must pass a stable
 * reservation_key (e.g. cart:123:event:456) so repeated checks upsert the same
 * hold. Release reservations via releaseReservation() when the booking commits
 * or is abandoned.
 */
final class EventCapacityService implements EventCapacityServiceInterface {

  /**
   * Seconds before an uncommitted reservation stops counting toward capacity.
   */
  private const RESERVATION_TTL = 900;

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CacheBackendInterface $cache,
    private readonly LoggerInterface $logger,
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getCapacityTotal(NodeInterface $event): ?int {
    // Check field_event_capacity_total first.
    if ($event->hasField('field_event_capacity_total') && !$event->get('field_event_capacity_total')->isEmpty()) {
      $value = (int) $event->get('field_event_capacity_total')->value;
      return $value > 0 ? $value : NULL;
    }

    // Fallback to field_capacity if it exists.
    if ($event->hasField('field_capacity') && !$event->get('field_capacity')->isEmpty()) {
      $value = (int) $event->get('field_capacity')->value;
      return $value > 0 ? $value : NULL;
    }

    // No capacity set = unlimited.
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getSoldCount(NodeInterface $event): int {
    $cache_key = 'capacity_sold:' . $event->id();
    $cache = $this->cache->get($cache_key);
    if ($cache) {
      return $cache->data;
    }

    $count = $this->computeSoldCount($event);
    $this->cache->set($cache_key, $count, time() + 300, ['node:' . $event->id()]);
    return $count;
  }

  /**
   * Computes sold count without caching.
   */
  private function computeSoldCount(NodeInterface $event): int {
    $eventId = (int) $event->id();
    $count = 0;

    // Determine event type.
    $eventType = 'rsvp';
    if ($event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()) {
      $eventType = $event->get('field_event_type')->value ?? 'rsvp';
    }

    // Count RSVPs for RSVP events.
    if (in_array($eventType, ['rsvp', 'both'], TRUE)) {
      $count += $this->countRsvps($eventId);
    }

    // Count paid tickets for paid events.
    if (in_array($eventType, ['paid', 'both'], TRUE)) {
      $count += $this->countPaidTickets($eventId);
    }

    return $count;
  }

  /**
   * Counts confirmed RSVPs for an event.
   *
   * @throws \Throwable
   *   When RSVP storage is unavailable or the count query fails.
   */
  private function countRsvps(int $eventId): int {
    if (!$this->entityTypeManager->hasDefinition('rsvp_submission')) {
      $message = 'rsvp_submission entity type is not available.';
      $this->logger->error('Unable to count RSVPs for event @event_id: @message', [
        '@event_id' => $eventId,
        '@message' => $message,
      ]);
      throw new \RuntimeException($message);
    }

    try {
      $count = $this->entityTypeManager
        ->getStorage('rsvp_submission')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('event_id', $eventId)
        ->condition('status', 'confirmed')
        ->count()
        ->execute();

      return (int) $count;
    }
    catch (\Throwable $e) {
      $this->logger->error('Unable to count RSVPs for event @event_id: @message', [
        '@event_id' => $eventId,
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Counts paid tickets for an event.
   *
   * Uses order_id + load instead of OrderItem::getOrder() to avoid
   * undefined $entity on EntityReferenceFieldItemList when the reference
   * is not resolved (e.g. during OrderStorage::postLoad / OrderRefresh).
   */
  private function countPaidTickets(int $eventId): int {
    try {
      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
      $orderItems = $orderItemStorage->loadByProperties([
        'field_target_event' => $eventId,
      ]);

      $orderStorage = $this->entityTypeManager->getStorage('commerce_order');
      $count = 0;

      foreach ($orderItems as $item) {
        try {
          if ($item->get('order_id')->isEmpty()) {
            continue;
          }
          $orderId = $item->get('order_id')->target_id;
          if (!$orderId) {
            continue;
          }
          $order = $orderStorage->load($orderId);
          if ($order && $order->getState()->getId() === 'completed') {
            $count += (int) $item->getQuantity();
          }
        }
        catch (\Exception $e) {
          continue;
        }
      }

      return $count;
    }
    catch (\Exception $e) {
      return 0;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getRemaining(NodeInterface $event): ?int {
    $total = $this->getCapacityTotal($event);
    if ($total === NULL) {
      return NULL;
    }

    $sold = $this->getSoldCount($event);
    $remaining = $total - $sold;
    return max(0, $remaining);
  }

  /**
   * {@inheritdoc}
   */
  public function isSoldOut(NodeInterface $event): bool {
    $total = $this->getCapacityTotal($event);
    if ($total === NULL) {
      return FALSE;
    }

    $sold = $this->getSoldCount($event);
    return $sold >= $total;
  }

  /**
   * {@inheritdoc}
   */
  public function assertCanBook(NodeInterface $event, int $requested = 1, ?string $reservationKey = NULL): void {
    if ($requested <= 0) {
      return;
    }

    $capacity = $this->getCapacityTotal($event);
    if ($capacity === NULL) {
      return;
    }

    $eventId = (int) $event->id();
    $reservationKey = $this->normalizeReservationKey($eventId, $reservationKey);

    $transaction = $this->database->startTransaction();

    try {
      $this->ensureLockRow($eventId);
      $this->acquireEventLock($eventId);

      $now = $this->time->getRequestTime();
      $this->purgeExpiredReservations($eventId, $now);

      $sold = $this->computeSoldCount($event);
      $reserved = $this->sumActiveReservations($eventId, $now, $reservationKey);
      $remaining = $capacity - $sold - $reserved;

      if ($requested > $remaining) {
        if ($remaining <= 0) {
          throw new CapacityExceededException('This event is sold out.');
        }
        throw new CapacityExceededException("Only {$remaining} ticket(s) remaining.");
      }

      $this->database->merge('myeventlane_capacity_reservation')
        ->key('reservation_key', $reservationKey)
        ->fields([
          'event_id' => $eventId,
          'quantity' => $requested,
          'created' => $now,
          'expires' => $now + self::RESERVATION_TTL,
        ])
        ->execute();

      $this->database->update('myeventlane_capacity_lock')
        ->fields(['updated' => $now])
        ->condition('event_id', $eventId)
        ->execute();
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function releaseReservation(string $reservationKey): void {
    $reservationKey = trim($reservationKey);
    if ($reservationKey === '') {
      return;
    }

    $this->database->delete('myeventlane_capacity_reservation')
      ->condition('reservation_key', $reservationKey)
      ->execute();
  }

  /**
   * Ensures a lock row exists for the event before SELECT … FOR UPDATE.
   */
  private function ensureLockRow(int $eventId): void {
    $now = $this->time->getRequestTime();
    $this->database->merge('myeventlane_capacity_lock')
      ->key('event_id', $eventId)
      ->fields(['updated' => $now])
      ->execute();
  }

  /**
   * Acquires a row-level lock for the event.
   */
  private function acquireEventLock(int $eventId): void {
    $this->database->select('myeventlane_capacity_lock', 'l')
      ->fields('l', ['event_id'])
      ->condition('event_id', $eventId)
      ->forUpdate()
      ->execute();
  }

  /**
   * Sums non-expired reservation quantities for an event.
   *
   * @param string|null $excludeReservationKey
   *   Reservation key to exclude (replaced in the same transaction).
   */
  private function sumActiveReservations(int $eventId, int $now, ?string $excludeReservationKey = NULL): int {
    $query = $this->database->select('myeventlane_capacity_reservation', 'r')
      ->condition('event_id', $eventId)
      ->condition('expires', $now, '>');
    if ($excludeReservationKey !== NULL && $excludeReservationKey !== '') {
      $query->condition('reservation_key', $excludeReservationKey, '<>');
    }
    $query->addExpression('COALESCE(SUM([quantity]), 0)', 'total');
    $total = $query->execute()->fetchField();

    return (int) $total;
  }

  /**
   * Deletes expired reservations for an event.
   */
  private function purgeExpiredReservations(int $eventId, int $now): void {
    $this->database->delete('myeventlane_capacity_reservation')
      ->condition('event_id', $eventId)
      ->condition('expires', $now, '<=')
      ->execute();
  }

  /**
   * Normalizes or generates a reservation key.
   */
  private function normalizeReservationKey(int $eventId, ?string $reservationKey): string {
    $reservationKey = trim((string) $reservationKey);
    if ($reservationKey !== '') {
      return $reservationKey;
    }

    $generated = 'ephemeral:event:' . $eventId . ':' . uniqid('', TRUE);
    $this->logger->warning(
      'assertCanBook called without reservation_key for event @event_id; generated ephemeral key @key. Pass an explicit key (cart/order/rsvp) to avoid capacity leaks.',
      [
        '@event_id' => $eventId,
        '@key' => $generated,
      ]
    );
    return $generated;
  }

  /**
   * Invalidates capacity cache for an event.
   *
   * @param int $eventId
   *   The event node ID.
   */
  public function invalidateCache(int $eventId): void {
    $this->cache->delete('capacity_sold:' . $eventId);
    $this->cache->delete('event_state:' . $eventId);
  }

}
