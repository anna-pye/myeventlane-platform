<?php

declare(strict_types=1);

namespace Drupal\myeventlane_seed\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for purging seeded demo data.
 */
final class DemoPurger {

  /**
   * Usernames of seeded vendor users.
   */
  private const SEEDED_USERS = ['vendor2', 'vendor3'];

  /**
   * Constructs a DemoPurger.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Purges all seeded demo data.
   *
   * Removes:
   * - Seeded vendor users (vendor2, vendor3)
   * - Their vendor entities
   * - Their stores
   * - Their events and products
   * - RSVP submissions for their events
   *
   * @return array
   *   Purge statistics.
   */
  public function purgeDemo(): array {
    $logger = $this->loggerFactory->get('myeventlane_seed');
    $logger->info('Starting demo data purge...');

    $stats = [
      'users_deleted' => 0,
      'vendors_deleted' => 0,
      'stores_deleted' => 0,
      'events_deleted' => 0,
      'products_deleted' => 0,
      'variations_deleted' => 0,
      'rsvps_deleted' => 0,
    ];

    $userStorage = $this->entityTypeManager->getStorage('user');
    $vendorStorage = $this->entityTypeManager->getStorage('myeventlane_vendor');
    $storeStorage = $this->entityTypeManager->getStorage('commerce_store');
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $productStorage = $this->entityTypeManager->getStorage('commerce_product');
    $variationStorage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $rsvpStorage = $this->entityTypeManager->getStorage('rsvp_submission');

    // Find seeded users.
    $users = $userStorage->loadByProperties(['name' => self::SEEDED_USERS]);
    $userIds = array_map(fn($u) => $u->id(), $users);
    $vendorIds = [];
    $storeIds = [];
    $eventIds = [];
    $productIds = [];

    // Collect vendors, stores, and events for deletion.
    foreach ($users as $user) {
      $vendors = $vendorStorage->loadByProperties(['uid' => $user->id()]);
      foreach ($vendors as $vendor) {
        $vendorIds[] = $vendor->id();

        // Get store.
        if ($vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
          $store = $vendor->get('field_vendor_store')->entity;
          if ($store) {
            $storeIds[] = $store->id();
          }
        }

        // Get events owned by this vendor.
        $events = $nodeStorage->loadByProperties([
          'type' => 'event',
          'field_event_vendor' => $vendor->id(),
        ]);
        foreach ($events as $event) {
          $eventIds[] = $event->id();

          // Get product linked to event.
          if ($event->hasField('field_product_target') && !$event->get('field_product_target')->isEmpty()) {
            $product = $event->get('field_product_target')->entity;
            if ($product) {
              $productIds[] = $product->id();
            }
          }
        }
      }
    }

    // Delete RSVP submissions for these events.
    if (!empty($eventIds)) {
      $rsvps = $rsvpStorage->loadByProperties(['event_id' => $eventIds]);
      foreach ($rsvps as $rsvp) {
        $rsvp->delete();
        $stats['rsvps_deleted']++;
      }
    }

    // Delete product variations.
    if (!empty($productIds)) {
      $variations = $variationStorage->loadByProperties(['product_id' => $productIds]);
      foreach ($variations as $variation) {
        $variation->delete();
        $stats['variations_deleted']++;
      }
    }

    // Delete products.
    if (!empty($productIds)) {
      $products = $productStorage->loadMultiple(array_unique($productIds));
      foreach ($products as $product) {
        $product->delete();
        $stats['products_deleted']++;
      }
    }

    // Delete events.
    if (!empty($eventIds)) {
      $events = $nodeStorage->loadMultiple(array_unique($eventIds));
      foreach ($events as $event) {
        $event->delete();
        $stats['events_deleted']++;
      }
    }

    // Delete stores.
    if (!empty($storeIds)) {
      $stores = $storeStorage->loadMultiple(array_unique($storeIds));
      foreach ($stores as $store) {
        $store->delete();
        $stats['stores_deleted']++;
      }
    }

    // Delete vendors.
    if (!empty($vendorIds)) {
      $vendors = $vendorStorage->loadMultiple(array_unique($vendorIds));
      foreach ($vendors as $vendor) {
        $vendor->delete();
        $stats['vendors_deleted']++;
      }
    }

    // Delete users (this will cascade delete if needed).
    foreach ($users as $user) {
      $user->delete();
      $stats['users_deleted']++;
    }

    $logger->info('Demo data purge complete: @stats', ['@stats' => json_encode($stats)]);

    return $stats;
  }

}

