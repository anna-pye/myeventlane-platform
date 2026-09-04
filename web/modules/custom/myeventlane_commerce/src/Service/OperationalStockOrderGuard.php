<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\myeventlane_commerce\Exception\OperationalStockUnavailableException;

/**
 * Prevents placed extras being silently edited outside payment and returns.
 */
final class OperationalStockOrderGuard {

  public function __construct(private readonly OperationalStockSaleManager $sales) {}

  public function protectItem(OrderItemInterface $item, bool $deleting = FALSE): void {
    if (!$this->sales->isEnabled()) {
      return;
    }
    $original = $item->getOriginal();
    if (!$this->isExtra($item) && !($original && $this->isExtra($original))) {
      return;
    }
    $order = $item->getOrder();
    $oldOrder = $original?->getOrder();
    if (!$this->isLocked($order) && !$this->isLocked($oldOrder)) {
      return;
    }
    if ($deleting || !$original
      || $original->getOrderId() !== $item->getOrderId()
      || $original->getPurchasedEntityId() !== $item->getPurchasedEntityId()
      || (float) $original->getQuantity() !== (float) $item->getQuantity()
      || $original->getUnitPrice()?->__toString() !== $item->getUnitPrice()?->__toString()
      || ($item->hasField('field_target_event') && $original->get('field_target_event')->getValue() !== $item->get('field_target_event')->getValue())) {
      throw new OperationalStockUnavailableException('Placed extras cannot be changed or deleted. Use the refund workflow and create a new order for replacements.');
    }
  }

  public function protectOrder(OrderInterface $order, bool $deleting = FALSE): void {
    if (!$this->sales->isEnabled()) {
      return;
    }
    $original = $order->getOriginal();
    $hasExtras = FALSE;
    foreach (array_merge($order->getItems(), $original?->getItems() ?? []) as $item) {
      $hasExtras = $hasExtras || $this->isExtra($item);
    }
    if (!$hasExtras) {
      return;
    }
    if (($deleting && $this->isLocked($order))
      || ($this->isLocked($original) && $this->sales->signature($original) !== $this->sales->signature($order))) {
      throw new OperationalStockUnavailableException('Placed extras cannot be changed or deleted. Use the refund workflow and create a new order for replacements.');
    }
  }

  private function isLocked(?OrderInterface $order): bool {
    return $order !== NULL && !$order->isNew()
      && ($order->isPaid() || $order->getState()->getId() !== 'draft');
  }

  private function isExtra(OrderItemInterface $item): bool {
    $variation = $item->getPurchasedEntity();
    return $variation instanceof ProductVariationInterface
      && in_array($variation->bundle(), OperationalVariationStockResolver::OPERATIONAL_VARIATION_BUNDLES, TRUE);
  }

}
