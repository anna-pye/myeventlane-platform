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
  public function createDonationOrder(int $userId, float $amount, ?int $eventId = NULL): OrderInterface {
    // Get or create cart.
    $store = $this->getDefaultStore();
    $user = $this->entityTypeManager->getStorage('user')->load($userId);
    $cart = $this->cartProvider->getCart('platform_donation', $store, $user);

    if (!$cart) {
      $cart = $this->cartProvider->createCart('platform_donation', $store, $user);
    }

    // Create order item.
    $orderItem = $this->createDonationOrderItem($amount, $eventId);
    $this->cartManager->addOrderItem($cart, $orderItem);

    $this->logger()->info('Created platform donation order @order_id for user @uid: $@amount', [
      '@order_id' => $cart->id(),
      '@uid' => $userId,
      '@amount' => number_format($amount, 2),
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
  private function createDonationOrderItem(float $amount, ?int $eventId = NULL): OrderItemInterface {
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
