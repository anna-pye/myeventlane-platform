<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor_comms\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_commerce\Service\OrderItemClassifier;
use Drupal\node\NodeInterface;

/**
 * Resolves email recipients for event communications.
 */
final class EventRecipientResolver {

  /**
   * Constructs EventRecipientResolver.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\myeventlane_commerce\Service\OrderItemClassifier $orderItemClassifier
   *   Canonical Commerce order item classifier.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly OrderItemClassifier $orderItemClassifier,
  ) {}

  /**
   * Gets unique email addresses for an event's attendees.
   *
   * Only includes emails from valid orders (completed/placed/fulfilled).
   * Excludes canceled/refunded orders.
   * De-duplicates emails per event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Array of unique email addresses.
   */
  public function getRecipientEmails(NodeInterface $event): array {
    $emails = [];
    $eventId = (int) $event->id();

    // Find order items for this event.
    $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
    $orderItemIds = $orderItemStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_target_event', $eventId)
      ->execute();

    if (empty($orderItemIds)) {
      return $emails;
    }

    $orderItems = $orderItemStorage->loadMultiple($orderItemIds);
    $processedOrders = [];

    foreach ($orderItems as $orderItem) {
      if (!$orderItem instanceof OrderItemInterface) {
        continue;
      }

      if (!$this->orderItemClassifier->isVendorRevenueEligible($orderItem)) {
        continue;
      }

      if ($orderItem->get('order_id')->isEmpty()) {
        continue;
      }

      $order_id = (int) $orderItem->getOrderId();
      if ($order_id <= 0) {
        continue;
      }

      /** @var \Drupal\commerce_order\Entity\OrderInterface|null $order */
      $order = $this->entityTypeManager->getStorage('commerce_order')->load($order_id);
      if (!$order instanceof OrderInterface) {
        continue;
      }

      // Skip if order already processed (multiple items per order).
      $orderId = $order->id();
      if (isset($processedOrders[$orderId])) {
        continue;
      }

      // Only include completed/placed/fulfilled orders.
      $orderState = $order->getState()->getId();
      if (!in_array($orderState, ['completed', 'placed', 'fulfilled'], TRUE)) {
        continue;
      }

      // Exclude canceled/refunded orders.
      if (in_array($orderState, ['canceled', 'refunded'], TRUE)) {
        continue;
      }

      // Get order email.
      $orderEmail = $order->getEmail();
      if (!empty($orderEmail) && filter_var($orderEmail, FILTER_VALIDATE_EMAIL)) {
        // De-duplicate by email (lowercase).
        $emails[strtolower($orderEmail)] = $orderEmail;
      }

      $processedOrders[$orderId] = TRUE;
    }

    // Return unique emails as array.
    return array_values($emails);
  }

  /**
   * Gets recipient count for an event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return int
   *   Number of unique recipients.
   */
  public function getRecipientCount(NodeInterface $event): int {
    return count($this->getRecipientEmails($event));
  }

}
