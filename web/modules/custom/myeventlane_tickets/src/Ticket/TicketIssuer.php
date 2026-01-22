<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Ticket;

use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 *
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

      // Determine event node from order item -> purchased entity -> product -> event reference.
      // This is project-specific. Implement defensively: try common patterns.
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

      // Optional: resolve paragraph ticket type config from event by matching variation id.
      $ticket_type_paragraph = $this->resolveTicketTypeParagraph($event, (int) $purchased_entity->id());

      for ($i = 0; $i < $qty; $i++) {
        $ticket = $ticket_storage->create([
          'ticket_code' => $this->codeGenerator->generateUniqueTicketCode(),
          'event_id' => $event->id(),
          'order_id' => $order->id(),
          'order_item_id' => $order_item->id(),
          'purchased_entity' => $purchased_entity->id(),
          'ticket_type_config' => $ticket_type_paragraph ? $ticket_type_paragraph->id() : NULL,
          'purchaser_uid' => $order->getCustomerId(),
          'status' => Ticket::STATUS_ISSUED_UNASSIGNED,
        ]);
        $ticket->save();
      }
    }
  }

  /**
   * Resolves event node from order item.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *   The order item.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The event node, or NULL if not found.
   */
  private function resolveEventFromOrderItem(OrderItemInterface $order_item) {
    // @todo Implement according to MyEventLane v2 data model.
    // Common patterns:
    // - variation has field_event reference
    // - product has field_event reference
    // - order item has field_event reference.
    $purchased_entity = $order_item->getPurchasedEntity();
    if ($purchased_entity && $purchased_entity->hasField('field_event') && !$purchased_entity->get('field_event')->isEmpty()) {
      return $purchased_entity->get('field_event')->entity;
    }

    $product = method_exists($purchased_entity, 'getProduct') ? $purchased_entity->getProduct() : NULL;
    if ($product && $product->hasField('field_event') && !$product->get('field_event')->isEmpty()) {
      return $product->get('field_event')->entity;
    }

    if ($order_item->hasField('field_target_event') && !$order_item->get('field_target_event')->isEmpty()) {
      return $order_item->get('field_target_event')->entity;
    }

    return NULL;
  }

  /**
   * Resolves ticket type paragraph from event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param int $variation_id
   *   The product variation ID.
   *
   * @return \Drupal\paragraphs\ParagraphInterface|null
   *   The ticket type paragraph, or NULL if not found.
   */
  private function resolveTicketTypeParagraph($event, int $variation_id) {
    // Optional: if your event uses field_ticket_types paragraphs, try to match.
    if (!$event || !$event->hasField('field_ticket_types') || $event->get('field_ticket_types')->isEmpty()) {
      return NULL;
    }

    foreach ($event->get('field_ticket_types') as $ref) {
      $p = $ref->entity;
      if (!$p) {
        continue;
      }
      // Adjust field name to your paragraph schema if it exists.
      if ($p->hasField('field_ticket_variation') && !$p->get('field_ticket_variation')->isEmpty()) {
        if ((int) $p->get('field_ticket_variation')->target_id === $variation_id) {
          return $p;
        }
      }
    }

    return NULL;
  }

}
