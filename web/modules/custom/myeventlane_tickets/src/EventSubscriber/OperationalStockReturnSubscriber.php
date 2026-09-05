<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\myeventlane_tickets\Service\OperationalStockReturnManager;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Reconciles add-on stock after an order is cancelled.
 */
final class OperationalStockReturnSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly OperationalStockReturnManager $returnManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_order.cancel.post_transition' => ['onOrderCancelPostTransition', -200],
    ];
  }

  /**
   * Returns only unfulfilled operational stock after cancellation.
   */
  public function onOrderCancelPostTransition(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    if (!$order instanceof OrderInterface) {
      return;
    }

    $fromState = (string) $event->getField()->getOriginalId();
    $this->returnManager->reconcileCancellation($order, $fromState);
  }

}
