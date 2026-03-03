<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;

/**
 * Marks abandoned-cart attribution rows as recovered on order placement.
 */
final class ProRecoveryAttributionSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the subscriber.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_order.place.post_transition' => 'onOrderPlaced',
    ];
  }

  /**
   * Updates existing attribution rows when an order is placed.
   */
  public function onOrderPlaced(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    if (!$order instanceof OrderInterface) {
      return;
    }

    if ($order->isNew()) {
      $this->logger->warning('Recovery attribution skipped for non-persisted order entity.');
      return;
    }

    $orderId = (int) $order->id();
    if ($orderId <= 0) {
      $this->logger->warning('Recovery attribution skipped for invalid order id.');
      return;
    }

    $totalPrice = $order->getTotalPrice();
    if ($totalPrice === NULL) {
      $this->logger->error('Cannot mark recovery attribution for order @order_id: total_price missing.', [
        '@order_id' => $orderId,
      ]);
      return;
    }

    $recoveredAt = $this->time->getRequestTime();
    $updated = $this->database->update('myeventlane_pro_recovery_attribution')
      ->fields([
        'recovered' => 1,
        'recovered_at' => $recoveredAt,
        'order_total' => (string) $totalPrice->getNumber(),
        'currency' => $totalPrice->getCurrencyCode(),
      ])
      ->condition('order_id', $orderId)
      ->condition('recovered', 0)
      ->execute();

    if ($updated > 0) {
      $this->logger->info('Marked @count recovery attribution row(s) as recovered for order @order_id.', [
        '@count' => $updated,
        '@order_id' => $orderId,
      ]);
    }
  }

}
