<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Service;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Store ↔ event ownership questions needed by refund access.
 */
interface StoreEventOwnershipInterface {

  /**
   * Resolves the Commerce store for an account.
   */
  public function getStoreForUser(AccountInterface $account): ?StoreInterface;

  /**
   * Whether the store is valid for the event in the MEL vendor/store model.
   */
  public function vendorOwnsEvent(StoreInterface $store, NodeInterface $event): bool;

}
