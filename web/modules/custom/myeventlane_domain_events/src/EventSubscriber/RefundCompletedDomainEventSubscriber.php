<?php

declare(strict_types=1);

namespace Drupal\myeventlane_domain_events\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\myeventlane_domain_events\Service\DomainEventBus;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Publishes refund.completed domain events.
 */
final class RefundCompletedDomainEventSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly DomainEventBus $eventBus,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_payment.refund.post_transition' => ['onRefundCompleted', -200],
      'commerce_payment.void.post_transition' => ['onRefundCompleted', -200],
    ];
  }

  /**
   * Publishes refund.completed event.
   */
  public function onRefundCompleted(WorkflowTransitionEvent $event): void {
    $entity = $event->getEntity();
    if (!$entity instanceof PaymentInterface) {
      return;
    }

    $order = $entity->getOrder();
    $orderId = $order ? (int) $order->id() : 0;
    [$eventNodeId, $vendorId] = $order ? $this->extractEventAndVendor($order) : [NULL, NULL];

    $refundAmount = 0.0;
    $paymentAmount = $entity->getAmount();
    if ($paymentAmount !== NULL) {
      $refundAmount = (float) $paymentAmount->getNumber();
    }

    $this->eventBus->publish(
      'refund.completed',
      'payment',
      (string) $entity->id(),
      [
        'payment_id' => (int) $entity->id(),
        'order_id' => $orderId > 0 ? $orderId : NULL,
        'vendor_id' => $vendorId,
        'event_node_id' => $eventNodeId,
        'refund_amount' => $refundAmount,
        'refund_total' => $refundAmount,
        'currency_code' => $paymentAmount ? (string) $paymentAmount->getCurrencyCode() : NULL,
      ],
      [
        'vendor_id' => $vendorId,
        'event_node_id' => $eventNodeId,
        'order_id' => $orderId > 0 ? $orderId : NULL,
        'source_module' => 'myeventlane_domain_events',
        'source_idempotency_key' => 'refund.completed:' . (string) $entity->id() . ':' . (string) $event->getTransition()->getId(),
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

