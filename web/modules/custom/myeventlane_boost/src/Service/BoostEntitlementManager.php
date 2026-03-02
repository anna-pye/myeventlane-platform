<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_boost\Entity\BoostEntitlementInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Manages Boost entitlement lifecycle.
 */
final class BoostEntitlementManager {

  /**
   * Constructs a BoostEntitlementManager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Determines whether the order item is a Boost purchase.
   */
  public function isBoostOrderItem(OrderItemInterface $orderItem): bool {
    $bundle = $orderItem->bundle();
    if ($bundle === 'boost') {
      return TRUE;
    }

    $variation = $orderItem->getPurchasedEntity();
    if (!$variation instanceof ProductVariationInterface) {
      return FALSE;
    }

    if ($variation->bundle() === 'boost_duration' || $variation->bundle() === 'boost_upgrade') {
      return TRUE;
    }

    $product = $variation->getProduct();
    return $product !== NULL && $product->bundle() === 'boost_upgrade';
  }

  /**
   * Activates an entitlement from a paid order item (idempotent by item ID).
   */
  public function activateEntitlementFromOrderItem(OrderInterface $order, OrderItemInterface $orderItem, ?int $starts = NULL): ?BoostEntitlementInterface {
    if (!$this->isBoostOrderItem($orderItem)) {
      return NULL;
    }

    if (!$orderItem->hasField('field_target_event') || $orderItem->get('field_target_event')->isEmpty()) {
      $this->logger->error('Boost order item @item_id is missing field_target_event.', [
        '@item_id' => $orderItem->id(),
      ]);
      return NULL;
    }

    $target_event = $orderItem->get('field_target_event')->entity;
    if (!$target_event instanceof NodeInterface || $target_event->bundle() !== 'event') {
      $this->logger->error('Boost order item @item_id has invalid target event.', [
        '@item_id' => $orderItem->id(),
      ]);
      return NULL;
    }

    $existing = $this->loadByOrderItemId((int) $orderItem->id());
    if ($existing instanceof BoostEntitlementInterface) {
      $this->logger->info('Boost entitlement already exists for order item @item_id; skipping duplicate activation.', [
        '@item_id' => $orderItem->id(),
      ]);
      $this->refreshEventBoostState((int) $target_event->id());
      return $existing;
    }

    $variation = $orderItem->getPurchasedEntity();
    if (!$variation instanceof ProductVariationInterface) {
      $this->logger->error('Boost order item @item_id has no purchasable variation.', [
        '@item_id' => $orderItem->id(),
      ]);
      return NULL;
    }

    $starts_at = $starts ?? $this->time->getRequestTime();
    $boost_days = $this->extractBoostDays($orderItem, $variation);
    $ends_at = $starts_at + ($boost_days * 86400);

    $owner_id = (int) $target_event->getOwnerId();
    if ($owner_id <= 0) {
      $this->logger->error('Boost target event @event_id has no valid owner.', [
        '@event_id' => $target_event->id(),
      ]);
      return NULL;
    }

    $values = [
      'uid' => $owner_id,
      'event' => (int) $target_event->id(),
      'order_id' => (int) $order->id(),
      'order_item_id' => (int) $orderItem->id(),
      'variation_id' => (int) $variation->id(),
      'starts' => $starts_at,
      'ends' => $ends_at,
      'status' => BoostEntitlementInterface::STATUS_ACTIVE,
      'label' => sprintf('Boost #%d for %s', (int) $orderItem->id(), $target_event->label()),
    ];

    $total_price = $orderItem->getTotalPrice();
    if ($total_price instanceof Price) {
      $values['amount_paid'] = [
        'number' => $total_price->getNumber(),
        'currency_code' => $total_price->getCurrencyCode(),
      ];
    }

    /** @var \Drupal\myeventlane_boost\Entity\BoostEntitlementInterface $entitlement */
    $entitlement = $this->entityTypeManager->getStorage('myeventlane_boost_entitlement')->create($values);
    $entitlement->save();

    $this->logger->notice('Activated boost entitlement @eid for event @event_id from order @order_id item @item_id.', [
      '@eid' => $entitlement->id(),
      '@event_id' => $target_event->id(),
      '@order_id' => $order->id(),
      '@item_id' => $orderItem->id(),
    ]);

    $this->refreshEventBoostState((int) $target_event->id());
    return $entitlement;
  }

  /**
   * Revokes all entitlements created from an order.
   */
  public function revokeEntitlementsForOrder(OrderInterface $order, string $reason = 'refund_or_void'): int {
    $storage = $this->entityTypeManager->getStorage('myeventlane_boost_entitlement');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_id', (int) $order->id())
      ->condition('status', BoostEntitlementInterface::STATUS_REVOKED, '<>')
      ->execute();

    if (empty($ids)) {
      return 0;
    }

    $count = 0;
    /** @var \Drupal\myeventlane_boost\Entity\BoostEntitlementInterface[] $entitlements */
    $entitlements = $storage->loadMultiple($ids);
    foreach ($entitlements as $entitlement) {
      $entitlement->set('status', BoostEntitlementInterface::STATUS_REVOKED);
      $entitlement->save();
      $count++;

      $event_id = (int) ($entitlement->get('event')->target_id ?? 0);
      if ($event_id > 0) {
        $this->refreshEventBoostState($event_id);
      }

      $this->logger->warning('Revoked boost entitlement @eid from order @oid due to @reason.', [
        '@eid' => $entitlement->id(),
        '@oid' => $order->id(),
        '@reason' => $reason,
      ]);
    }

    return $count;
  }

