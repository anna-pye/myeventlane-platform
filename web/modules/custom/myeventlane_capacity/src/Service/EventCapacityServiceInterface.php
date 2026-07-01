<?php

declare(strict_types=1);

namespace Drupal\myeventlane_capacity\Service;

use Drupal\node\NodeInterface;

/**
 * Interface for event capacity management.
 */
interface EventCapacityServiceInterface {

  /**
   * Gets the total capacity for an event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return int|null
   *   Total capacity, or NULL if unlimited.
   */
  public function getCapacityTotal(NodeInterface $event): ?int;

  /**
   * Gets the number of tickets/RSVPs sold/confirmed.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return int
   *   Count of sold/confirmed tickets/RSVPs.
   */
  public function getSoldCount(NodeInterface $event): int;

  /**
   * Gets the remaining capacity.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return int|null
   *   Remaining capacity, or NULL if unlimited.
   */
  public function getRemaining(NodeInterface $event): ?int;

  /**
   * Checks if the event is sold out.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return bool
   *   TRUE if sold out.
   */
  public function isSoldOut(NodeInterface $event): bool;

  /**
   * Asserts that the event can accept the requested booking.
   *
   * Atomically checks authoritative sold counts plus active reservations under
   * a per-event database row lock, then writes or updates a provisional
   * reservation when capacity allows.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param int $requested
   *   Number of tickets/RSVPs requested.
   * @param string|null $reservationKey
   *   Stable hold identifier (e.g. cart:123:event:456). Callers should pass
   *   an explicit key so repeated checks upsert the same reservation.
   *
   * @throws \Drupal\myeventlane_capacity\Exception\CapacityExceededException
   *   If capacity would be exceeded.
   */
  public function assertCanBook(NodeInterface $event, int $requested = 1, ?string $reservationKey = NULL): void;

  /**
   * Releases a provisional capacity reservation.
   *
   * @param string $reservationKey
   *   The reservation key passed to assertCanBook().
   */
  public function releaseReservation(string $reservationKey): void;

}
