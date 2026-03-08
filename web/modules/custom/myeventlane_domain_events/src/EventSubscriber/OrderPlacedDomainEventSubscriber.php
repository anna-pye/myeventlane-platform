<?php

declare(strict_types=1);

namespace Drupal\myeventlane_domain_events\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\myeventlane_domain_events\Service\DomainEventBus;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Publishes order.placed domain events.
 */
final class OrderPlacedDomainEventSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly DomainEventBus $eventBus,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_order.place.post_transition' => ['onOrderPlaced', -200],
    ];
  }

  /**
   * Publishes order.placed event.
   */
  public function onOrderPlaced(WorkflowTransitionEvent $event): void {
    $entity = $event->getEntity();
    if (!$entity instanceof OrderInterface) {
      return;
    }

    [$eventNodeId, $vendorId] = $this->extractEventAndVendor($entity);
    $totalPrice = $entity->getTotalPrice();

    $payload = [
      'order_id' => (int) $entity->id(),
      'order_number' => (string) $entity->getOrderNumber(),
      'vendor_id' => $vendorId,
      'event_node_id' => $eventNodeId,
      'user_id' => (int) $entity->getCustomerId(),
      'amount_total' => $totalPrice ? (float) $totalPrice->getNumber() : 0.0,
      'currency_code' => $totalPrice ? (string) $totalPrice->getCurrencyCode() : NULL,
    ];

    $this->eventBus->publish(
      'order.placed',
      'order',
      (string) $entity->id(),
      $payload,
      [
        'vendor_id' => $vendorId,
        'event_node_id' => $eventNodeId,
        'order_id' => (int) $entity->id(),
        'user_id' => (int) $entity->getCustomerId(),
        'source_module' => 'myeventlane_domain_events',
        'source_idempotency_key' => 'order.placed:' . (string) $entity->id(),
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

