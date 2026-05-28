<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Classifies Commerce order items for ticket-only post-order pipelines.
 *
 * Operational merchandise must not flow through attendee creation, ticket
 * issuance integrity, or waitlist reconciliation.
 */
final class TicketBackedOrderItemClassifier implements TicketBackedOrderItemClassifierInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly OperationalMerchandiseManager $operationalMerchandiseManager,
    private readonly TicketTierForVariationResolverInterface $ticketTierResolver,
    private readonly OrderItemClassifier $orderItemClassifier,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Whether the line item is operational merchandise (add-on), not a ticket.
   */
  public function isOperationalOrderItem(OrderItemInterface $order_item): bool {
    if ($this->orderItemClassifier->isBoost($order_item) || $this->orderItemClassifier->isDonation($order_item)) {
      return FALSE;
    }

    $purchased = $order_item->getPurchasedEntity();
    if (!$purchased instanceof ProductVariationInterface) {
      return FALSE;
    }

    $product = $purchased->getProduct();
    if (!$product instanceof ProductInterface) {
      return FALSE;
    }

    return OperationalMerchandiseManager::isOperationalProductBundle($product->bundle());
  }

  /**
   * Whether the line item is a ticket-backed purchase for a mapped event tier.
   */
  public function isTicketBackedOrderItem(OrderItemInterface $order_item): bool {
    if ($this->isOperationalOrderItem($order_item)) {
      return FALSE;
    }

    if ($this->orderItemClassifier->isBoost($order_item) || $this->orderItemClassifier->isDonation($order_item)) {
      return FALSE;
    }

    $purchased = $order_item->getPurchasedEntity();
    if (!$purchased instanceof ProductVariationInterface) {
      return FALSE;
    }

    $event = $this->resolveEvent($order_item, $purchased);
    if (!$event instanceof NodeInterface) {
      $this->logger->notice(
        'Order item @oi on order @oid has no resolvable event for ticket-backed classification; treating as non-ticket.',
        [
          '@oi' => (string) $order_item->id(),
          '@oid' => (string) ($order_item->getOrderId() ?? ''),
        ]
      );
      return FALSE;
    }

    $tier = $this->ticketTierResolver->resolveTierForVariation($event, $purchased);
    if (!$tier instanceof TicketTypeInterface) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Customer-facing ticket tier label when ticket-backed; NULL otherwise.
   */
  public function resolveTicketTierLabel(OrderItemInterface $order_item): ?string {
    if (!$this->isTicketBackedOrderItem($order_item)) {
      return NULL;
    }

    $purchased = $order_item->getPurchasedEntity();
    if (!$purchased instanceof ProductVariationInterface) {
      return NULL;
    }

    $event = $this->resolveEvent($order_item, $purchased);
    if (!$event instanceof NodeInterface) {
      return NULL;
    }

    $tier = $this->ticketTierResolver->resolveTierForVariation($event, $purchased);
    if (!$tier instanceof TicketTypeInterface) {
      return NULL;
    }

    $label = trim((string) $tier->label());
    return $label !== '' ? $label : NULL;
  }

  private function resolveEvent(OrderItemInterface $order_item, ProductVariationInterface $variation): ?NodeInterface {
    if ($order_item->hasField('field_target_event') && !$order_item->get('field_target_event')->isEmpty()) {
      $event = $order_item->get('field_target_event')->entity;
      if ($event instanceof NodeInterface && $event->bundle() === 'event') {
        return $event;
      }
      $event_id = (int) $order_item->get('field_target_event')->target_id;
      if ($event_id > 0) {
        $loaded = $this->entityTypeManager->getStorage('node')->load($event_id);
        if ($loaded instanceof NodeInterface && $loaded->bundle() === 'event') {
          return $loaded;
        }
      }
    }

    if ($variation->hasField('field_event') && !$variation->get('field_event')->isEmpty()) {
      $event = $variation->get('field_event')->entity;
      if ($event instanceof NodeInterface && $event->bundle() === 'event') {
        return $event;
      }
    }

    $product = $variation->getProduct();
    if ($product instanceof ProductInterface && $product->hasField('field_event') && !$product->get('field_event')->isEmpty()) {
      $event = $product->get('field_event')->entity;
      if ($event instanceof NodeInterface && $event->bundle() === 'event') {
        return $event;
      }
    }

    return NULL;
  }

}
