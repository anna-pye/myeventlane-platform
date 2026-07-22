<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_vendor\Service\EventVendorAccessCheckerInterface;
use Drupal\node\NodeInterface;

/**
 * Resolves access control for refund operations.
 *
 * Organiser financial access requires:
 * - operation-specific permission (caller / vendorCanRefundOrderForEvent)
 * - workspace parity (EventVendorAccessChecker)
 * - valid store ↔ event relationship (StoreEventOwnershipInterface)
 * - for refunds: order contains items for the event, order store is valid, refundable state.
 *
 * Store ownership alone must not bypass workspace parity.
 *
 * @see docs/adr/ADR-0008-canonical-event-ownership.md
 */
final class RefundAccessResolver {

  /**
   * Constructs RefundAccessResolver.
   *
   * @param \Drupal\myeventlane_refunds\Service\StoreEventOwnershipInterface $storeEventOwnership
   *   Store ↔ event ownership gateway.
   * @param \Drupal\myeventlane_refunds\Service\RefundOrderItemsInterface $orderInspector
   *   Order item extraction for event binding.
   * @param \Drupal\myeventlane_vendor\Service\EventVendorAccessCheckerInterface $eventVendorAccessChecker
   *   Canonical workspace parity checker.
   */
  public function __construct(
    private readonly StoreEventOwnershipInterface $storeEventOwnership,
    private readonly RefundOrderItemsInterface $orderInspector,
    private readonly EventVendorAccessCheckerInterface $eventVendorAccessChecker,
  ) {}

  /**
   * Checks if a vendor can manage an event for financial surfaces.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return bool
   *   TRUE if the vendor can manage the event, FALSE otherwise.
   */
  public function vendorCanManageEvent(NodeInterface $event, AccountInterface $account): bool {
    // Documented staff bypass — unchanged.
    if ($account->hasPermission('administer commerce_order')) {
      return TRUE;
    }

    if ($event->bundle() !== 'event') {
      return FALSE;
    }

    if (!$this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($event, $account)) {
      return FALSE;
    }

    return $this->accountHasValidStoreRelationshipForEvent($event, $account);
  }

  /**
   * Checks if a vendor can refund an order for a specific event.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return bool
   *   TRUE if the vendor can refund the order, FALSE otherwise.
   */
  public function vendorCanRefundOrderForEvent(OrderInterface $order, NodeInterface $event, AccountInterface $account): bool {
    if (!$account->hasPermission('manage_refunds') && !$account->hasPermission('administer commerce_order')) {
      return FALSE;
    }

    // Must be able to manage the event (parity AND store).
    if (!$this->vendorCanManageEvent($event, $account)) {
      return FALSE;
    }

    // Order must contain ticket items for this event.
    $eventItems = $this->orderInspector->extractItemsForEvent($order, (int) $event->id());
    if (empty($eventItems)) {
      return FALSE;
    }

    // Order store must belong to the same financial context as the event.
    // Staff with administer commerce_order retain the pre-existing bypass of
    // organiser store binding (they still need event items + refundable state).
    if (!$account->hasPermission('administer commerce_order')
      && !$this->orderHasValidStoreRelationshipForEvent($order, $event)) {
      return FALSE;
    }

    // Order must be in a refundable state (align with vendor order list + Commerce workflows).
    $orderState = $order->getState()->getId();
    $refundableStates = [
      'completed',
      'fulfilled',
      'placed',
      'fulfillment',
      'partially_refunded',
    ];
    if (!in_array($orderState, $refundableStates, TRUE)) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Gets access result for event management.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result.
   */
  public function accessManageEvent(NodeInterface $event, AccountInterface $account): AccessResult {
    $allowed = $this->vendorCanManageEvent($event, $account);
    return AccessResult::allowedIf($allowed)
      ->cachePerUser()
      ->addCacheableDependency($event);
  }

  /**
   * Gets access result for order refund.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result.
   */
  public function accessRefundOrder(OrderInterface $order, NodeInterface $event, AccountInterface $account): AccessResult {
    $allowed = $this->vendorCanRefundOrderForEvent($order, $event, $account);
    return AccessResult::allowedIf($allowed)
      ->cachePerUser()
      ->addCacheableDependency($order)
      ->addCacheableDependency($event);
  }

  /**
   * Proves the account's resolvable store is valid for the event.
   */
  private function accountHasValidStoreRelationshipForEvent(NodeInterface $event, AccountInterface $account): bool {
    $store = $this->storeEventOwnership->getStoreForUser($account);
    if (!$store instanceof StoreInterface) {
      return FALSE;
    }

    return $this->storeEventOwnership->vendorOwnsEvent($store, $event);
  }

  /**
   * Proves the order's store (when present) is valid for the event.
   *
   * Missing store fails closed for refund mutations.
   */
  private function orderHasValidStoreRelationshipForEvent(OrderInterface $order, NodeInterface $event): bool {
    $store = $order->getStore();
    if (!$store instanceof StoreInterface) {
      return FALSE;
    }

    return $this->storeEventOwnership->vendorOwnsEvent($store, $event);
  }

}
