<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\myeventlane_core\Service\StripeService;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for handling Stripe Connect payments for ticket sales.
 *
 * This service ensures correct financial handling:
 * - Ticket revenue → transferred to vendor (minus platform fee)
 * - Organiser donations → transferred to vendor (no application fee)
 * - Platform donations → retained by platform (not transferred to vendor)
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
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\myeventlane_commerce\Service\OrderItemClassifier $orderItemClassifier
   *   The order item classifier.
   * @param \Drupal\myeventlane_commerce\Service\TicketBackedOrderItemClassifierInterface $ticketBackedOrderItemClassifier
   *   Ticket-backed line item classifier for Connect revenue boundaries.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StripeService $stripeService,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly OrderItemClassifier $orderItemClassifier,
    private readonly TicketBackedOrderItemClassifierInterface $ticketBackedOrderItemClassifier,
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
    if (!$this->orderRequiresConnect($order)) {
      return NULL;
    }

    // Organiser donations may use the platform default store on the order; resolve
    // the Connect account from field_target_event → event → vendor → store.
    $accountId = $this->resolveStripeAccountIdFromOrganiserDonations($order);
    if (!empty($accountId)) {
      return $accountId;
    }

    $store = $order->getStore();
    if (!$store) {
      return NULL;
    }

    return $this->getStripeAccountIdFromStore($store);
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
    $hasPaidItems = FALSE;
    $eventIds = [];

    foreach ($order->getItems() as $item) {
      if ($item->bundle() === 'boost') {
        continue;
      }

      if ($this->orderItemClassifier->isOrganiserDonation($item)) {
        $totalPrice = $item->getTotalPrice();
        if ($totalPrice && $totalPrice->getNumber() > 0) {
          $hasPaidItems = TRUE;
          if ($item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
            $eventIds[] = $item->get('field_target_event')->target_id;
          }
        }
        continue;
      }

      $purchasedEntity = $item->getPurchasedEntity();
      if ($purchasedEntity) {
        $price = $purchasedEntity->getPrice();
        if ($price && $price->getNumber() > 0) {
          $hasPaidItems = TRUE;

          if ($item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
            $eventIds[] = $item->get('field_target_event')->target_id;
          }
        }
      }
    }

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
   * Calculates ticket revenue from ticket-backed order items only.
   *
   * Excludes donations, boosts, operational merchandise, and add-ons.
   * Operational Connect transfer rules for non-ticket revenue are deferred.
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
      if ($this->orderItemClassifier->isDonation($item)) {
        continue;
      }

      if ($item->bundle() === 'boost') {
        continue;
      }

      if (!$this->ticketBackedOrderItemClassifier->isTicketBackedOrderItem($item)) {
        continue;
      }

      $totalPrice = $item->getTotalPrice();
      if ($totalPrice) {
        $ticketAmount += (int) round($totalPrice->getNumber() * 100);
      }
    }

    return $ticketAmount;
  }

  /**
   * Ticket revenue in cents for one event on this order (excludes donations, boost).
   *
   * Uses the same ticket-backed inclusion rules as calculateTicketRevenue(),
   * scoped to field_target_event = $eventId. Used for vendor MEL percentage
   * contribution accrual so the base matches Connect ticket revenue.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param int $eventId
   *   Event node ID.
   *
   * @return int
   *   Ticket revenue in cents for that event line items.
   */
  public function calculateTicketRevenueCentsForEvent(OrderInterface $order, int $eventId): int {
    if ($eventId <= 0) {
      return 0;
    }

    $ticketAmount = 0;

    foreach ($order->getItems() as $item) {
      if ($this->orderItemClassifier->isDonation($item)) {
        continue;
      }

      if ($item->bundle() === 'boost') {
        continue;
      }

      if (!$this->ticketBackedOrderItemClassifier->isTicketBackedOrderItem($item)) {
        continue;
      }

      if (!$item->hasField('field_target_event') || $item->get('field_target_event')->isEmpty()) {
        continue;
      }

      if ((int) $item->get('field_target_event')->target_id !== $eventId) {
        continue;
      }

      $totalPrice = $item->getTotalPrice();
      if ($totalPrice) {
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
      if ($this->orderItemClassifier->isDonation($item)) {
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
   * Calculates organiser donation revenue (for reference/logging).
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return int
   *   Organiser donation revenue in cents.
   */
  public function calculateOrganiserDonationRevenue(OrderInterface $order): int {
    $donationAmount = 0;

    foreach ($order->getItems() as $item) {
      if ($this->orderItemClassifier->isOrganiserDonation($item)) {
        $totalPrice = $item->getTotalPrice();
        if ($totalPrice) {
          $donationAmount += (int) round($totalPrice->getNumber() * 100);
        }
      }
    }

    return $donationAmount;
  }

  /**
   * Calculates vendor transfer amount in cents (ticket + organiser donations).
   */
  public function calculateVendorTransferAmount(OrderInterface $order): int {
    return $this->calculateTicketRevenue($order) + $this->calculateOrganiserDonationRevenue($order);
  }

  /**
   * Calculates application fee for an order.
   *
   * IMPORTANT: Application fee is calculated ONLY on ticket revenue,
   * NOT on donations. Organiser and platform donations do not incur
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
  public function calculateApplicationFee(OrderInterface $order, ?float $feePercentage = NULL, ?int $fixedFeeCents = NULL): int {
    $config = $this->configFactory->get('myeventlane_core.settings');
    $feePercentage = $feePercentage ?? (float) ($config->get('stripe_fee_percent') ?? 3) / 100;
    $fixedFeeCents = $fixedFeeCents ?? (int) ($config->get('stripe_fee_fixed_cents') ?? 30);

    // Calculate fee only on ticket revenue (excludes donations).
    $ticketRevenue = $this->calculateTicketRevenue($order);
    if ($ticketRevenue <= 0) {
      return 0;
    }

    return $this->stripeService->calculateApplicationFee($ticketRevenue, $feePercentage, $fixedFeeCents);
  }

  /**
   * Gets payment intent parameters for Connect destination charge.
   *
   * STRIPE CONNECT MATH:
   * - Customer pays: total order amount (tickets + donations + fees + tax)
   * - Platform receives: application_fee_amount + platform donation revenue
   * - Vendor receives: ticket revenue + organiser donations - application_fee.
   *
   * Example:
   * - Tickets: $100.00
   * - Organiser donations: $20.00
   * - Application fee (3% + $0.30 on $100): $3.30
   * - Total charged: $120.00
   * - Vendor receives: $120.00 - $3.30 = $116.70
   * - Platform receives: $3.30 (fee)
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

    $ticketRevenue = $this->calculateTicketRevenue($order);
    $organiserDonationRevenue = $this->calculateOrganiserDonationRevenue($order);
    $vendorTransferAmount = $ticketRevenue + $organiserDonationRevenue;

    if ($vendorTransferAmount <= 0) {
      return [];
    }

    $applicationFee = $this->calculateApplicationFee($order);

    $params = [
      'application_fee_amount' => $applicationFee,
      'transfer_data' => [
        'destination' => $accountId,
        'amount' => $vendorTransferAmount,
      ],
      'metadata' => [
        'order_id' => (string) $order->id(),
      ],
    ];

    $platformDonationRevenue = $this->calculateDonationRevenue($order) - $organiserDonationRevenue;
    $this->logger->info(
      'Stripe Connect params for order @order_id: ticket_revenue=@ticket, organiser_donation_revenue=@organiser, platform_donation_revenue=@platform, transfer_amount=@transfer, fee=@fee, vendor_receives=@vendor',
      [
        '@order_id' => $order->id(),
        '@ticket' => $ticketRevenue,
        '@organiser' => $organiserDonationRevenue,
        '@platform' => $platformDonationRevenue,
        '@transfer' => $vendorTransferAmount,
        '@fee' => $applicationFee,
        '@vendor' => $vendorTransferAmount - $applicationFee,
      ]
    );

    return $params;
  }

  /**
   * Checks whether an order requires Stripe Connect (tickets or organiser donations).
   */
  private function orderRequiresConnect(OrderInterface $order): bool {
    foreach ($order->getItems() as $item) {
      if ($item->bundle() === 'boost') {
        return FALSE;
      }
    }

    foreach ($order->getItems() as $item) {
      if ($item->bundle() === 'boost') {
        continue;
      }

      if ($this->orderItemClassifier->isPlatformDonation($item)) {
        continue;
      }

      if ($this->orderItemClassifier->isOrganiserDonation($item)) {
        $totalPrice = $item->getTotalPrice();
        if ($totalPrice && $totalPrice->getNumber() > 0) {
          return TRUE;
        }
        continue;
      }

      $purchasedEntity = $item->getPurchasedEntity();
      if ($purchasedEntity) {
        $price = $purchasedEntity->getPrice();
        if ($price && $price->getNumber() > 0) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  /**
   * Resolves Connect account from organiser donation line items on the order.
   */
  private function resolveStripeAccountIdFromOrganiserDonations(OrderInterface $order): ?string {
    foreach ($order->getItems() as $item) {
      if (!$this->orderItemClassifier->isOrganiserDonation($item)) {
        continue;
      }

      $totalPrice = $item->getTotalPrice();
      if (!$totalPrice || $totalPrice->getNumber() <= 0) {
        continue;
      }

      $accountId = $this->resolveStripeAccountIdFromOrderItemEvent($item);
      if (!empty($accountId)) {
        return $accountId;
      }
    }

    return NULL;
  }

  /**
   * Resolves Connect account via field_target_event → event → vendor → store.
   */
  private function resolveStripeAccountIdFromOrderItemEvent(OrderItemInterface $item): ?string {
    if (!$item->hasField('field_target_event') || $item->get('field_target_event')->isEmpty()) {
      return NULL;
    }

    $event = $item->get('field_target_event')->entity;
    if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
      $eventId = (int) $item->get('field_target_event')->target_id;
      if ($eventId <= 0) {
        return NULL;
      }
      $loaded = $this->entityTypeManager->getStorage('node')->load($eventId);
      if (!$loaded instanceof NodeInterface || $loaded->bundle() !== 'event') {
        return NULL;
      }
      $event = $loaded;
    }

    $store = $this->resolveOrganiserCommerceStoreFromEvent($event);
    if (!$store) {
      return NULL;
    }

    return $this->getStripeAccountIdFromStore($store);
  }

  /**
   * Resolves the organiser Commerce store for an event node.
   *
   * field_event_vendor → field_vendor_store first; then event author fallback.
   */
  private function resolveOrganiserCommerceStoreFromEvent(NodeInterface $event): ?StoreInterface {
    if ($event->hasField('field_event_vendor') && !$event->get('field_event_vendor')->isEmpty()) {
      $vendor = $event->get('field_event_vendor')->entity;
      if ($vendor instanceof ContentEntityInterface
        && $vendor->hasField('field_vendor_store')
        && !$vendor->get('field_vendor_store')->isEmpty()) {
        $candidate = $vendor->get('field_vendor_store')->entity;
        if ($candidate instanceof StoreInterface) {
          return $candidate;
        }
      }
    }

    $vendorUid = (int) $event->getOwnerId();
    if ($vendorUid === 0) {
      return NULL;
    }

    $store = NULL;

    if ($this->entityTypeManager->hasDefinition('myeventlane_vendor')) {
      $vendorStorage = $this->entityTypeManager->getStorage('myeventlane_vendor');
      $vendorIds = $vendorStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_owner', $vendorUid)
        ->range(0, 1)
        ->execute();

      if ($vendorIds !== []) {
        $vendorEntity = $vendorStorage->load(reset($vendorIds));
        if ($vendorEntity instanceof ContentEntityInterface
          && $vendorEntity->hasField('field_vendor_store')
          && !$vendorEntity->get('field_vendor_store')->isEmpty()) {
          $store = $vendorEntity->get('field_vendor_store')->entity;
        }
      }
    }

    if (!$store instanceof StoreInterface) {
      $storeIds = $this->entityTypeManager->getStorage('commerce_store')->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $vendorUid)
        ->range(0, 1)
        ->execute();

      if ($storeIds !== []) {
        $loaded = $this->entityTypeManager->getStorage('commerce_store')->load(reset($storeIds));
        $store = $loaded instanceof StoreInterface ? $loaded : NULL;
      }
    }

    return $store instanceof StoreInterface ? $store : NULL;
  }

  /**
   * Reads field_stripe_account_id from a Commerce store when present.
   */
  private function getStripeAccountIdFromStore(StoreInterface $store): ?string {
    if ($store->hasField('field_stripe_account_id') && !$store->get('field_stripe_account_id')->isEmpty()) {
      $accountId = trim((string) $store->get('field_stripe_account_id')->value);
      if ($accountId !== '') {
        return $accountId;
      }
    }

    return NULL;
  }

}
