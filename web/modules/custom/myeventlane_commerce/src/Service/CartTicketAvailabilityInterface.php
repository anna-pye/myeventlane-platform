<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\node\NodeInterface;

/**
 * Availability checks required while refreshing a cart ticket hold.
 */
interface CartTicketAvailabilityInterface {

  /**
   * Resolves the canonical ticket tier for a Commerce variation.
   */
  public function resolveTierForVariation(
    NodeInterface $event,
    ProductVariationInterface $variation,
  ): ?TicketTypeInterface;

  /**
   * Returns the active waitlist-offer expiry covering this ticket, if any.
   */
  public function getActiveWaitlistOfferExpiry(
    NodeInterface $event,
    ProductVariationInterface $variation,
  ): ?int;

  /**
   * Validates one paid ticket line without changing reservation state.
   */
  public function assertPaidVariationLineConstraints(
    NodeInterface $event,
    ProductInterface $product,
    ProductVariationInterface $variation,
    int $lineQuantity,
    ?TicketAccessContext $context = NULL,
    ?array $capacityMaps = NULL,
    ?string $excludedTierReservationKey = NULL,
  ): void;

  /**
   * Validates and reserves the requested event-level ticket quantity.
   */
  public function assertEventTotalBookable(
    NodeInterface $event,
    int $totalQuantityForEvent,
    ?string $reservationKey = NULL,
  ): void;

}
