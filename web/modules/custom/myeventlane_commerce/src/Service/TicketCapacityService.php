<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\mel_ticket\Entity\TicketWaitlistEntry;
use Drupal\node\NodeInterface;

/**
 * Single source of truth for tier sold, waitlist-held, and remaining public pool.
 */
final class TicketCapacityService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly TicketVariationSoldService $variationSold,
    private readonly TimeInterface $time,
    private readonly CartTicketTierHoldStoreInterface $cartTierHolds,
  ) {}

  public function getSold(int $eventId, int $variationId): int {
    return $this->variationSold->countCompletedSoldForVariation($eventId, $variationId);
  }

  /**
   * @return array<int, int>
   *   Variation ID => sold quantity.
   */
  public function getSoldBatch(int $eventId, array $variationIds): array {
    return $this->variationSold->countCompletedSoldForVariations($eventId, $variationIds);
  }

  /**
   * Counts active waitlist and cart holds for one ticket tier.
   *
   * @param int $eventId
   *   Event node ID.
   * @param int $tierId
   *   Ticket tier ID.
   * @param string|null $excludedCartReservationKey
   *   Current cart hold key to exclude from competing inventory.
   */
  public function getHeld(
    int $eventId,
    int $tierId,
    ?string $excludedCartReservationKey = NULL,
  ): int {
    $waitlistHeld = $this->sumActiveOfferReserved($eventId, [$tierId])[$tierId] ?? 0;
    return $waitlistHeld + $this->cartTierHolds->getHeldQuantity(
      $eventId,
      $tierId,
      $excludedCartReservationKey,
    );
  }

  /**
   * Counts active waitlist and cart holds for multiple ticket tiers.
   *
   * @param int $eventId
   *   Event node ID.
   * @param list<int> $tierIds
   *   Ticket tier IDs.
   *
   * @return array<int, int>
   *   Tier ID => held quantity from active waitlist and cart holds.
   */
  public function getHeldBatch(int $eventId, array $tierIds): array {
    $held = $this->sumActiveOfferReserved($eventId, $tierIds);
    foreach ($tierIds as $tierId) {
      $tierId = (int) $tierId;
      $held[$tierId] = ($held[$tierId] ?? 0)
        + $this->cartTierHolds->getHeldQuantity($eventId, $tierId);
    }
    return $held;
  }

  public function getRemaining(
    int $eventId,
    int $tierId,
    int $variationId,
    TicketTypeInterface $tier,
  ): int {
    if ($tier->get('capacity')->isEmpty()) {
      return PHP_INT_MAX;
    }
    $cap = (int) $tier->get('capacity')->value;
    if ($cap < 1) {
      return PHP_INT_MAX;
    }
    $sold = $this->getSold($eventId, $variationId);
    $held = $this->getHeld($eventId, $tierId);
    $pool = $cap - $sold - $held;
    if ($pool < 0) {
      return 0;
    }
    return $pool;
  }

  /**
   * Max purchasable quantity for a buyer holding an active waitlist offer.
   */
  public function getBuyerLimitForOffer(
    NodeInterface $event,
    TicketTypeInterface $tier,
    int $variationId,
    TicketWaitlistEntry $offer,
  ): int {
    if ($tier->get('capacity')->isEmpty()) {
      return (int) ($offer->get('offer_reserved')->value ?? 0);
    }
    $cap = (int) $tier->get('capacity')->value;
    if ($cap < 1) {
      return (int) ($offer->get('offer_reserved')->value ?? 0);
    }
    $sold = $this->getSold((int) $event->id(), $variationId);
    $reserved = (int) ($offer->get('offer_reserved')->value ?? 0);
    return min($reserved, max(0, $cap - $sold));
  }

  /**
   * Max purchasable from the public pool (no active offer).
   */
  public function getBuyerLimitPublicPool(
    int $eventId,
    int $tierId,
    int $variationId,
    TicketTypeInterface $tier,
  ): int {
    return $this->getRemaining($eventId, $tierId, $variationId, $tier);
  }

  /**
   * @param list<int> $tierIds
   *
   * @return array<int, int>
   */
  private function sumActiveOfferReserved(int $eventId, array $tierIds): array {
    $tierIds = array_values(array_unique(array_filter(array_map('intval', $tierIds))));
    $out = array_fill_keys($tierIds, 0);
    if ($tierIds === [] || !$this->entityTypeManager->hasDefinition('mel_ticket_waitlist_entry')) {
      return $out;
    }
    $definition = $this->entityTypeManager->getDefinition('mel_ticket_waitlist_entry', FALSE);
    if (!$definition || $definition->getBaseTable() === '') {
      return $out;
    }
    $base = $definition->getBaseTable();
    if (!$this->database->schema()->tableExists($base)) {
      return $out;
    }

    $now = $this->time->getRequestTime();
    $ids = $this->entityTypeManager->getStorage('mel_ticket_waitlist_entry')->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $eventId)
      ->condition('ticket_type', $tierIds, 'IN')
      ->condition('status', TicketWaitlistEntry::STATUS_OFFERED)
      ->condition('offer_expires', $now, '>')
      ->execute();
    if (!$ids) {
      return $out;
    }
    $entries = $this->entityTypeManager->getStorage('mel_ticket_waitlist_entry')->loadMultiple($ids);
    foreach ($entries as $entry) {
      if (!$entry instanceof TicketWaitlistEntry) {
        continue;
      }
      $tid = (int) $entry->get('ticket_type')->target_id;
      if (isset($out[$tid])) {
        $out[$tid] += (int) ($entry->get('offer_reserved')->value ?? 0);
      }
    }
    return $out;
  }

}
