<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\myeventlane_core\Service\StripeService;
use Psr\Log\LoggerInterface;

/**
 * Service for handling Stripe Connect payments for ticket sales.
 *
 * This service ensures correct financial handling:
 * - Ticket revenue → transferred to vendor (minus platform fee)
 * - Donation revenue → retained by platform (not transferred to vendor)
 * - Application fees calculated only on ticket revenue.
 */
final class StripeConnectPaymentService {

  use StringTranslationTrait;

  /**
   * Constructs a StripeConnectPaymentService.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\myeventlane_core\Service\StripeService $stripeService
   *   The Stripe service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StripeService $stripeService,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Gets the Stripe Connect account ID for an order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return string|null
   *   The Stripe account ID (acct_xxx), or NULL if not found or not needed.
   */
  public function getStripeAccountIdForOrder(OrderInterface $order): ?string {
    // Get the store from the order.
    $store = $order->getStore();
    if (!$store) {
      return NULL;
    }

    // Check if this is a Boost purchase (should use platform account, not Connect).
    foreach ($order->getItems() as $item) {
      if ($item->bundle() === 'boost') {
        // Boost purchases use platform account, not Connect.
        return NULL;
      }
    }

    // Check if order has paid items that require Connect.
    $hasPaidItems = FALSE;
    foreach ($order->getItems() as $item) {
      $purchasedEntity = $item->getPurchasedEntity();
      if ($purchasedEntity) {
        $price = $purchasedEntity->getPrice();
        if ($price && $price->getNumber() > 0) {
          $hasPaidItems = TRUE;
          break;
        }
      }
    }

    // If no paid items, no Connect needed.
    if (!$hasPaidItems) {
      return NULL;
    }

    // Get Stripe account ID from store.
    if ($store->hasField('field_stripe_account_id') && !$store->get('field_stripe_account_id')->isEmpty()) {
      $accountId = $store->get('field_stripe_account_id')->value;
      if (!empty($accountId)) {
        return $accountId;
      }
    }

    return NULL;
  }

  /**
   * Validates that an order can be processed with Stripe Connect.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array{valid: bool, message: string|null}
   *   Validation result with 'valid' boolean and optional 'message'.
   */
  public function validateOrderForConnect(OrderInterface $order): array {
    // Check if order has paid items.
    $hasPaidItems = FALSE;
    $eventIds = [];

    foreach ($order->getItems() as $item) {
      // Skip Boost items (they use platform account).
      if ($item->bundle() === 'boost') {
        continue;
      }

      $purchasedEntity = $item->getPurchasedEntity();
      if ($purchasedEntity) {
        $price = $purchasedEntity->getPrice();
        if ($price && $price->getNumber() > 0) {
          $hasPaidItems = TRUE;

          // Get event ID from order item if available.
          if ($item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
            $eventIds[] = $item->get('field_target_event')->target_id;
          }
        }
      }
    }

    // If no paid items, validation passes (RSVP/free events).
    if (!$hasPaidItems) {
      return ['valid' => TRUE, 'message' => NULL];
    }

    // Check if store has Stripe Connect account.
    $store = $order->getStore();
    if (!$store) {
      return [
        'valid' => FALSE,
        'message' => $this->t('No store found for this order.'),
      ];
    }

    $accountId = $this->getStripeAccountIdForOrder($order);
    if (empty($accountId)) {
      // Get event title for better error message.
      $eventTitle = 'this event';
      if (!empty($eventIds)) {
        $eventNode = $this->entityTypeManager->getStorage('node')->load(reset($eventIds));
        if ($eventNode) {
          $eventTitle = $eventNode->label();
        }
      }

      return [
        'valid' => FALSE,
        'message' => $this->t('This event\'s organiser has not set up card payments yet. Please try another event or contact the organiser.'),
      ];
    }

    return ['valid' => TRUE, 'message' => NULL];
  }

  /**
   * Checks if an order item is a donation (should be excluded from vendor payout).
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $item
   *   The order item.
   *
   * @return bool
   *   TRUE if the item is a donation, FALSE otherwise.
   */
  private function isDonationItem(OrderItemInterface $item): bool {
    $bundle = $item->bundle();
    return in_array($bundle, ['checkout_donation', 'platform_donation', 'rsvp_donation'], TRUE);
  }

