<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

use Drupal\commerce_recurring\Entity\SubscriptionInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryException;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\myeventlane_vendor\Service\VendorSubscriptionService;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Canonical resolver for active Pro state.
 */
final class ProActiveResolver {

  private const BILLING_SCHEDULE = 'mel_pro_monthly';

  /**
   * Store IDs already warned for compatibility fallback.
   *
   * @var array<int, bool>
   */
  private array $warnedFallbackStoreIds = [];

  public function __construct(
    private readonly ProSubscriptionStateResolver $subscriptionStateResolver,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly VendorSubscriptionService $vendorSubscriptionService,
    private readonly ProEntitlementManager $proEntitlementManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Determines whether a store is currently Pro active.
   */
  public function isStoreProActive(StoreInterface $store): bool {
    $owner = $this->resolveStoreOwner($store);
    if (!$owner instanceof UserInterface) {
      return FALSE;
    }

    $subscriptionResult = $this->resolveFromSubscriptions($owner);
    if ($subscriptionResult !== NULL) {
      return $subscriptionResult;
    }

    $storeId = (int) ($store->id() ?? 0);
    return $this->resolveCompatibilityFallback($owner, $storeId);
  }

  /**
   * Determines whether a user is currently Pro active.
   */
  public function isUserProActive(UserInterface $account): bool {
    if ($account->isAnonymous()) {
      return FALSE;
    }

    $subscriptionResult = $this->resolveFromSubscriptions($account);
    if ($subscriptionResult !== NULL) {
      return $subscriptionResult;
    }

    return $this->resolveCompatibilityFallback($account, 0);
  }

  /**
   * Resolves the active subscription end timestamp for a store owner.
   */
  public function getStoreActiveEndTimestamp(StoreInterface $store): ?int {
    $owner = $this->resolveStoreOwner($store);
    if (!$owner instanceof UserInterface) {
      return NULL;
    }
    return $this->getUserActiveEndTimestamp($owner);
  }

  /**
   * Resolves the active subscription end timestamp for a user.
   */
  public function getUserActiveEndTimestamp(UserInterface $account): ?int {
    if ($account->isAnonymous()) {
      return NULL;
    }

    $activeSubscriptions = $this->loadUserProSubscriptions($account);
    foreach ($activeSubscriptions as $subscription) {
      if (!$this->subscriptionStateResolver->isActive($subscription)) {
        continue;
      }
      if (method_exists($subscription, 'getNextRenewalTime')) {
        $nextRenewal = (int) $subscription->getNextRenewalTime();
        if ($nextRenewal > 0) {
          return $nextRenewal;
        }
      }
    }

    return NULL;
  }

  /**
   * Returns TRUE/FALSE when subscription state is determinable, otherwise NULL.
   */
  private function resolveFromSubscriptions(UserInterface $account): ?bool {
    try {
      $subscriptions = $this->loadUserProSubscriptions($account);
      if ($subscriptions === []) {
        return NULL;
      }

      foreach ($subscriptions as $subscription) {
        if ($this->subscriptionStateResolver->isActive($subscription)) {
          return TRUE;
        }
      }

      return FALSE;
    }
    catch (\Throwable $exception) {
      $this->logger->error('Failed determining Pro state from subscription resolver for user @uid: @message', [
        '@uid' => (int) $account->id(),
        '@message' => $exception->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Loads Pro subscriptions for a user.
   *
   * @return \Drupal\commerce_recurring\Entity\SubscriptionInterface[]
   *   User subscriptions.
   */
  private function loadUserProSubscriptions(UserInterface $account): array {
    $storage = $this->entityTypeManager->getStorage('commerce_subscription');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', (int) $account->id())
      ->condition('billing_schedule', self::BILLING_SCHEDULE)
      ->sort('subscription_id', 'DESC')
      ->execute();

    if ($ids === []) {
      return [];
    }

    $subscriptions = $storage->loadMultiple($ids);
    return array_values(array_filter($subscriptions, static fn($subscription): bool => $subscription instanceof SubscriptionInterface));
  }

  /**
   * Compatibility fallback (vendor.is_pro / role).
   */
  private function resolveCompatibilityFallback(UserInterface $owner, int $storeId): bool {
    $fallback = FALSE;

    $vendorId = $this->resolveVendorIdByOwner((int) $owner->id(), $storeId);
    if ($vendorId > 0) {
      $fallback = $this->vendorSubscriptionService->isProVendor($vendorId);
    }

    if (!$fallback) {
      $fallback = $this->proEntitlementManager->isPro($owner);
    }

    if ($storeId > 0 && !isset($this->warnedFallbackStoreIds[$storeId])) {
      $this->warnedFallbackStoreIds[$storeId] = TRUE;
      $this->logger->warning('Using compatibility fallback to resolve Pro state for store @store_id. Subscription state could not be determined.', [
        '@store_id' => $storeId,
      ]);
    }

    return $fallback;
  }

  /**
   * Resolves store owner from owner field or vendor link.
   */
  private function resolveStoreOwner(StoreInterface $store): ?UserInterface {
    $owner = $store->getOwner();
    if ($owner instanceof UserInterface && !$owner->isAnonymous()) {
      return $owner;
    }

    if ($store->hasField('field_vendor_reference') && !$store->get('field_vendor_reference')->isEmpty()) {
      $vendor = $store->get('field_vendor_reference')->entity;
      if ($vendor instanceof Vendor) {
        $vendorOwner = $vendor->getOwner();
        if ($vendorOwner instanceof UserInterface && !$vendorOwner->isAnonymous()) {
          return $vendorOwner;
        }
      }
    }

    return NULL;
  }

  /**
   * Resolves vendor ID from owner and optional store.
   */
  private function resolveVendorIdByOwner(int $uid, int $storeId): int {
    if ($uid <= 0 || !$this->entityTypeManager->hasDefinition('myeventlane_vendor')) {
      return 0;
    }

    $storage = $this->entityTypeManager->getStorage('myeventlane_vendor');
    $baseQuery = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->range(0, 1);

    if ($storeId > 0) {
      $storeQuery = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $uid)
        ->condition('field_vendor_store', $storeId)
        ->range(0, 1);
      try {
        $ids = $storeQuery->execute();
        return $ids === [] ? 0 : (int) reset($ids);
      }
      catch (QueryException) {
        // Test environments may not install this optional vendor field.
      }
    }

    $ids = $baseQuery->execute();
    return $ids === [] ? 0 : (int) reset($ids);
  }

}
