<?php

namespace Drupal\myeventlane_commerce\EventSubscriber;

use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_order\Event\OrderEvents;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Reacts to order paid events.
 */
final class OrderPaidSubscriber implements EventSubscriberInterface {

  /**
   * Constructs an OrderPaidSubscriber.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      OrderEvents::ORDER_PAID => ['onOrderPaid'],
    ];
  }

  /**
   * Logs and handles order paid events.
   *
   * @param \Drupal\commerce_order\Event\OrderEvent $event
   *   The order event.
   */
  public function onOrderPaid(OrderEvent $event): void {
    $order = $event->getOrder();
    // @todo Fix boost logic implementation here.
    $this->logger->info('Order @id paid — MYEL subscriber ran.', ['@id' => $order->id()]);
  }

}
