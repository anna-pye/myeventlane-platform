<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\EventSubscriber;

use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_order\Event\OrderEvents;
use Drupal\commerce_order\OrderRefreshInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Ensures platform fee is applied whenever a draft order is saved.
 *
 * Commerce's order refresh (and thus PlatformFeeOrderProcessor) normally runs
 * only when the refresh interval has elapsed. This subscriber runs a refresh
 * on every presave of a draft order so the fee is always present when the
 * order is persisted. Runs after OrderStorage::doOrderPreSave.
 */
final class PlatformFeeOrderPresaveSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the subscriber.
   *
   * @param \Drupal\commerce_order\OrderRefreshInterface $orderRefresh
   *   The order refresh service.
   */
  public function __construct(
    private readonly OrderRefreshInterface $orderRefresh,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      OrderEvents::ORDER_PRESAVE => ['onOrderPresave', 50],
    ];
  }

  /**
   * Refreshes draft orders so the platform fee (and other adjustments) are set.
   *
   * @param \Drupal\commerce_order\Event\OrderEvent $event
   *   The order event.
   */
  public function onOrderPresave(OrderEvent $event): void {
    $order = $event->getOrder();

    if ($order->getState()->getId() !== 'draft') {
      return;
    }

    $this->orderRefresh->refresh($order);
    $order->recalculateTotalPrice();
  }

}