  /**
   * Returns active entitlement entities for a vendor.
   *
   * @return \Drupal\myeventlane_boost\Entity\BoostEntitlementInterface[]
   *   Active entitlements sorted by soonest end date.
   */
  public function getActiveEntitlementsForVendor(int $uid, int $limit = 20): array {
    if ($uid <= 0) {
      return [];
    }

    $now = $this->time->getRequestTime();
    $storage = $this->entityTypeManager->getStorage('myeventlane_boost_entitlement');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('status', BoostEntitlementInterface::STATUS_ACTIVE)
      ->condition('ends', $now, '>')
      ->sort('ends', 'ASC');

    if ($limit > 0) {
      $query->range(0, $limit);
    }

    $ids = $query->execute();
    if (empty($ids)) {
      return [];
    }

    /** @var \Drupal\myeventlane_boost\Entity\BoostEntitlementInterface[] $entitlements */
    $entitlements = $storage->loadMultiple($ids);
    return $entitlements;
  }

  /**
   * Returns the latest active end timestamp for an event.
   */
  public function getActiveEndTimestampForEvent(int $eventNid): ?int {
    if ($eventNid <= 0) {
      return NULL;
    }

    $now = $this->time->getRequestTime();
    $storage = $this->entityTypeManager->getStorage('myeventlane_boost_entitlement');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $eventNid)
      ->condition('status', BoostEntitlementInterface::STATUS_ACTIVE)
      ->condition('ends', $now, '>')
      ->sort('ends', 'DESC')
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return NULL;
    }

    /** @var \Drupal\myeventlane_boost\Entity\BoostEntitlementInterface|null $entitlement */
    $entitlement = $storage->load(reset($ids));
    if (!$entitlement instanceof BoostEntitlementInterface) {
      return NULL;
    }

    $value = $entitlement->get('ends')->value;
    return $value !== NULL ? (int) $value : NULL;
  }

  /**
   * Marks active entitlements as expired when they pass end date.
   */
  public function expireEndedEntitlements(): int {
    $now = $this->time->getRequestTime();
    $storage = $this->entityTypeManager->getStorage('myeventlane_boost_entitlement');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', BoostEntitlementInterface::STATUS_ACTIVE)
      ->condition('ends', $now, '<=')
      ->execute();

    if (empty($ids)) {
      return 0;
    }

    $updated = 0;
    $event_ids = [];
    /** @var \Drupal\myeventlane_boost\Entity\BoostEntitlementInterface[] $entitlements */
    $entitlements = $storage->loadMultiple($ids);
    foreach ($entitlements as $entitlement) {
      $entitlement->set('status', BoostEntitlementInterface::STATUS_EXPIRED);
      $entitlement->save();
      $updated++;
      $event_id = (int) ($entitlement->get('event')->target_id ?? 0);
      if ($event_id > 0) {
        $event_ids[$event_id] = $event_id;
      }
    }

    foreach ($event_ids as $event_id) {
      $this->refreshEventBoostState($event_id);
    }

    return $updated;
  }

  /**
   * Synchronizes denormalized event boost fields to entitlement state.
   */
  public function refreshEventBoostState(int $eventNid): void {
    if ($eventNid <= 0) {
      return;
    }

    $node = $this->entityTypeManager->getStorage('node')->load($eventNid);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'event') {
      return;
    }

    $active_end = $this->getActiveEndTimestampForEvent($eventNid);
    if ($active_end !== NULL) {
      if ($node->hasField('field_promoted')) {
        $node->set('field_promoted', 1);
      }
      if ($node->hasField('field_promo_expires')) {
        $node->set('field_promo_expires', gmdate('Y-m-d\TH:i:s', $active_end));
      }
    }
    else {
      if ($node->hasField('field_promoted')) {
        $node->set('field_promoted', 0);
      }
      if ($node->hasField('field_promo_expires')) {
        $node->set('field_promo_expires', NULL);
      }
    }

    $node->save();
  }

  /**
   * Loads an entitlement by source order item ID.
   */
  private function loadByOrderItemId(int $orderItemId): ?BoostEntitlementInterface {
    if ($orderItemId <= 0) {
      return NULL;
    }

    $ids = $this->entityTypeManager->getStorage('myeventlane_boost_entitlement')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_item_id', $orderItemId)
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return NULL;
    }

    $entity = $this->entityTypeManager->getStorage('myeventlane_boost_entitlement')->load(reset($ids));
    return $entity instanceof BoostEntitlementInterface ? $entity : NULL;
  }

  /**
   * Resolves purchased boost days from line item or variation.
   */
  private function extractBoostDays(OrderItemInterface $orderItem, ProductVariationInterface $variation): int {
    $days = 0;
    if ($orderItem->hasField('field_boost_days_purchased') && !$orderItem->get('field_boost_days_purchased')->isEmpty()) {
      $days = (int) $orderItem->get('field_boost_days_purchased')->value;
    }

    if ($days <= 0 && $variation->hasField('field_boost_days') && !$variation->get('field_boost_days')->isEmpty()) {
      $days = (int) $variation->get('field_boost_days')->value;
    }

    return max(1, $days);
  }

}
