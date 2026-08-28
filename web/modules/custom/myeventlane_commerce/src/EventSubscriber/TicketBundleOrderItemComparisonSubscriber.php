<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\EventSubscriber;

use Drupal\commerce_cart\Event\CartEvents;
use Drupal\commerce_cart\Event\OrderItemComparisonFieldsEvent;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps standalone ticket lines separate from locked bundle components.
 */
final class TicketBundleOrderItemComparisonSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      CartEvents::ORDER_ITEM_COMPARISON_FIELDS => 'onComparisonFields',
    ];
  }

  /**
   * Adds lock state when Commerce compares ticket variation order items.
   */
  public function onComparisonFields(OrderItemComparisonFieldsEvent $event): void {
    $order_item = $event->getOrderItem();
    $variation = $order_item->getPurchasedEntity();
    if (!$variation instanceof ProductVariationInterface
      || $variation->bundle() !== 'ticket_variation'
      || !$order_item->hasField('locked')) {
      return;
    }

    $fields = $event->getComparisonFields();
    $fields[] = 'locked';
    $event->setComparisonFields(array_values(array_unique($fields)));
  }

}
