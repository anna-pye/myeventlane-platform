<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Service;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_checkout_flow\Service\VendorOwnershipResolver;
use Drupal\node\NodeInterface;

/**
 * Adapts VendorOwnershipResolver for refund financial context checks.
 */
final class VendorOwnershipStoreBridge implements StoreEventOwnershipInterface {

  /**
   * Constructs the bridge.
   */
  public function __construct(
    private readonly VendorOwnershipResolver $vendorOwnershipResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getStoreForUser(AccountInterface $account): ?StoreInterface {
    return $this->vendorOwnershipResolver->getStoreForUser($account);
  }

  /**
   * {@inheritdoc}
   */
  public function vendorOwnsEvent(StoreInterface $store, NodeInterface $event): bool {
    return $this->vendorOwnershipResolver->vendorOwnsEvent($store, $event);
  }

}
