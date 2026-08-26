<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\myeventlane_capacity\Service\EventCapacityServiceInterface;

/**
 * Persists authoritative, expiring cart holds for finite ticket tiers.
 */
final class CartTicketTierHoldStore implements CartTicketTierHoldStoreInterface {

  private const TABLE = 'myeventlane_commerce_ticket_hold';

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly EventCapacityServiceInterface $eventCapacity,
  ) {}

  /**
   * Stable key for one order's hold against one ticket tier.
   */
  public static function reservationKey(int $orderId, int $eventId, int $tierId): string {
    return 'cart:' . $orderId . ':event:' . $eventId . ':tier:' . $tierId;
  }

  /**
   * Creates or refreshes one tier hold.
   */
  public function upsert(
    int $orderId,
    int $eventId,
    int $tierId,
    int $variationId,
    int $quantity,
    bool $countsTowardCapacity = TRUE,
    ?int $maximumExpiry = NULL,
  ): void {
    if ($orderId < 1 || $eventId < 1 || $tierId < 1 || $variationId < 1 || $quantity < 1) {
      throw new \InvalidArgumentException('A ticket-tier hold requires valid identifiers and quantity.');
    }

    $now = $this->time->getRequestTime();
    $expires = $now + $this->eventCapacity->getReservationTtl();
    if ($maximumExpiry !== NULL) {
      $expires = min($expires, $maximumExpiry);
    }

    $this->database->merge(self::TABLE)
      ->key('reservation_key', self::reservationKey($orderId, $eventId, $tierId))
      ->fields([
        'order_id' => $orderId,
        'event_id' => $eventId,
        'tier_id' => $tierId,
        'variation_id' => $variationId,
        'quantity' => $quantity,
        'counts_toward_capacity' => $countsTowardCapacity ? 1 : 0,
        'created' => $now,
        'expires' => $expires,
      ])
      ->execute();
  }

  /**
   * Removes holds no longer represented by an event's cart lines.
   *
   * @param int $orderId
   *   Commerce order ID.
   * @param int $eventId
   *   Event node ID.
   * @param string[] $validReservationKeys
   *   Reservation keys that must remain for the order and event.
   */
  public function releaseStaleForEvent(
    int $orderId,
    int $eventId,
    array $validReservationKeys,
  ): void {
    $query = $this->database->delete(self::TABLE)
      ->condition('order_id', $orderId)
      ->condition('event_id', $eventId);
    if ($validReservationKeys !== []) {
      $query->condition('reservation_key', array_values($validReservationKeys), 'NOT IN');
    }
    $query->execute();
  }

  /**
   * Returns one active tier hold.
   *
   * @return array{event_id: int, tier_id: int, variation_id: int, quantity: int, created: int, expires: int}|null
   *   Active hold metadata, or NULL when absent or expired.
   */
  public function getActive(int $orderId, int $eventId, int $tierId): ?array {
    $row = $this->database->select(self::TABLE, 'h')
      ->fields('h', [
        'event_id',
        'tier_id',
        'variation_id',
        'quantity',
        'created',
        'expires',
      ])
      ->condition('reservation_key', self::reservationKey($orderId, $eventId, $tierId))
      ->condition('expires', $this->time->getRequestTime(), '>')
      ->execute()
      ->fetchAssoc();
    if (!is_array($row)) {
      return NULL;
    }
    return array_map('intval', $row);
  }

  /**
   * Counts active public-pool cart holds for a ticket tier.
   */
  public function getHeldQuantity(
    int $eventId,
    int $tierId,
    ?string $excludedReservationKey = NULL,
  ): int {
    $query = $this->database->select(self::TABLE, 'h');
    $query->addExpression('COALESCE(SUM(quantity), 0)', 'held');
    $query
      ->condition('event_id', $eventId)
      ->condition('tier_id', $tierId)
      ->condition('counts_toward_capacity', 1)
      ->condition('expires', $this->time->getRequestTime(), '>');
    if ($excludedReservationKey !== NULL && $excludedReservationKey !== '') {
      $query->condition('reservation_key', $excludedReservationKey, '<>');
    }
    return (int) $query->execute()->fetchField();
  }

  /**
   * Releases all tier holds for one event on an order.
   */
  public function releaseEvent(int $orderId, int $eventId): void {
    if ($orderId < 1 || $eventId < 1) {
      return;
    }
    $this->database->delete(self::TABLE)
      ->condition('order_id', $orderId)
      ->condition('event_id', $eventId)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function purgeExpired(): int {
    return (int) $this->database->delete(self::TABLE)
      ->condition('expires', $this->time->getRequestTime(), '<=')
      ->execute();
  }

}
