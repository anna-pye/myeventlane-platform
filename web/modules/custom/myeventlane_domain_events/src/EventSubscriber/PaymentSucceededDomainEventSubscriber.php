<?php

declare(strict_types=1);

namespace Drupal\myeventlane_domain_events\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_order\Event\OrderEvents;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\myeventlane_domain_events\Service\DomainEventBus;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Publishes payment.succeeded domain events.
 */
final class PaymentSucceededDomainEventSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly DomainEventBus $eventBus,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      OrderEvents::ORDER_PAID => ['onOrderPaid', -200],
    ];
  }

  /**
   * Publishes payment.succeeded event.
   */
  public function onOrderPaid(OrderEvent $event): void {
    $order = $event->getOrder();
    if (!$order instanceof OrderInterface) {
      return;
    }

    [$eventNodeId, $vendorId] = $this->extractEventAndVendor($order);
    $totalPrice = $order->getTotalPrice();
    $gross = $totalPrice ? (float) $totalPrice->getNumber() : 0.0;

    $payload = [
      'order_id' => (int) $order->id(),
      'vendor_id' => $vendorId,
      'event_node_id' => $eventNodeId,
      'user_id' => (int) $order->getCustomerId(),
      'amount_total' => $gross,
      'gross_revenue' => $gross,
      'mel_fee' => 0.0,
      'stripe_fee' => 0.0,
      'net_revenue' => $gross,
      'currency_code' => $totalPrice ? (string) $totalPrice->getCurrencyCode() : NULL,
    ];

    $this->eventBus->publish(
      'payment.succeeded',
      'order',
      (string) $order->id(),
      $payload,
      [
        'vendor_id' => $vendorId,
        'event_node_id' => $eventNodeId,
        'order_id' => (int) $order->id(),
        'user_id' => (int) $order->getCustomerId(),
        'source_module' => 'myeventlane_domain_events',
        'source_idempotency_key' => 'payment.succeeded:' . (string) $order->id(),
      ],
    );
  }

  /**
   * @return array{0:int|null,1:int|null}
   *   Event node ID and vendor ID.
   */
  private function extractEventAndVendor(OrderInterface $order): array {
    foreach ($order->getItems() as $orderItem) {
      if (!$orderItem->hasField('field_target_event') || $orderItem->get('field_target_event')->isEmpty()) {
        continue;
      }
      $eventNode = $orderItem->get('field_target_event')->entity;
      if (!$eventNode instanceof ContentEntityInterface) {
        continue;
      }

      $eventNodeId = (int) $eventNode->id();
      $vendorId = $this->extractVendorIdFromEvent($eventNode);
      return [$eventNodeId > 0 ? $eventNodeId : NULL, $vendorId];
    }
    return [NULL, NULL];
  }

  /**
   * Resolves vendor entity ID from known event-node vendor reference fields.
   */
  private function extractVendorIdFromEvent(ContentEntityInterface $eventNode): ?int {
    foreach (['field_event_vendor', 'field_vendor', 'field_vendor_account'] as $field) {
      if ($eventNode->hasField($field) && !$eventNode->get($field)->isEmpty()) {
        $id = (int) $eventNode->get($field)->target_id;
        if ($id > 0) {
          return $id;
        }
      }
    }
    return NULL;
  }

}

