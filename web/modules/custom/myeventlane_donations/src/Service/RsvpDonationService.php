<?php

declare(strict_types=1);

namespace Drupal\myeventlane_donations\Service;

use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_cart\CartSessionInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_rsvp\Entity\RsvpSubmission;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for handling RSVP attendee donations.
 */
final class RsvpDonationService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CartProviderInterface $cartProvider,
    private readonly CartManagerInterface $cartManager,
    private readonly CartSessionInterface $cartSession,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  private function logger(): LoggerInterface {
    return $this->loggerFactory->get('myeventlane_donations');
  }

  /**
   * Creates a donation order for an RSVP submission.
   */
  public function createDonationOrder(RsvpSubmission $submission, NodeInterface $event, float $amount): ?OrderInterface {
    $this->logger()->info('Creating RSVP donation order: event=@event_id, submission=@submission_id, amount=@amount', [
      '@event_id' => $event->id(),
      '@submission_id' => $submission->id(),
      '@amount' => $amount,
    ]);

    $store = $this->getVendorStore($event);
    if (!$store) {
      $this->logger()->warning('No store found for event @event_id', ['@event_id' => $event->id()]);
      return NULL;
    }

    if (!$this->isStripeConnected($store)) {
      $this->logger()->warning('Stripe Connect not enabled for store @store_id', ['@store_id' => $store->id()]);
      return NULL;
    }

    $orderStorage = $this->entityTypeManager->getStorage('commerce_order');
    $customerUid = (int) $this->currentUser->id();
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $orderStorage->create([
      'type' => 'rsvp_donation',
      'store_id' => $store->id(),
      'uid' => $customerUid,
      'state' => 'draft',
      'cart' => TRUE,
    ]);
    $order->save();

    $orderItem = $this->createDonationOrderItem($amount, $event, $submission);

    try {
      $this->cartManager->addOrderItem($order, $orderItem);
    }
    catch (\Throwable $e) {
      $this->logger()->error('Failed to add order item to donation order: @message', [
        '@message' => $e->getMessage(),
      ]);
      $order->addItem($orderItem);
      $order->save();
    }

    // Required for anonymous checkout access.
    if ((int) $order->getCustomerId() === 0) {
      $this->cartSession->addCartId($order->id(), CartSessionInterface::ACTIVE);
    }

    $this->logger()->info('Created RSVP donation order @order_id for submission @submission_id: $@amount', [
      '@order_id' => $order->id(),
      '@submission_id' => $submission->id(),
      '@amount' => number_format($amount, 2),
    ]);

    return $order;
  }

  private function createDonationOrderItem(float $amount, NodeInterface $event, RsvpSubmission $submission): OrderItemInterface {
    $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');

    /** @var \Drupal\commerce_order\Entity\OrderItemInterface $orderItem */
    $orderItem = $orderItemStorage->create([
      'type' => 'rsvp_donation',
      'title' => 'Donation for ' . $event->label(),
      'unit_price' => new Price((string) $amount, 'AUD'),
      'quantity' => 1,
    ]);

    if ($orderItem->hasField('field_target_event')) {
      $orderItem->set('field_target_event', ['target_id' => (int) $event->id()]);
    }

    if ($orderItem->hasField('field_attendee_data')) {
      $metadata = [
        'donation_type' => 'rsvp',
        'rsvp_submission_id' => (int) $submission->id(),
        'event_id' => (int) $event->id(),
        'vendor_uid' => (int) $event->getOwnerId(),
        'created_at' => time(),
      ];
      $orderItem->set('field_attendee_data', (string) json_encode($metadata));
    }

    $orderItem->save();
    return $orderItem;
  }

  private function getVendorStore(NodeInterface $event): ?StoreInterface {
    $vendorUid = (int) $event->getOwnerId();
    if ($vendorUid === 0) {
      $this->logger()->warning('Event @event_id has no owner (uid 0)', ['@event_id' => $event->id()]);
      return NULL;
    }

    $store = NULL;

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
        }
      }
    }

    if (!$store) {
      $storeStorage = $this->entityTypeManager->getStorage('commerce_store');
      $storeIds = $storeStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $vendorUid)
        ->range(0, 1)
        ->execute();

      if (!empty($storeIds)) {
        $store = $storeStorage->load(reset($storeIds));
      }
    }

    return $store instanceof StoreInterface ? $store : NULL;
  }

  private function isStripeConnected(StoreInterface $store): bool {
    if ($store->hasField('field_stripe_charges_enabled') && !$store->get('field_stripe_charges_enabled')->isEmpty()) {
      return (bool) $store->get('field_stripe_charges_enabled')->value;
    }

    if ($store->hasField('field_stripe_connected') && !$store->get('field_stripe_connected')->isEmpty()) {
      return (bool) $store->get('field_stripe_connected')->value;
    }

    return FALSE;
  }

}