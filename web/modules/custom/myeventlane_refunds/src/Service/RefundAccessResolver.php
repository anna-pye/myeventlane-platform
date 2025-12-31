<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_checkout_flow\Service\VendorOwnershipResolver;
use Drupal\node\NodeInterface;

/**
 * Resolves access control for refund operations.
 */
final class RefundAccessResolver {

  /**
   * Constructs RefundAccessResolver.
   *
   * @param \Drupal\myeventlane_checkout_flow\Service\VendorOwnershipResolver $vendorOwnershipResolver
   *   The vendor ownership resolver.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly VendorOwnershipResolver $vendorOwnershipResolver,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Checks if a vendor can manage an event.
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
    // Admin override.
    if ($account->hasPermission('administer commerce_order')) {
      return TRUE;
    }

    // Check if user owns the event.
    if ((int) $event->getOwnerId() === (int) $account->id()) {
      return TRUE;
    }

    // Check via vendor store ownership.
    $store = $this->vendorOwnershipResolver->getStoreForUser($account);
    if ($store) {
      return $this->vendorOwnershipResolver->vendorOwnsEvent($store, $event);
    }

    return FALSE;
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
    // Must be able to manage the event.
    if (!$this->vendorCanManageEvent($event, $account)) {
      return FALSE;
    }

    // Order must contain ticket items for this event.
    $orderInspector = \Drupal::service('myeventlane_refunds.order_inspector');
    $eventItems = $orderInspector->extractItemsForEvent($order, (int) $event->id());
    if (empty($eventItems)) {
      return FALSE;
    }

    // Order must be in a refundable state.
    $orderState = $order->getState()->getId();
    if (!in_array($orderState, ['completed', 'fulfilled', 'placed'], TRUE)) {
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

}

