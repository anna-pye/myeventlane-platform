<?php

declare(strict_types=1);

namespace Drupal\myeventlane_donations\Service;

use Psr\Log\LoggerInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\commerce_price\Price;

/**
 * Service for handling platform donations (vendor → MyEventLane).
 */
final class PlatformDonationService {

  /**
   * Constructs a PlatformDonationService.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\commerce_cart\CartProviderInterface $cartProvider
   *   The cart provider.
   * @param \Drupal\commerce_cart\CartManagerInterface $cartManager
   *   The cart manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CartProviderInterface $cartProvider,
    private readonly CartManagerInterface $cartManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Gets the logger for this service.
   *
   * @return \Psr\Log\LoggerInterface
   *   The logger.
   */
  private function logger(): LoggerInterface {
    return $this->loggerFactory->get('myeventlane_donations');
  }

  /**
   * Creates a donation order for a vendor.
   *
   * @param int $userId
   *   The vendor user ID.
   * @param float $amount
   *   The donation amount in AUD.
   * @param int|null $eventId
   *   Optional event node ID if donation is event-specific.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface
   *   The created order.
   *
   * @throws \Exception
   *   If order creation fails.
   */
  public function createDonationOrder(int $userId, float $amount, ?int $eventId = NULL, bool $replaceExistingCartItems = FALSE): OrderInterface {
    // Get or create cart.
    $store = $this->getDefaultStore();
    $user = $this->entityTypeManager->getStorage('user')->load($userId);
    $cart = $this->cartProvider->getCart('platform_donation', $store, $user);

    if (!$cart) {
      $cart = $this->cartProvider->createCart('platform_donation', $store, $user);
    }

    // Event wizard / idempotent flows: avoid stacking multiple platform_donation
    // line items on the same cart (reuse-first; same order type).
    if ($replaceExistingCartItems) {
      foreach ($cart->getItems() as $existing) {
        $cart->removeItem($existing);
        $existing->delete();
      }
      $cart->save();
    }

    // Create order item.
    $orderItem = $this->createDonationOrderItem($amount, $eventId, $replaceExistingCartItems ? 'event_wizard' : NULL);
    $this->cartManager->addOrderItem($cart, $orderItem);

    $this->logger()->info('Created platform donation order @order_id for user @uid: $@amount', [
      '@order_id' => $cart->id(),
      '@uid' => $userId,
      '@amount' => number_format($amount, 2),
    ]);

    return $cart;
  }

  /**
   * Creates a platform_donation order to pay down a MEL vendor contribution invoice.
   *
   * Uses the same Commerce cart + checkout path as other platform donations.
   * The invoice id is stored on the order item (field_attendee_data) for settlement.
   *
   * @param int $userId
   *   Drupal user paying (vendor owner or team member).
   * @param int $invoiceId
   *   mel_vendor_invoice.id.
   * @param int $amountCents
   *   Amount to charge in minor units (typically remaining balance).
   * @param string $currencyCode
   *   ISO 4217 currency (e.g. AUD, USD).
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface
   *   The created order (cart).
   *
   * @throws \Exception
   *   If order creation fails.
   */
  public function createInvoiceSettlementOrder(int $userId, int $invoiceId, int $amountCents, string $currencyCode): OrderInterface {
    if ($amountCents <= 0) {
      throw new \InvalidArgumentException('Invoice settlement amount must be positive.');
    }

    $store = $this->getDefaultStore();
    $user = $this->entityTypeManager->getStorage('user')->load($userId);
    $cart = $this->cartProvider->getCart('platform_donation', $store, $user);

    if (!$cart) {
      $cart = $this->cartProvider->createCart('platform_donation', $store, $user);
    }

    foreach ($cart->getItems() as $existing) {
      $cart->removeItem($existing);
      $existing->delete();
    }
    $cart->save();

    $amountMajor = number_format($amountCents / 100, 2, '.', '');
    $currencyCode = strtoupper($currencyCode);

    $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
    $orderItem = $orderItemStorage->create([
      'type' => 'platform_donation',
      'title' => 'MyEventLane contribution invoice',
      'unit_price' => new Price($amountMajor, $currencyCode),
      'quantity' => 1,
    ]);

    if ($orderItem->hasField('field_attendee_data')) {
      $metadata = [
        'donation_type' => 'platform',
        'mel_vendor_invoice_id' => $invoiceId,
        'mel_invoice_settlement' => TRUE,
        'created_at' => time(),
      ];
      $orderItem->set('field_attendee_data', json_encode($metadata));
    }

    $orderItem->save();
    $this->cartManager->addOrderItem($cart, $orderItem);

    $this->logger()->info('Created MEL invoice settlement order @order_id for user @uid: invoice @iid @amount @cur', [
      '@order_id' => $cart->id(),
      '@uid' => $userId,
      '@iid' => (string) $invoiceId,
      '@amount' => $amountMajor,
      '@cur' => $currencyCode,
    ]);

    return $cart;
  }

  /**
   * Creates a donation order item.
   *
   * @param float $amount
   *   The donation amount in AUD.
   * @param int|null $eventId
   *   Optional event node ID.
   *
   * @return \Drupal\commerce_order\Entity\OrderItemInterface
   *   The created order item.
   */
  private function createDonationOrderItem(float $amount, ?int $eventId = NULL, ?string $source = NULL): OrderItemInterface {
    $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
    $orderItem = $orderItemStorage->create([
      'type' => 'platform_donation',
      'title' => 'Donation to MyEventLane',
      'unit_price' => new Price((string) $amount, 'AUD'),
      'quantity' => 1,
    ]);

    // Store event ID if provided.
    if ($eventId && $orderItem->hasField('field_target_event')) {
      $orderItem->set('field_target_event', ['target_id' => $eventId]);
    }

    // Store metadata.
    if ($orderItem->hasField('field_attendee_data')) {
      $metadata = [
        'donation_type' => 'platform',
        'created_at' => time(),
      ];
      if ($eventId) {
        $metadata['event_id'] = $eventId;
      }
      if ($source !== NULL && $source !== '') {
        $metadata['source'] = $source;
      }
      $orderItem->set('field_attendee_data', json_encode($metadata));
    }

    $orderItem->save();

    return $orderItem;
  }

  /**
   * Gets the default store.
   *
   * @return \Drupal\commerce_store\Entity\StoreInterface
   *   The default store.
   *
   * @throws \Exception
   *   If no store is found.
   */
  private function getDefaultStore(): StoreInterface {
    $storeStorage = $this->entityTypeManager->getStorage('commerce_store');
    $store = $storeStorage->loadDefault();

    if (!$store) {
      throw new \Exception('No default store found. Please configure a Commerce store.');
    }

    return $store;
  }

}
