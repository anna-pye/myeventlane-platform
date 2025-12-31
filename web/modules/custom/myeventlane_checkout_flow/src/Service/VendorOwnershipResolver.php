<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\node\NodeInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Resolves vendor ownership for events and stores.
 */
final class VendorOwnershipResolver {

  /**
   * Constructs VendorOwnershipResolver.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Checks if a vendor (store) owns an event.
   *
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The vendor store.
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return bool
   *   TRUE if the store's vendor owns the event, FALSE otherwise.
   */
  public function vendorOwnsEvent(StoreInterface $store, NodeInterface $event): bool {
    // Check if event has field_event_vendor.
    if (!$event->hasField('field_event_vendor') || $event->get('field_event_vendor')->isEmpty()) {
      // Fallback: check if event owner matches store owner.
      return (int) $event->getOwnerId() === (int) $store->getOwnerId();
    }

    $event_vendor = $event->get('field_event_vendor')->entity;
    if (!$event_vendor) {
      return FALSE;
    }

    // Check if vendor has field_vendor_store that matches.
    if ($event_vendor->hasField('field_vendor_store') && !$event_vendor->get('field_vendor_store')->isEmpty()) {
      $vendor_store = $event_vendor->get('field_vendor_store')->entity;
      if ($vendor_store && $vendor_store->id() === $store->id()) {
        return TRUE;
      }
    }

    // Fallback: check if vendor owner matches store owner.
    return (int) $event_vendor->getOwnerId() === (int) $store->getOwnerId();
  }

  /**
   * Gets the store for a given user account.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return \Drupal\commerce_store\Entity\StoreInterface|null
   *   The store, or NULL if not found.
   */
  public function getStoreForUser(AccountInterface $account): ?StoreInterface {
    // Try to find store via vendor entity first.
    if (\Drupal::moduleHandler()->moduleExists('myeventlane_vendor')) {
      $vendor_storage = $this->entityTypeManager->getStorage('myeventlane_vendor');
      $vendors = $vendor_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_owner', $account->id())
        ->range(0, 1)
        ->execute();

      if (!empty($vendors)) {
        $vendor = $vendor_storage->load(reset($vendors));
        if ($vendor && $vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
          $store = $vendor->get('field_vendor_store')->entity;
          if ($store instanceof StoreInterface) {
            return $store;
          }
        }
      }
    }

    // Fallback: find store by owner UID.
    $store_storage = $this->entityTypeManager->getStorage('commerce_store');
    $store_ids = $store_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $account->id())
      ->range(0, 1)
      ->execute();

    if (!empty($store_ids)) {
      $store = $store_storage->load(reset($store_ids));
      if ($store instanceof StoreInterface) {
        return $store;
      }
    }

    return NULL;
  }

}

