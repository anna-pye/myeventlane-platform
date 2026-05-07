<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Service;

use Drupal\commerce_order\Entity\OrderInterface;

/**
 * Event-grouped checkout/cart summary rows for presentation (Commerce-sourced amounts).
 */
interface CheckoutGroupedSummaryBuilderInterface {

  /**
   * @return array<string, mixed>
   *   Variables for mel_checkout_order_summary_grouped and cache_tags.
   */
  public function build(OrderInterface $order): array;

}
