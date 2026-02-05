<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Determines if a buyer can self-service refund an order for an event.
 *
 * Enforces event-level refund policy (field_refund_policy) and cutoff dates.
 */
final class BuyerRefundEligibilityService {

  /**
   * Refund policy values that allow buyer self-service.
   *
   * Maps policy key to days before event start when refund window closes.
   * NULL means no fixed window (e.g. case-by-case; request always allowed).
   * Supports both myeventlane_schema keys (7_days, 1_day) and legacy keys (refund_7d, refund_24h).
   */
  private const POLICY_DAYS = [
    '1_day' => 1,
    '7_days' => 7,
    '14_days' => 14,
    '30_days' => 30,
    'refund_24h' => 1,
    'refund_7d' => 7,
    'case_by_case' => NULL,
  ];

  /**
   * Constructs BuyerRefundEligibilityService.
   *
   * @param \Drupal\myeventlane_refunds\Service\RefundOrderInspector $orderInspector
   *   The order inspector.
   */
  public function __construct(
    private readonly RefundOrderInspector $orderInspector,
  ) {}

  /**
   * Checks if the buyer can request a self-service refund for an order/event.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The buyer (order owner).
   *
   * @return bool
   *   TRUE if eligible, FALSE otherwise.
   */
  public function isEligible(OrderInterface $order, NodeInterface $event, AccountInterface $account): bool {
    if (!$this->buyerOwnsOrder($order, $account)) {
      return FALSE;
    }

    if (!$this->isOrderRefundable($order)) {
      return FALSE;
    }

    $eventId = (int) $event->id();
    if (empty($this->orderInspector->extractItemsForEvent($order, $eventId))) {
      return FALSE;
    }

    if (!$this->policyAllowsRefund($event)) {
      return FALSE;
    }

    if (!$this->withinRefundWindow($event)) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Gets the reason why a refund is not eligible (for display).
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The buyer.
   *
   * @return string|null
   *   Human-readable reason, or NULL if eligible.
   */
  public function getIneligibilityReason(OrderInterface $order, NodeInterface $event, AccountInterface $account): ?string {
    if (!$this->buyerOwnsOrder($order, $account)) {
      return 'You do not own this order.';
    }

    if (!$this->isOrderRefundable($order)) {
      return 'This order is not in a refundable state.';
    }

    $eventId = (int) $event->id();
    if (empty($this->orderInspector->extractItemsForEvent($order, $eventId))) {
      return 'This order does not contain tickets for this event.';
    }

    if (!$this->policyAllowsRefund($event)) {
      return 'This event does not allow refunds.';
    }

    if (!$this->withinRefundWindow($event)) {
      return 'The refund window for this event has closed.';
    }

    return NULL;
  }

  /**
   * Checks if the account owns the order.
   */
  private function buyerOwnsOrder(OrderInterface $order, AccountInterface $account): bool {
    if ($account->isAnonymous()) {
      return FALSE;
    }

    $customerId = $order->getCustomerId();
    return $customerId && (int) $customerId === (int) $account->id();
  }

  /**
   * Checks if the order is in a refundable state.
   */
  private function isOrderRefundable(OrderInterface $order): bool {
    $state = $order->getState()->getId();
    return in_array($state, ['completed', 'fulfilled', 'placed'], TRUE);
  }

  /**
   * Checks if the event's refund policy allows buyer self-service.
   *
   * Allows time-based policies (1_day, 7_days, etc.) and case_by_case.
   */
  private function policyAllowsRefund(NodeInterface $event): bool {
    if (!$event->hasField('field_refund_policy') || $event->get('field_refund_policy')->isEmpty()) {
      return FALSE;
    }

    $policy = trim((string) $event->get('field_refund_policy')->value);

    if ($policy === '' || $policy === 'no_refunds' || $policy === 'none_specified') {
      return FALSE;
    }

    return array_key_exists($policy, self::POLICY_DAYS);
  }

  /**
   * Checks if the current time is within the refund window.
   *
   * Window closes N days before event start (start of that day, UTC).
   * For case_by_case (NULL days), there is no cutoff — request is always allowed.
   */
  private function withinRefundWindow(NodeInterface $event): bool {
    if (!$event->hasField('field_refund_policy') || $event->get('field_refund_policy')->isEmpty()) {
      return FALSE;
    }

    $policy = trim((string) $event->get('field_refund_policy')->value);
    $days = self::POLICY_DAYS[$policy] ?? NULL;

    // Case-by-case: no fixed window; vendor decides. Allow request anytime.
    if ($days === NULL) {
      return array_key_exists($policy, self::POLICY_DAYS);
    }

    if (!$event->hasField('field_event_start') || $event->get('field_event_start')->isEmpty()) {
      return FALSE;
    }

    try {
      $eventStart = $event->get('field_event_start')->date;
      if (!$eventStart) {
        return FALSE;
      }
      $eventStartTs = $eventStart->getTimestamp();
    }
    catch (\Exception $e) {
      return FALSE;
    }

    $cutoffTs = $eventStartTs - ($days * 86400);
    return time() < $cutoffTs;
  }

}
