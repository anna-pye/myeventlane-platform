<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Inspects orders to determine refund eligibility and calculate amounts.
 */
final class RefundOrderInspector {

  /**
   * Donation order item bundles.
   */
  private const DONATION_BUNDLES = [
    'checkout_donation',
    'platform_donation',
    'rsvp_donation',
  ];

  /**
   * Constructs RefundOrderInspector.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Checks if an order item is a donation.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $item
   *   The order item.
   *
   * @return bool
   *   TRUE if the item is a donation, FALSE otherwise.
   */
  public function isDonationItem(OrderItemInterface $item): bool {
    return in_array($item->bundle(), self::DONATION_BUNDLES, TRUE);
  }

  /**
   * Checks if an order item is a ticket (not a donation).
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $item
   *   The order item.
   *
   * @return bool
   *   TRUE if the item is a ticket, FALSE otherwise.
   */
  public function isTicketItem(OrderItemInterface $item): bool {
    return !$this->isDonationItem($item);
  }

  /**
   * Gets the event ID from an order item.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $item
   *   The order item.
   *
   * @return int|null
   *   The event node ID, or NULL if not found.
   */
  public function getEventIdFromItem(OrderItemInterface $item): ?int {
    if (!$item->hasField('field_target_event') || $item->get('field_target_event')->isEmpty()) {
      return NULL;
    }

    $eventId = $item->get('field_target_event')->target_id;
    return $eventId ? (int) $eventId : NULL;
  }

  /**
   * Extracts order items for a specific event.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param int $event_nid
   *   The event node ID.
   *
   * @return \Drupal\commerce_order\Entity\OrderItemInterface[]
   *   Array of order items for this event.
   */
  public function extractItemsForEvent(OrderInterface $order, int $event_nid): array {
    $items = [];
    foreach ($order->getItems() as $item) {
      $itemEventId = $this->getEventIdFromItem($item);
      if ($itemEventId === $event_nid) {
        $items[] = $item;
      }
    }
    return $items;
  }

  /**
   * Calculates ticket subtotal in cents for a specific event.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param int $event_nid
   *   The event node ID.
   *
   * @return int
   *   Ticket subtotal in cents.
   */
  public function calculateTicketSubtotalCents(OrderInterface $order, int $event_nid): int {
    $items = $this->extractItemsForEvent($order, $event_nid);
    $total = 0;

    foreach ($items as $item) {
      if ($this->isTicketItem($item)) {
        $totalPrice = $item->getTotalPrice();
        if ($totalPrice) {
          $total += (int) round($totalPrice->getNumber() * 100);
        }
      }
    }

    return $total;
  }

  /**
   * Calculates donation total in cents for the entire order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return int
   *   Donation total in cents.
   */
  public function calculateDonationTotalCents(OrderInterface $order): int {
    $total = 0;

    foreach ($order->getItems() as $item) {
      if ($this->isDonationItem($item)) {
        $totalPrice = $item->getTotalPrice();
        if ($totalPrice) {
          $total += (int) round($totalPrice->getNumber() * 100);
        }
      }
    }

    return $total;
  }

  /**
   * Calculates refundable amount in cents based on payments and existing refunds.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return int
   *   Refundable amount in cents.
   */
  public function calculateRefundableAmountCents(OrderInterface $order): int {
    // Get all payments for this order.
    $paymentStorage = $this->entityTypeManager->getStorage('commerce_payment');
    $paymentIds = $paymentStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_id', $order->id())
      ->condition('state', ['completed', 'partially_refunded'], 'IN')
      ->execute();

    if (empty($paymentIds)) {
      return 0;
    }

    $payments = $paymentStorage->loadMultiple($paymentIds);
    $totalPaid = 0;
    $totalRefunded = 0;

    foreach ($payments as $payment) {
      $amount = $payment->getAmount();
      if ($amount) {
        $totalPaid += (int) round($amount->getNumber() * 100);
      }

      $refundedAmount = $payment->getRefundedAmount();
      if ($refundedAmount) {
        $totalRefunded += (int) round($refundedAmount->getNumber() * 100);
      }
    }

    return max(0, $totalPaid - $totalRefunded);
  }

  /**
   * Masks an email address for display.
   *
   * @param string $email
   *   The email address.
   *
   * @return string
   *   Masked email (e.g., "u***@example.com").
   */
  public function maskEmail(string $email): string {
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return '';
    }

    [$local, $domain] = explode('@', $email, 2);
    $maskedLocal = strlen($local) > 1
      ? substr($local, 0, 1) . str_repeat('*', min(3, strlen($local) - 1))
      : '*';
    return $maskedLocal . '@' . $domain;
  }

}

