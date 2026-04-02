<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Creates optional RSVP donation orders in Commerce.
 */
final class DonationService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Creates a donation order and returns the order ID.
   */
  public function createDonationOrder(NodeInterface $event, float $amount, AccountInterface $user): ?int {
    if ($event->bundle() !== 'event' || $event->id() === NULL) {
      return NULL;
    }
    if ($amount <= 0) {
      return NULL;
    }

    $store = $this->resolveStore($event);
    if ($store === NULL) {
      $this->logger->warning('Donation order skipped: no store for event @event.', [
        '@event' => (string) $event->id(),
      ]);
      return NULL;
    }

    $orderType = $this->entityTypeManager->getStorage('commerce_order_type')->load('rsvp_donation')
      ? 'rsvp_donation'
      : 'default';
    $itemType = $this->entityTypeManager->getStorage('commerce_order_item_type')->load('rsvp_donation')
      ? 'rsvp_donation'
      : 'default';

    $currency = $store->getDefaultCurrencyCode() ?: 'AUD';
    $amountString = number_format($amount, 2, '.', '');

    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $this->entityTypeManager->getStorage('commerce_order')->create([
      'type' => $orderType,
      'store_id' => (int) $store->id(),
      'uid' => (int) $user->id(),
      'state' => 'draft',
      'cart' => FALSE,
    ]);
    $order->save();

    $itemTitle = sprintf('Donation - %s', $event->label());
    $orderItem = $this->entityTypeManager->getStorage('commerce_order_item')->create([
      'type' => $itemType,
      'title' => $itemTitle,
      'unit_price' => new Price($amountString, $currency),
      'quantity' => 1,
    ]);

    if ($orderItem->hasField('field_target_event')) {
      $orderItem->set('field_target_event', ['target_id' => (int) $event->id()]);
    }
    if ($orderItem->hasField('field_attendee_data')) {
      $orderItem->set('field_attendee_data', json_encode([
        'event_id' => (int) $event->id(),
        'vendor_id' => (int) $event->getOwnerId(),
        'donation_type' => 'rsvp_optional',
      ]));
    }
    $orderItem->save();

    $order->addItem($orderItem);
    $order->save();

    return (int) $order->id();
  }

  private function resolveStore(NodeInterface $event): ?StoreInterface {
    if ($event->hasField('field_event_store') && !$event->get('field_event_store')->isEmpty()) {
      $store = $event->get('field_event_store')->entity;
      if ($store instanceof StoreInterface) {
        return $store;
      }
    }
    $storeStorage = $this->entityTypeManager->getStorage('commerce_store');
    $defaults = $storeStorage->loadByProperties(['is_default' => TRUE]);
    $default = reset($defaults);
    return $default instanceof StoreInterface ? $default : NULL;
  }

}
