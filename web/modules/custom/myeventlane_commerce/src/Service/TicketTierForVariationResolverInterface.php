<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\node\NodeInterface;

/**
 * Resolves mel_ticket_type rows for Commerce variations on an event.
 */
interface TicketTierForVariationResolverInterface {

  public function resolveTierForVariation(
    NodeInterface $event,
    ProductVariationInterface $variation,
  ): ?TicketTypeInterface;

}
