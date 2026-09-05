<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

/**
 * Stores expiring cart holds for finite operational add-on variations.
 */
interface OperationalStockHoldStoreInterface {

  /**
   * Creates or refreshes a hold for one order and variation.
   */
  public function upsert(int $orderId, int $variationId, int $quantity): void;

  /**
   * Counts active held units, optionally excluding one reservation.
   */
  public function getHeldQuantity(int $variationId, ?string $excludedReservationKey = NULL): int;

  /**
   * Gets the active quantity held by one order for one variation.
   */
  public function getActiveQuantity(int $orderId, int $variationId): int;

  /**
   * Releases order holds that are no longer represented by cart lines.
   *
   * @param int $orderId
   *   Commerce order ID.
   * @param string[] $validReservationKeys
   *   Reservation keys that must remain on the order.
   */
  public function releaseStaleForOrder(int $orderId, array $validReservationKeys): void;

  /**
   * Releases every operational stock hold for an order.
   */
  public function releaseOrder(int $orderId): void;

  /**
   * Deletes expired operational stock holds.
   */
  public function purgeExpired(): int;

}
