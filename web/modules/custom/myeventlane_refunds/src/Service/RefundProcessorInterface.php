<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Creates and processes authorised refunds.
 */
interface RefundProcessorInterface {

  /**
   * Requests a refund and returns its audit-log ID.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The paid order.
   * @param \Drupal\node\NodeInterface $event
   *   The event associated with the refund.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account authorising the refund.
   * @param array<string, mixed> $payload
   *   Refund scope, type, amount, and audit context.
   */
  public function requestRefund(
    OrderInterface $order,
    NodeInterface $event,
    AccountInterface $account,
    array $payload,
  ): int;

  /**
   * Requests one idempotent ticket-only refund for an event cancellation.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The paid order.
   * @param \Drupal\node\NodeInterface $event
   *   The cancelled event.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The organiser who authorised the cancellation.
   */
  public function requestEventCancellationRefund(
    OrderInterface $order,
    NodeInterface $event,
    AccountInterface $account,
  ): int;

}
