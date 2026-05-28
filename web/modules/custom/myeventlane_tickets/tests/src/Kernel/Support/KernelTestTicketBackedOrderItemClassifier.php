<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Kernel\Support;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\myeventlane_commerce\Service\TicketBackedOrderItemClassifierInterface;
use Drupal\myeventlane_core\Commerce\OperationalProductBundles;
use Drupal\node\NodeInterface;

/**
 * Permissive ticket-backed classifier for ticket kernel tests without commerce.
 *
 * Treats event-linked variations as ticket-backed (pre-Phase-0 issuance tests).
 * Excludes operational merchandise bundles so mixed-order safety tests can
 * substitute the production classifier instead.
 */
final class KernelTestTicketBackedOrderItemClassifier implements TicketBackedOrderItemClassifierInterface {

  /**
   * {@inheritdoc}
   */
  public function isTicketBackedOrderItem(OrderItemInterface $order_item): bool {
    if (in_array($order_item->bundle(), ['boost', 'checkout_donation', 'platform_donation', 'rsvp_donation'], TRUE)) {
      return FALSE;
    }

    $purchased = $order_item->getPurchasedEntity();
    if (!$purchased instanceof ProductVariationInterface) {
      return FALSE;
    }

    $product = $purchased->getProduct();
    if ($product instanceof ProductInterface && OperationalProductBundles::isOperationalProductBundle($product->bundle())) {
      return FALSE;
    }

    return $this->resolveEvent($order_item, $purchased) instanceof NodeInterface;
  }

  private function resolveEvent(OrderItemInterface $order_item, ProductVariationInterface $variation): ?NodeInterface {
    if ($order_item->hasField('field_target_event') && !$order_item->get('field_target_event')->isEmpty()) {
      $event = $order_item->get('field_target_event')->entity;
      if ($event instanceof NodeInterface && $event->bundle() === 'event') {
        return $event;
      }
    }

    if ($variation->hasField('field_event') && !$variation->get('field_event')->isEmpty()) {
      $event = $variation->get('field_event')->entity;
      if ($event instanceof NodeInterface && $event->bundle() === 'event') {
        return $event;
      }
    }

    $product = $variation->getProduct();
    if ($product instanceof ProductInterface && $product->hasField('field_event') && !$product->get('field_event')->isEmpty()) {
      $event = $product->get('field_event')->entity;
      if ($event instanceof NodeInterface && $event->bundle() === 'event') {
        return $event;
      }
    }

    return NULL;
  }

}
