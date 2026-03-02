<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;

/**
 * Checks whether a vendor has an active Stripe connection.
 */
final class StripeChecker {

  private const FIELD_STRIPE_ACCOUNT_ID = 'field_stripe_account_id';

  private const FIELD_STRIPE_CONNECTED = 'field_stripe_connected';

  /**
   * Constructs a StripeChecker service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The module logger.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns TRUE when the account has a connected Stripe destination.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   *
   * @return bool
   *   TRUE when connected; FALSE otherwise.
   */
  public function isConnected(AccountInterface $account): bool {
    $userId = (int) $account->id();
    if ($userId <= 0) {
      return FALSE;
    }

    try {
      if ($this->hasConnectedStore($userId)) {
        return TRUE;
      }

      return $this->hasVendorStripeAccount($userId);
    }
    catch (\Throwable $exception) {
      $this->logger->debug('Stripe connection check failed for user @uid: @message', [
        '@uid' => $userId,
        '@message' => $exception->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Checks Stripe connectivity from the vendor's commerce store.
   *
   * @param int $userId
   *   The user ID.
   *
   * @return bool
   *   TRUE when a store has a connected Stripe account.
   */
  private function hasConnectedStore(int $userId): bool {
    $storeStorage = $this->entityTypeManager->getStorage('commerce_store');
    $storeIds = $storeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $userId)
      ->range(0, 1)
      ->execute();

    if (empty($storeIds)) {
      return FALSE;
    }

    $store = $storeStorage->load((int) reset($storeIds));
    if (!$store || !$store->hasField(self::FIELD_STRIPE_ACCOUNT_ID) || $store->get(self::FIELD_STRIPE_ACCOUNT_ID)->isEmpty()) {
      return FALSE;
    }

    if ($store->hasField(self::FIELD_STRIPE_CONNECTED)) {
      return (bool) $store->get(self::FIELD_STRIPE_CONNECTED)->value;
    }

    return (string) $store->get(self::FIELD_STRIPE_ACCOUNT_ID)->value !== '';
  }

  /**
   * Checks legacy vendor entity Stripe state.
   *
   * @param int $userId
   *   The user ID.
   *
   * @return bool
   *   TRUE when a vendor entity has a Stripe account ID.
   */
  private function hasVendorStripeAccount(int $userId): bool {
    $vendorStorage = $this->entityTypeManager->getStorage('myeventlane_vendor');
    $vendorIds = $vendorStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $userId)
      ->range(0, 1)
      ->execute();

    if (empty($vendorIds)) {
      return FALSE;
    }

    $vendor = $vendorStorage->load((int) reset($vendorIds));
    if (!$vendor || !$vendor->hasField(self::FIELD_STRIPE_ACCOUNT_ID) || $vendor->get(self::FIELD_STRIPE_ACCOUNT_ID)->isEmpty()) {
      return FALSE;
    }

    return (string) $vendor->get(self::FIELD_STRIPE_ACCOUNT_ID)->value !== '';
  }

}
