<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Ticket;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\myeventlane_commerce\Service\OperationalMerchandiseManager as MerchandiseManager;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_commerce\Service\OperationalStockSaleManager;
use Drupal\node\NodeInterface;

/**
 * Issues one non-admission entitlement for each paid operational add-on unit.
 */
final class OperationalEntitlementIssuer {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TicketCodeGenerator $codeGenerator,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly LockBackendInterface $lock,
    private readonly MerchandiseManager $operationalMerchandiseManager,
    private readonly ?OperationalStockSaleManager $stockSaleManager = NULL,
    private readonly ?Connection $database = NULL,
  ) {}

  /**
   * Issues only missing operational entitlement rows.
   *
   * This makes paid-event replay safe.
   */
  public function issueForOrder(OrderInterface $order): void {
    $order_id = (int) $order->id();
    if ($order_id < 1) {
      return;
    }
    // Covers direct recovery calls as well as the order-paid subscriber.
    if ($this->stockSaleManager !== NULL) {
      $this->stockSaleManager->commitPaid($order);
    }

    foreach ($order->getItems() as $order_item) {
      if (!$order_item instanceof OrderItemInterface) {
        continue;
      }
      $variation = $order_item->getPurchasedEntity();
      if (!$variation instanceof ProductVariationInterface) {
        continue;
      }
      $product = $variation->getProduct();
      if (!$product instanceof ProductInterface || !MerchandiseManager::isOperationalProductBundle($product->bundle())) {
        continue;
      }
      $event = $this->resolveEvent($order_item, $variation);
      if (!$event instanceof NodeInterface) {
        $this->loggerFactory->get('myeventlane_tickets')->warning(
          'Paid add-on @item on order @order has no resolvable event; entitlement issuance was skipped.',
          ['@item' => (string) $order_item->id(), '@order' => (string) $order_id],
        );
        continue;
      }

      $lock_name = 'myeventlane_operational_item:' . (int) $order_item->id();
      $acquired = $this->lock->acquire($lock_name, 10.0);
      if (!$acquired) {
        $this->lock->wait($lock_name, 5);
        $acquired = $this->lock->acquire($lock_name, 10.0);
      }
      if (!$acquired) {
        $this->loggerFactory->get('myeventlane_tickets')->error(
          'Could not lock paid add-on @item on order @order for entitlement issuance.',
          ['@item' => (string) $order_item->id(), '@order' => (string) $order_id],
        );
        continue;
      }
      try {
        $expected = max(0, (int) $order_item->getQuantity());
        $existing = $this->countForOrderItem((int) $order_item->id());
        for ($unit = $existing; $unit < $expected; $unit++) {
          $this->createEntitlement($order, $order_item, $variation, $event, $unit);
        }
      }
      finally {
        $release = fn() => $this->lock->release($lock_name);
        if ($this->database?->inTransaction()) {
          $this->database->transactionManager()->addPostTransactionCallback($release);
        }
        else {
          $release();
        }
      }
    }
  }

  /**
   * Counts entitlements already issued for an operational order item.
   */
  private function countForOrderItem(int $order_item_id): int {
    return (int) $this->entityTypeManager->getStorage('myeventlane_ticket')->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_item_id', $order_item_id)
      ->count()
      ->execute();
  }

  /**
   * Creates one operational entitlement for a paid add-on unit.
   */
  private function createEntitlement(
    OrderInterface $order,
    OrderItemInterface $order_item,
    ProductVariationInterface $variation,
    NodeInterface $event,
    int $unit,
  ): void {
    $product = $variation->getProduct();
    $payload = $product instanceof ProductInterface
      ? $this->operationalMerchandiseManager->normalizeProductFieldFromEntity($product)
      : [];
    $type = $this->resolveEntitlementType($product, $payload);
    $label = $product instanceof ProductInterface ? trim((string) $product->label()) : '';
    $variation_label = trim((string) $variation->label());
    $metadata = [
      'source' => 'paid_operational_order_item',
      'unit_index' => $unit,
      'display_label' => $label !== '' ? $label : $variation_label,
      'variation_label' => $variation_label,
      'operational_product_type' => (string) ($payload['operational_product_type'] ?? ''),
      'capability_reference' => (string) ($payload['capability_reference'] ?? ''),
      'pickup_mode' => (string) ($payload['pickup_mode'] ?? ''),
    ];

    $this->entityTypeManager->getStorage('myeventlane_ticket')->create([
      'ticket_code' => $this->codeGenerator->generateUniqueTicketCode(),
      'event_id' => (int) $event->id(),
      'order_id' => (int) $order->id(),
      'order_item_id' => (int) $order_item->id(),
      'purchased_entity' => (int) $variation->id(),
      'purchaser_uid' => (int) $order->getCustomerId(),
      'status' => Ticket::STATUS_ISSUED_UNASSIGNED,
      'entitlement_type' => $type,
      'redemption_limit' => 1,
      'redemption_count' => 0,
      'fulfilment_status' => Ticket::FULFILMENT_PENDING,
      'metadata_json' => $metadata,
    ])->save();
  }

  /**
   * Maps product semantics to the existing entitlement policy.
   *
   * Unknown hospitality and bundle products remain generic add-ons.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface|null $product
   *   The operational product, when available.
   * @param array<string, mixed> $payload
   *   Normalised operational product metadata.
   *
   * @return string
   *   The entitlement type constant.
   */
  private function resolveEntitlementType(?ProductInterface $product, array $payload): string {
    $capability = strtolower(trim((string) ($payload['capability_reference'] ?? '')));
    if ($capability === 'parking_addon' || str_contains($capability, 'parking')) {
      return Ticket::ENTITLEMENT_PARKING;
    }
    if (str_contains($capability, 'drink')) {
      return Ticket::ENTITLEMENT_DRINK;
    }
    if (str_contains($capability, 'food') || str_contains($capability, 'meal')) {
      return Ticket::ENTITLEMENT_FOOD;
    }
    if (str_contains($capability, 'vip')) {
      return Ticket::ENTITLEMENT_VIP;
    }
    return in_array($product?->bundle(), ['operational_merchandise', 'timed_collection_product'], TRUE)
      ? Ticket::ENTITLEMENT_MERCH
      : Ticket::ENTITLEMENT_ADDON;
  }

  /**
   * Resolves the event associated with an operational order item.
   */
  private function resolveEvent(
    OrderItemInterface $order_item,
    ProductVariationInterface $variation,
  ): ?NodeInterface {
    foreach ([$order_item, $variation, $variation->getProduct()] as $entity) {
      if ($entity && $entity->hasField('field_target_event') && !$entity->get('field_target_event')->isEmpty()) {
        $event = $entity->get('field_target_event')->entity;
        if ($event instanceof NodeInterface && $event->bundle() === 'event') {
          return $event;
        }
      }
      if ($entity && $entity->hasField('field_event') && !$entity->get('field_event')->isEmpty()) {
        $event = $entity->get('field_event')->entity;
        if ($event instanceof NodeInterface && $event->bundle() === 'event') {
          return $event;
        }
      }
    }
    return NULL;
  }

}
