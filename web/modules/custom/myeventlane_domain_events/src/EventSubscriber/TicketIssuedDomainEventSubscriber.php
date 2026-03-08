<?php

declare(strict_types=1);

namespace Drupal\myeventlane_domain_events\EventSubscriber;

use Drupal\Core\Entity\EntityEvent;
use Drupal\myeventlane_domain_events\Service\DomainEventBus;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Publishes ticket.issued domain events on ticket entity insert.
 */
final class TicketIssuedDomainEventSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly DomainEventBus $eventBus,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events['entity.insert'][] = ['onEntityInsert'];
    return $events;
  }

  /**
   * Handles entity insert events.
   */
  public function onEntityInsert(EntityEvent $event): void {
    $entity = $event->getEntity();
    if (!$entity instanceof Ticket) {
      return;
    }

    $eventNodeId = (int) $entity->get('event_id')->target_id;
    $orderId = (int) $entity->get('order_id')->target_id;
    $userId = (int) $entity->get('purchaser_uid')->target_id;

    $this->eventBus->publish(
      'ticket.issued',
      'ticket',
      (string) $entity->id(),
      [
        'ticket_id' => (int) $entity->id(),
        'ticket_code' => (string) $entity->get('ticket_code')->value,
        'event_node_id' => $eventNodeId > 0 ? $eventNodeId : NULL,
        'order_id' => $orderId > 0 ? $orderId : NULL,
        'user_id' => $userId > 0 ? $userId : NULL,
        'quantity' => 1,
      ],
      [
        'event_node_id' => $eventNodeId > 0 ? $eventNodeId : NULL,
        'order_id' => $orderId > 0 ? $orderId : NULL,
        'user_id' => $userId > 0 ? $userId : NULL,
        'source_module' => 'myeventlane_domain_events',
        'source_idempotency_key' => 'ticket.issued:' . (string) $entity->id(),
      ],
    );
  }

}

