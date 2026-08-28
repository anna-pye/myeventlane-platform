<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\OrderProcessor;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\OrderProcessorInterface;
use Drupal\myeventlane_commerce\OrderPreprocessor\TicketBundlePricePreprocessor;

/**
 * Removes the temporary bundle tax marker after Commerce Tax has run.
 */
final class TicketBundleTaxMarkerCleanupProcessor implements OrderProcessorInterface {

  /**
   * {@inheritdoc}
   */
  public function process(OrderInterface $order): void {
    foreach ($order->getItems() as $order_item) {
      foreach ($order_item->getAdjustments() as $adjustment) {
        if ($adjustment->getSourceId() === TicketBundlePricePreprocessor::TAX_PLACEHOLDER_SOURCE) {
          $order_item->removeAdjustment($adjustment);
        }
      }
    }
  }

}
