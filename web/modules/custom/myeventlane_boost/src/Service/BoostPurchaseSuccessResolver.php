<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\node\NodeInterface;

/**
 * Resolves boost purchase summary data for checkout completion UX.
 */
final class BoostPurchaseSuccessResolver {

  public function __construct(
    private readonly BoostEntitlementManager $entitlementManager,
  ) {}

  /**
   * Whether every line item in the order is a Boost purchase.
   */
  public function isBoostOnlyOrder(OrderInterface $order): bool {
    $items = $order->getItems();
    if ($items === []) {
      return FALSE;
    }

    foreach ($items as $item) {
      if (!$this->entitlementManager->isBoostOrderItem($item)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Resolves the primary boost line from a completed order.
   *
   * @return array{
   *     event_id: int,
   *     event_title: string,
   *     boost_days: int,
   *     plan_label: string,
   *   }|null
   *   Purchase summary, or NULL when no boost item is present.
   */
  public function resolvePrimaryBoostPurchase(OrderInterface $order): ?array {
    foreach ($order->getItems() as $item) {
      if (!$this->entitlementManager->isBoostOrderItem($item)) {
        continue;
      }

      $event_id = $this->resolveTargetEventId($item);
      if ($event_id <= 0) {
        continue;
      }

      $event_title = 'your event';
      if ($item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
        $event = $item->get('field_target_event')->entity;
        if ($event instanceof NodeInterface) {
          $event_title = $event->label();
        }
      }

      return [
        'event_id' => $event_id,
        'event_title' => $event_title,
        'boost_days' => $this->resolveBoostDays($item),
        'plan_label' => $item->label(),
      ];
    }

    return NULL;
  }

  /**
   * Whether the order's primary boost purchase targets the given event.
   */
  public function orderMatchesEvent(OrderInterface $order, int $event_id): bool {
    $purchase = $this->resolvePrimaryBoostPurchase($order);
    return $purchase !== NULL && $purchase['event_id'] === $event_id;
  }

  /**
   * Resolves the target event ID from a boost order item.
   */
  public function resolveTargetEventId(OrderItemInterface $item): int {
    if ($item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
      return (int) $item->get('field_target_event')->target_id;
    }
    return 0;
  }

  /**
   * Resolves purchased boost duration in days from line item or variation.
   */
  public function resolveBoostDays(OrderItemInterface $item): int {
    $days = 0;
    if ($item->hasField('field_boost_days_purchased') && !$item->get('field_boost_days_purchased')->isEmpty()) {
      $days = (int) $item->get('field_boost_days_purchased')->value;
    }

    $variation = $item->getPurchasedEntity();
    if ($days <= 0 && $variation instanceof ProductVariationInterface
      && $variation->hasField('field_boost_days')
      && !$variation->get('field_boost_days')->isEmpty()) {
      $days = (int) $variation->get('field_boost_days')->value;
    }

    return max(1, $days);
  }

}
