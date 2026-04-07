<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Ticket;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\mel_ticket\Entity\TicketType;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\node\NodeInterface;

/**
 * Issues ticket rows for paid Commerce orders.
 */
final class TicketIssuer {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TicketCodeGenerator $codeGenerator,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Issues tickets for a paid order.
   *
   * One order item quantity = N ticket entities.
   */
  public function issueForOrder(OrderInterface $order): void {
    $ticket_storage = $this->entityTypeManager->getStorage('myeventlane_ticket');

    foreach ($order->getItems() as $order_item) {
      if (!$order_item instanceof OrderItemInterface) {
        continue;
      }

      $purchased_entity = $order_item->getPurchasedEntity();
      if (!$purchased_entity || $purchased_entity->getEntityTypeId() !== 'commerce_product_variation') {
        continue;
      }

      $event = $this->resolveEventFromOrderItem($order_item);
      if (!$event) {
        $this->loggerFactory->get('myeventlane_tickets')->warning(
          'Could not resolve event for order item @id on order @order.',
          ['@id' => $order_item->id(), '@order' => $order->id()]
        );
        continue;
      }

      $qty = (int) $order_item->getQuantity();
      if ($qty < 1) {
        continue;
      }

      $mel_ticket_type = $this->resolveMelTicketType($event, (int) $purchased_entity->id());

      for ($i = 0; $i < $qty; $i++) {
        $values = [
          'ticket_code' => $this->codeGenerator->generateUniqueTicketCode(),
          'event_id' => $event->id(),
          'order_id' => $order->id(),
          'order_item_id' => $order_item->id(),
          'purchased_entity' => $purchased_entity->id(),
          'ticket_type_config' => NULL,
          'mel_ticket_type' => $mel_ticket_type ? ['target_id' => $mel_ticket_type->id()] : NULL,
          'purchaser_uid' => $order->getCustomerId(),
          'status' => Ticket::STATUS_ISSUED_UNASSIGNED,
        ];
        $ticket = $ticket_storage->create($values);
        $ticket->save();
      }
    }
  }

  /**
   * Resolves event node from order item.
   */
  private function resolveEventFromOrderItem(OrderItemInterface $order_item): ?NodeInterface {
    $purchased_entity = $order_item->getPurchasedEntity();
    if ($purchased_entity && $purchased_entity->hasField('field_event') && !$purchased_entity->get('field_event')->isEmpty()) {
      $node = $purchased_entity->get('field_event')->entity;
      return $node instanceof NodeInterface ? $node : NULL;
    }

    $product = $purchased_entity !== NULL && method_exists($purchased_entity, 'getProduct')
      ? $purchased_entity->getProduct()
      : NULL;
    if ($product && $product->hasField('field_event') && !$product->get('field_event')->isEmpty()) {
      $node = $product->get('field_event')->entity;
      return $node instanceof NodeInterface ? $node : NULL;
    }

    if ($order_item->hasField('field_target_event') && !$order_item->get('field_target_event')->isEmpty()) {
      $node = $order_item->get('field_target_event')->entity;
      return $node instanceof NodeInterface ? $node : NULL;
    }

    return NULL;
  }

  /**
   * Resolves mel_ticket_type from event field_ticket_types by variation id.
   */
  private function resolveMelTicketType(NodeInterface $event, int $variation_id): ?TicketType {
    if (!$event->hasField('field_ticket_types') || $event->get('field_ticket_types')->isEmpty()) {
      return NULL;
    }

    foreach ($event->get('field_ticket_types')->referencedEntities() as $entity) {
      if ($entity instanceof TicketType && !$entity->get('commerce_variation')->isEmpty()) {
        if ((int) $entity->get('commerce_variation')->target_id === $variation_id) {
          return $entity;
        }
      }
    }

    return NULL;
  }

}
