<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Ticket;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\mel_ticket\Entity\TicketType;
use Drupal\myeventlane_commerce\Service\TicketBackedOrderItemClassifierInterface;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;

/**
 * Issues ticket rows for paid Commerce orders.
 */
final class TicketIssuer {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TicketCodeGenerator $codeGenerator,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly TicketBackedOrderItemClassifierInterface $ticketBackedOrderItemClassifier,
  ) {}

  /**
   * Read-only count of ticket rows that issuance would create for this order.
   *
   * Mirrors {@see self::issueForOrder()} line-item rules excluding per-item
   * idempotent skips when tickets already exist.
   */
  public function countExpectedIssuanceUnits(OrderInterface $order): int {
    $total = 0;
    foreach ($order->getItems() as $order_item) {
      if (!$order_item instanceof OrderItemInterface) {
        continue;
      }

      if (!$this->isOrderItemEligibleForTicketIssuance($order_item)) {
        continue;
      }

      /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $purchased_entity */
      $purchased_entity = $order_item->getPurchasedEntity();

      $qty = (int) $order_item->getQuantity();
      if ($qty < 1) {
        continue;
      }

      $total += $qty;
    }

    return $total;
  }

  /**
   * True when this line item would contribute rows in {@see self::issueForOrder()}.
   */
  public function isOrderItemEligibleForTicketIssuance(OrderItemInterface $order_item): bool {
    $purchased_entity = $order_item->getPurchasedEntity();
    if (!$purchased_entity instanceof ProductVariationInterface) {
      return FALSE;
    }

    if (!$this->ticketBackedOrderItemClassifier->isTicketBackedOrderItem($order_item)) {
      return FALSE;
    }

    return $this->resolveEventFromOrderItem($order_item) !== NULL;
  }

  /**
   * Issues tickets for a paid order.
   *
   * One order item quantity = N ticket entities. Uses per-order-item idempotency:
   * only missing tickets are created on replay.
   */
  public function issueForOrder(OrderInterface $order): void {
    $order_id = (int) $order->id();
    if ($order_id < 1) {
      return;
    }

    $logger = $this->loggerFactory->get('myeventlane_tickets');

    foreach ($order->getItems() as $order_item) {
      if (!$order_item instanceof OrderItemInterface) {
        continue;
      }

      if (!$this->isOrderItemEligibleForTicketIssuance($order_item)) {
        continue;
      }

      /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $purchased_entity */
      $purchased_entity = $order_item->getPurchasedEntity();

      $event = $this->resolveEventFromOrderItem($order_item);
      if (!$event) {
        $logger->warning(
          'Could not resolve event for order item @id on order @order.',
          ['@id' => $order_item->id(), '@order' => $order->id()]
        );
        continue;
      }

      $expected_quantity = (int) $order_item->getQuantity();
      if ($expected_quantity < 1) {
        continue;
      }

      $order_item_id = (int) $order_item->id();
      $existing_count = $this->countTicketsForOrderItem($order_item_id);
      if ($existing_count >= $expected_quantity) {
        continue;
      }

      $missing = $expected_quantity - $existing_count;
      if ($existing_count > 0) {
        $logger->info(
          'Partial ticket repair for order @order_id order_item @order_item_id: issuing @missing missing of @expected tickets.',
          [
            '@order_id' => (string) $order_id,
            '@order_item_id' => (string) $order_item_id,
            '@missing' => (string) $missing,
            '@expected' => (string) $expected_quantity,
            'order_id' => $order_id,
            'order_item_id' => $order_item_id,
            'missing_ticket_count' => $missing,
            'expected_ticket_count' => $expected_quantity,
            'existing_ticket_count' => $existing_count,
          ]
        );
      }

      $mel_ticket_type = $this->resolveMelTicketType($event, (int) $purchased_entity->id());

      for ($holder_index = $existing_count; $holder_index < $expected_quantity; $holder_index++) {
        $this->createTicketForOrderItem(
          $order,
          $order_item,
          $event,
          $purchased_entity,
          $mel_ticket_type,
          $holder_index,
        );
      }
    }
  }

  /**
   * Counts issued ticket rows for a Commerce order item.
   */
  private function countTicketsForOrderItem(int $order_item_id): int {
    if ($order_item_id < 1) {
      return 0;
    }

    $ticket_storage = $this->entityTypeManager->getStorage('myeventlane_ticket');
    return (int) $ticket_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_item_id', $order_item_id)
      ->count()
      ->execute();
  }

  /**
   * Creates and saves one ticket entity for an order item unit.
   */
  private function createTicketForOrderItem(
    OrderInterface $order,
    OrderItemInterface $order_item,
    NodeInterface $event,
    ProductVariationInterface $purchased_entity,
    ?TicketType $mel_ticket_type,
    int $holder_index,
  ): void {
    $ticket_storage = $this->entityTypeManager->getStorage('myeventlane_ticket');
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
    $this->applyCheckoutHolderToTicket($ticket, $order_item, $holder_index);
    $ticket->save();
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

  /**
   * Maps checkout ticket-holder paragraphs onto issued ticket rows when present.
   *
   * Holder details collected at checkout (field_ticket_holder) are the canonical
   * source for PDF generation; unassigned status remains when no holder email exists.
   */
  private function applyCheckoutHolderToTicket(Ticket $ticket, OrderItemInterface $order_item, int $holder_index): void {
    if (!$order_item->hasField('field_ticket_holder') || $order_item->get('field_ticket_holder')->isEmpty()) {
      return;
    }

    $holders = $order_item->get('field_ticket_holder')->referencedEntities();
    if (!isset($holders[$holder_index]) || !$holders[$holder_index] instanceof ParagraphInterface) {
      return;
    }

    $holder = $holders[$holder_index];
    $firstName = $holder->hasField('field_first_name') && !$holder->get('field_first_name')->isEmpty()
      ? trim((string) $holder->get('field_first_name')->value)
      : '';
    $lastName = $holder->hasField('field_last_name') && !$holder->get('field_last_name')->isEmpty()
      ? trim((string) $holder->get('field_last_name')->value)
      : '';
    $holderName = trim($firstName . ' ' . $lastName);
    $holderEmail = $holder->hasField('field_email') && !$holder->get('field_email')->isEmpty()
      ? trim((string) $holder->get('field_email')->value)
      : '';

    if ($holderName === '' || $holderEmail === '') {
      return;
    }

    $ticket->set('holder_name', $holderName);
    $ticket->set('holder_email', $holderEmail);
    $ticket->set('status', Ticket::STATUS_ASSIGNED);
  }

}
