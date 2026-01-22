<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\EventSubscriber;

use Drupal\commerce_order\Event\OrderEvents;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\myeventlane_tickets\Ticket\TicketIssuer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 *
 */
final class OrderPaidSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly TicketIssuer $issuer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Choose the most appropriate Commerce event used in your project.
    // If you already standardize on an "order paid" event elsewhere, use that.
    return [
      OrderEvents::ORDER_PAID => 'onOrderPaid',
    ];
  }

  /**
   * Handles order paid event.
   *
   * @param \Drupal\commerce_order\Event\OrderEvent $event
   *   The order event.
   */
  public function onOrderPaid(OrderEvent $event): void {
    $order = $event->getOrder();
    $this->issuer->issueForOrder($order);
  }

}
