<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\node\NodeInterface;

/**
 * Read-only ticket purchasability surface for customer-facing display builders.
 */
interface TicketCustomerDisplayGatewayInterface extends TicketTierForVariationResolverInterface {

  /**
   * Anonymous default-customer context (no access-code grants).
   */
  public function buildDefaultCustomerAccessContext(NodeInterface $event): TicketAccessContext;

  /**
   * Variations that may appear on the public ticket selection form.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface[]
   */
  public function filterPurchasableVariations(
    NodeInterface $event,
    ProductInterface $product,
    ?TicketAccessContext $accessContext = NULL,
    bool $useCache = TRUE,
  ): array;

  /**
   * Completed-order quantity for this variation and event.
   */
  public function countCompletedSoldForVariation(int $eventId, int $variationId): int;

  /**
   * Remaining public inventory after completed sales and active holds.
   *
   * Returns NULL when the tier has no finite capacity.
   */
  public function getPublicPoolRemaining(
    NodeInterface $event,
    ?TicketTypeInterface $tier,
    int $variationId,
  ): ?int;

  /**
   * Vendor-only reason a saved tier is not on the public book matrix.
   */
  public function explainVendorPreviewExclusion(
    NodeInterface $event,
    ProductInterface $product,
    TicketTypeInterface $tier,
    ?ProductVariationInterface $variation,
    TicketAccessContext $context,
  ): string;

}