  /**
   * Calculates ticket revenue (excludes donations, boosts, and other non-ticket items).
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return int
   *   Ticket revenue in cents.
   */
  public function calculateTicketRevenue(OrderInterface $order): int {
    $ticketAmount = 0;

    foreach ($order->getItems() as $item) {
      // Skip donation items (platform revenue, not vendor revenue).
      if ($this->isDonationItem($item)) {
        continue;
      }

      // Skip Boost items (they don't use Connect).
      if ($item->bundle() === 'boost') {
        continue;
      }

      $totalPrice = $item->getTotalPrice();
      if ($totalPrice) {
        // Convert to cents.
        $ticketAmount += (int) round($totalPrice->getNumber() * 100);
      }
    }

    return $ticketAmount;
  }

  /**
   * Calculates donation revenue (for reference/logging).
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return int
   *   Donation revenue in cents.
   */
  public function calculateDonationRevenue(OrderInterface $order): int {
    $donationAmount = 0;

    foreach ($order->getItems() as $item) {
      if ($this->isDonationItem($item)) {
        $totalPrice = $item->getTotalPrice();
        if ($totalPrice) {
          // Convert to cents.
          $donationAmount += (int) round($totalPrice->getNumber() * 100);
        }
      }
    }

    return $donationAmount;
  }

  /**
   * Calculates application fee for an order.
   *
   * IMPORTANT: Application fee is calculated ONLY on ticket revenue,
   * NOT on donations. Donations are platform revenue and do not incur
   * vendor payout fees.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param float $feePercentage
   *   Fee percentage (e.g., 0.03 for 3%).
   * @param int $fixedFeeCents
   *   Fixed fee in cents (e.g., 30 for $0.30).
   *
   * @return int
   *   Application fee in cents (calculated on ticket revenue only).
   */
  public function calculateApplicationFee(OrderInterface $order, float $feePercentage = 0.03, int $fixedFeeCents = 30): int {
    // Calculate fee only on ticket revenue (excludes donations).
    $ticketRevenue = $this->calculateTicketRevenue($order);

    return $this->stripeService->calculateApplicationFee($ticketRevenue, $feePercentage, $fixedFeeCents);
  }

  /**
   * Gets payment intent parameters for Connect destination charge.
   *
   * STRIPE CONNECT MATH:
   * - Customer pays: total order amount (tickets + donations + fees + tax)
   * - Platform receives: application_fee_amount + donation revenue
   * - Vendor receives: ticket revenue - application_fee_amount.
   *
   * Example:
   * - Tickets: $100.00
   * - Donations: $20.00
   * - Application fee (3% + $0.30 on $100): $3.30
   * - Total charged: $120.00
   * - Vendor receives: $100.00 - $3.30 = $96.70
   * - Platform receives: $3.30 (fee) + $20.00 (donation) = $23.30
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array
   *   Parameters to add to PaymentIntent creation, or empty array if not needed.
   */
  public function getConnectPaymentIntentParams(OrderInterface $order): array {
    $accountId = $this->getStripeAccountIdForOrder($order);
    if (empty($accountId)) {
      return [];
    }

    // Calculate ticket revenue (excludes donations).
    $ticketRevenue = $this->calculateTicketRevenue($order);

    // If no ticket revenue, no Connect transfer needed.
    // (Donation-only orders should not use Connect.)
    if ($ticketRevenue <= 0) {
      $this->logger->warning(
        'Order @order_id has no ticket revenue but Connect account ID is set. This may indicate a configuration issue.',
        ['@order_id' => $order->id()]
      );
      return [];
    }

    // Calculate application fee on ticket revenue only.
    $applicationFee = $this->calculateApplicationFee($order);

    // Build Connect parameters.
    // Note: In Stripe Connect destination charges:
    // - The total PaymentIntent amount includes everything (tickets + donations)
    // - transfer_data[destination] transfers ticket revenue to vendor
    // - application_fee_amount is deducted from the transfer
    // - Donations remain with platform (not transferred)
    //
    // Stripe automatically calculates: vendor_receives = ticket_revenue - application_fee
    // We use transfer_data[amount] to explicitly set the transfer amount to ticket revenue.
    $params = [
      'application_fee_amount' => $applicationFee,
      'transfer_data' => [
        'destination' => $accountId,
        // Explicitly set transfer amount to ticket revenue.
        // This ensures vendor receives: ticket_revenue - application_fee
        // and donations remain with platform.
        'amount' => $ticketRevenue,
      ],
    ];

    // Log for debugging.
    $donationRevenue = $this->calculateDonationRevenue($order);
    $this->logger->info(
      'Stripe Connect params for order @order_id: ticket_revenue=@ticket, donation_revenue=@donation, fee=@fee, vendor_receives=@vendor',
      [
        '@order_id' => $order->id(),
        '@ticket' => $ticketRevenue,
        '@donation' => $donationRevenue,
        '@fee' => $applicationFee,
        '@vendor' => $ticketRevenue - $applicationFee,
      ]
    );

    return $params;
  }

}
