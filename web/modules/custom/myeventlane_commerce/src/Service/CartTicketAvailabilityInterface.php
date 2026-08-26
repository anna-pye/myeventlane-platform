<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\node\NodeInterface;

/**
 * Availability checks required while refreshing a cart ticket hold.
 */
interface CartTicketAvailabilityInterface {

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
