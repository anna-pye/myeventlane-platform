<?php

declare(strict_types=1);

namespace Drupal\myeventlane_donations\Service;

use Psr\Log\LoggerInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartSessionInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\myeventlane_rsvp\Entity\RsvpSubmission;
use Drupal\node\NodeInterface;
use Drupal\commerce_price\Price;

/**
 * Service for handling RSVP attendee donations (attendee → vendor via Stripe Connect).
 */
final class RsvpDonationService {

  /**
   * Constructs a RsvpDonationService.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\commerce_cart\CartProviderInterface $cartProvider
   *   The cart provider.
   * @param \Drupal\commerce_cart\CartManagerInterface $cartManager
   *   The cart manager.
   * @param \Drupal\commerce_cart\CartSessionInterface $cartSession
   *   The cart session.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CartProviderInterface $cartProvider,
    private readonly CartManagerInterface $cartManager,
    private readonly CartSessionInterface $cartSession,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
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
   * Creates a donation order for an RSVP submission.
   *
   * @param \Drupal\myeventlane_rsvp\Entity\RsvpSubmission $submission
   *   The RSVP submission.
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param float $amount
   *   The donation amount in AUD.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface|null
   *   The created order, or NULL if vendor store not found or Stripe not connected.
   *
   * @throws \Exception
   *   If order creation fails.
   */
  public function createDonationOrder(RsvpSubmission $submission, NodeInterface $event, float $amount): ?OrderInterface {
    $this->logger()->info('Creating RSVP donation order: event=@event_id, submission=@submission_id, amount=@amount', [
      '@event_id' => $event->id(),
      '@submission_id' => $submission->id(),
      '@amount' => $amount,
    ]);

    // Get vendor store for this event.
    $store = $this->getVendorStore($event);
    if (!$store) {
      $this->logger()->warning('No store found for event @event_id', [
        '@event_id' => $event->id(),
      ]);
      return NULL;
    }

    $this->logger()->debug('Store found: @store_id', ['@store_id' => $store->id()]);

    // Verify Stripe Connect is enabled for this store.
    if (!$this->isStripeConnected($store)) {
      $this->logger()->warning('Stripe Connect not enabled for store @store_id', [
        '@store_id' => $store->id(),
      ]);
      return NULL;
    }

    $this->logger()->debug('Stripe Connect verified for store @store_id', ['@store_id' => $store->id()]);

    // Create order directly with rsvp_donation order type.
    try {
      $orderStorage = $this->entityTypeManager->getStorage('commerce_order');
      $order = $orderStorage->create([
        'type' => 'rsvp_donation',
        'store_id' => $store->id(),
      // Anonymous for RSVP donations.
        'uid' => 0,
        'state' => 'draft',
      ]);
      $order->save();
      $this->logger()->debug('Order created: @order_id', ['@order_id' => $order->id()]);
    }
    catch (\Exception $e) {
      $this->logger()->error('Failed to create donation order: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }

    // Create order item.
    $orderItem = $this->createDonationOrderItem($amount, $event, $submission);

    // Use cart manager to add item (handles validation and recalculation).
    try {
      $this->cartManager->addOrderItem($order, $orderItem);
    }
    catch (\Exception $e) {
      $this->logger()->error('Failed to add order item to donation order: @message', [
        '@message' => $e->getMessage(),
      ]);
      // Try direct add as fallback.
      $order->addItem($orderItem);
      $order->save();
    }

    // Add order to cart session for anonymous users so they can access checkout.
    // This is required because Commerce checkout access check uses cart session.
    if ($order->getCustomerId() === 0) {
      $this->cartSession->addCartId($order->id(), CartSessionInterface::ACTIVE);
      $this->logger()->debug('Added order @order_id to cart session for anonymous user', [
        '@order_id' => $order->id(),
      ]);
    }

    $this->logger()->info('Created RSVP donation order @order_id for submission @submission_id: $@amount', [
      '@order_id' => $order->id(),
      '@submission_id' => $submission->id(),
      '@amount' => number_format($amount, 2),
    ]);

    return $order;
  }

  /**
   * Creates a donation order item.
   *
   * @param float $amount
   *   The donation amount in AUD.
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param \Drupal\myeventlane_rsvp\Entity\RsvpSubmission $submission
   *   The RSVP submission.
   *
   * @return \Drupal\commerce_order\Entity\OrderItemInterface
   *   The created order item.
   */
  private function createDonationOrderItem(float $amount, NodeInterface $event, RsvpSubmission $submission): OrderItemInterface {
    $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
    $orderItem = $orderItemStorage->create([
      'type' => 'rsvp_donation',
      'title' => 'Donation for ' . $event->label(),
      'unit_price' => new Price((string) $amount, 'AUD'),
      'quantity' => 1,
    ]);

    // Store event ID if field exists.
    if ($orderItem->hasField('field_target_event')) {
      $orderItem->set('field_target_event', ['target_id' => $event->id()]);
    }

    // Store RSVP submission ID in metadata.
    if ($orderItem->hasField('field_attendee_data')) {
      $metadata = [
        'donation_type' => 'rsvp',
        'rsvp_submission_id' => $submission->id(),
        'event_id' => $event->id(),
        'vendor_uid' => $event->getOwnerId(),
        'created_at' => time(),
      ];
      $orderItem->set('field_attendee_data', json_encode($metadata));
    }

    $orderItem->save();

    return $orderItem;
  }

  /**
   * Gets the vendor store for an event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return \Drupal\commerce_store\Entity\StoreInterface|null
   *   The vendor store, or NULL if not found.
   */
  private function getVendorStore(NodeInterface $event): ?StoreInterface {
    $vendorUid = (int) $event->getOwnerId();
    if ($vendorUid === 0) {
      $this->logger()->warning('Event @event_id has no owner (uid 0)', [
        '@event_id' => $event->id(),
      ]);
      return NULL;
    }

    $store = NULL;

    // First, try to find store via vendor entity (if vendor module is available).
    if ($this->moduleHandler->moduleExists('myeventlane_vendor')) {
      $vendorStorage = $this->entityTypeManager->getStorage('myeventlane_vendor');
      $vendors = $vendorStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_owner', $vendorUid)
        ->range(0, 1)
        ->execute();

      if (!empty($vendors)) {
        $vendor = $vendorStorage->load(reset($vendors));
        if ($vendor && $vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
          $store = $vendor->get('field_vendor_store')->entity;
          $this->logger()->debug('Found store via vendor entity for event @event_id', [
            '@event_id' => $event->id(),
          ]);
        }
      }
    }

    // Fallback: Find store by owner UID.
    if (!$store) {
      $storeStorage = $this->entityTypeManager->getStorage('commerce_store');
      $storeIds = $storeStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $vendorUid)
        ->range(0, 1)
        ->execute();

      if (!empty($storeIds)) {
        $store = $storeStorage->load(reset($storeIds));
        $this->logger()->debug('Found store via owner UID for event @event_id', [
          '@event_id' => $event->id(),
        ]);
      }
    }

    if (!$store) {
      $this->logger()->warning('No store found for vendor uid @uid (event @event_id)', [
        '@uid' => $vendorUid,
        '@event_id' => $event->id(),
      ]);
      return NULL;
    }

    return $store instanceof StoreInterface ? $store : NULL;
  }

  /**
   * Checks if a store has Stripe Connect enabled.
   *
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The store.
   *
   * @return bool
   *   TRUE if Stripe Connect is enabled, FALSE otherwise.
   */
  private function isStripeConnected(StoreInterface $store): bool {
    // Check if charges are enabled (most reliable indicator).
    if ($store->hasField('field_stripe_charges_enabled') && !$store->get('field_stripe_charges_enabled')->isEmpty()) {
      $connected = (bool) $store->get('field_stripe_charges_enabled')->value;
      $this->logger()->debug('Stripe charges enabled check for store @store_id: @connected', [
        '@store_id' => $store->id(),
        '@connected' => $connected ? 'yes' : 'no',
      ]);
      return $connected;
    }

    // Fallback: check connected flag.
    if ($store->hasField('field_stripe_connected') && !$store->get('field_stripe_connected')->isEmpty()) {
      $connected = (bool) $store->get('field_stripe_connected')->value;
      $this->logger()->debug('Stripe connected flag check for store @store_id: @connected', [
        '@store_id' => $store->id(),
        '@connected' => $connected ? 'yes' : 'no',
      ]);
      return $connected;
    }

    $this->logger()->debug('No Stripe fields found on store @store_id', [
      '@store_id' => $store->id(),
    ]);
    return FALSE;
  }

}
