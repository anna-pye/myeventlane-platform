<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

/**
 * Storage contract for authoritative, expiring cart ticket-tier holds.
 */
interface CartTicketTierHoldStoreInterface {

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
  ): void;

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
  ): void;

  /**
   * Returns one active tier hold.
   *
   * @return array{event_id: int, tier_id: int, variation_id: int, quantity: int, created: int, expires: int}|null
   *   Active hold metadata, or NULL when absent or expired.
   */
  public function getActive(int $orderId, int $eventId, int $tierId): ?array;

  /**
   * Counts active public-pool cart holds for a ticket tier.
   */
  public function getHeldQuantity(
    int $eventId,
    int $tierId,
    ?string $excludedReservationKey = NULL,
  ): int;

  /**
   * Releases all tier holds for one event on an order.
   */
  public function releaseEvent(int $orderId, int $eventId): void;

  /**
   * Removes expired tier holds and returns the number deleted.
   */
  public function purgeExpired(): int;

}
