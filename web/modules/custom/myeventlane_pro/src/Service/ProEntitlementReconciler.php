<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\myeventlane_pro\Event\ProGracePeriodClearedEvent;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\user\UserInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Reconciles Pro Organiser role entitlements against subscription state.
 *
 * Subscription state is canonical; role + vendor.is_pro are derived.
 *
 * Hybrid model: subscription-managed role grants coexist with manual admin
 * assignment. Revocation only occurs when field_pro_subscription_managed is
 * TRUE and no active Pro subscription exists for the user.
 */
final class ProEntitlementReconciler {

  private const PRO_ROLE = 'mel_pro';
  private const MANAGED_FIELD = 'field_pro_subscription_managed';
  private const GRACE_FIELD = 'field_pro_grace_expires';
  private const BILLING_SCHEDULE = 'mel_pro_monthly';
  private const BATCH_SIZE = 50;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly ProSubscriptionStateResolver $stateResolver,
    private readonly LoggerChannelInterface $logger,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly EventDispatcherInterface $eventDispatcher,
  ) {}

  /**
   * Full reconciliation across all users and subscriptions.
   *
   * @return array{active_checked: int, roles_added: int, roles_removed: int}
   */
  public function reconcile(): array {
    $stats = [
      'active_checked' => 0,
      'roles_added' => 0,
      'roles_removed' => 0,
    ];

    $this->grantForActiveSubscriptions($stats);
    $this->revokeStaleEntitlements($stats);

    $this->logger->notice('Pro entitlement reconciliation complete: @active_checked checked, @roles_added granted, @roles_removed revoked.', [
      '@active_checked' => $stats['active_checked'],
      '@roles_added' => $stats['roles_added'],
      '@roles_removed' => $stats['roles_removed'],
    ]);

    return $stats;
  }

  /**
   * Reconciles a single user's Pro entitlement.
   *
   * Safe to call from event subscribers for immediate per-user correction.
   */
  public function reconcileUser(UserInterface $user): void {
    if ($user->isAnonymous()) {
      return;
    }

    if (!$this->hasRequiredFields($user)) {
      $this->logger->error('User entity missing @field field. Run database updates.', [
        '@field' => self::MANAGED_FIELD . ', ' . self::GRACE_FIELD,
      ]);
      return;
    }

    // A recurring subscription remains active while Commerce retries a failed
    // renewal. Preserve the explicit grace marker until an order-paid event
    // confirms recovery.
    if ($this->isInGrace($user)) {
      $this->ensureRole($user);
      $this->syncVendorProFlag($user, TRUE);
    }
    elseif ($this->userHasActiveProSubscription($user)) {
      $changed = $this->ensureRole($user);
      $this->syncVendorProFlag($user, TRUE);
      $this->clearGracePeriod($user);
      if ($changed) {
        $this->invalidateUserProTags($user);
      }
    }
    else {
      $this->revokeIfManaged($user);
      $this->syncVendorProFlag($user, FALSE);
      $this->invalidateUserProTags($user);
    }
  }

  /**
   * Pass 1: ensure every user with an active Pro subscription has the role.
   */
  private function grantForActiveSubscriptions(array &$stats): void {
    $storage = $this->entityTypeManager->getStorage('commerce_subscription');

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('billing_schedule', self::BILLING_SCHEDULE)
      ->execute();

    $processedUids = [];

    foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
      /** @var \Drupal\commerce_recurring\Entity\SubscriptionInterface[] $subscriptions */
      $subscriptions = $storage->loadMultiple($chunk);

      foreach ($subscriptions as $subscription) {
        if (!$this->stateResolver->isActive($subscription)) {
          continue;
        }

        $user = $subscription->getCustomer();
        if (!$user instanceof UserInterface || $user->isAnonymous()) {
          continue;
        }

        $uid = (int) $user->id();
        if (isset($processedUids[$uid])) {
          continue;
        }
        $processedUids[$uid] = TRUE;

        $stats['active_checked']++;
        if ($this->ensureRole($user)) {
          $stats['roles_added']++;
          $this->invalidateUserProTags($user);
        }
        $this->syncVendorProFlag($user, TRUE);
      }

      $storage->resetCache($chunk);
    }
  }

  /**
   * Pass 2: revoke from users whose managed flag is set but have no active sub.
   */
  private function revokeStaleEntitlements(array &$stats): void {
    $userStorage = $this->entityTypeManager->getStorage('user');

    $uids = $userStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition(self::MANAGED_FIELD, 1)
      ->execute();

    foreach (array_chunk($uids, self::BATCH_SIZE) as $chunk) {
      /** @var \Drupal\user\UserInterface[] $users */
      $users = $userStorage->loadMultiple($chunk);

      foreach ($users as $user) {
        if (!$user instanceof UserInterface) {
          continue;
        }

        if ($this->isInGrace($user)) {
          continue;
        }

        if (!$this->userHasActiveProSubscription($user)) {
          $hadRole = $user->hasRole(self::PRO_ROLE);
          $this->revokeIfManaged($user);
          $this->syncVendorProFlag($user, FALSE);
          if ($hadRole) {
            $stats['roles_removed']++;
            $this->invalidateUserProTags($user);
          }
        }
      }

      $userStorage->resetCache($chunk);
    }
  }

  /**
   * Checks whether the user owns at least one active Pro subscription.
   */
  private function userHasActiveProSubscription(UserInterface $user): bool {
    $storage = $this->entityTypeManager->getStorage('commerce_subscription');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('billing_schedule', self::BILLING_SCHEDULE)
      ->condition('uid', $user->id())
      ->execute();

    if ($ids === []) {
      return FALSE;
    }

    /** @var \Drupal\commerce_recurring\Entity\SubscriptionInterface[] $subscriptions */
    $subscriptions = $storage->loadMultiple($ids);
    foreach ($subscriptions as $subscription) {
      if ($this->stateResolver->isActive($subscription)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Sets grace expiry for a managed Pro user.
   */
  public function setGracePeriod(UserInterface $user, int $expiresAt): bool {
    if (!$this->hasRequiredFields($user)) {
      return FALSE;
    }

    $targetExpiry = max($expiresAt, $this->time->getRequestTime());
    $currentExpiry = (int) ($user->get(self::GRACE_FIELD)->value ?? 0);

    if ($currentExpiry === $targetExpiry) {
      return FALSE;
    }

    $user->set(self::GRACE_FIELD, $targetExpiry);
    if (!(bool) $user->get(self::MANAGED_FIELD)->value) {
      $user->set(self::MANAGED_FIELD, TRUE);
    }
    if (!$user->hasRole(self::PRO_ROLE)) {
      $user->addRole(self::PRO_ROLE);
    }
    $user->save();
    return TRUE;
  }

  /**
   * Clears grace expiry value.
   */
  public function clearGracePeriod(UserInterface $user): bool {
    if (!$user->hasField(self::GRACE_FIELD)) {
      return FALSE;
    }

    $currentExpiry = (int) ($user->get(self::GRACE_FIELD)->value ?? 0);
    if ($currentExpiry === 0) {
      return FALSE;
    }

    $user->set(self::GRACE_FIELD, NULL);
    $user->save();
    $this->eventDispatcher->dispatch(new ProGracePeriodClearedEvent($user));
    return TRUE;
  }

  /**
   * Extends an existing grace period by a number of seconds from the later of now or current expiry.
   *
   * @return bool
   *   TRUE when the user was saved with a new expiry.
   */
  public function extendGracePeriodBySeconds(UserInterface $user, int $additionalSeconds): bool {
    if ($additionalSeconds <= 0) {
      return FALSE;
    }

    $now = $this->time->getRequestTime();
    $current = (int) ($user->get(self::GRACE_FIELD)->value ?? 0);
    $base = max($now, $current);
    return $this->setGracePeriod($user, $base + $additionalSeconds);
  }

  /**
   * Revokes entitlements for users whose grace has expired.
   *
   * @return int
   *   Number of roles revoked.
   */
  public function reconcileExpiredGracePeriods(): int {
    $revoked = 0;
    $now = $this->time->getRequestTime();
    $userStorage = $this->entityTypeManager->getStorage('user');

    $uids = $userStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition(self::MANAGED_FIELD, 1)
      ->condition(self::GRACE_FIELD, $now, '<')
      ->execute();

    foreach (array_chunk($uids, self::BATCH_SIZE) as $chunk) {
      /** @var \Drupal\user\UserInterface[] $users */
      $users = $userStorage->loadMultiple($chunk);
      foreach ($users as $user) {
        if (!$user instanceof UserInterface) {
          continue;
        }

        if ($this->userHasActiveProSubscription($user)) {
          $this->clearGracePeriod($user);
          $this->syncVendorProFlag($user, TRUE);
          continue;
        }

        if ($user->hasRole(self::PRO_ROLE)) {
          $revoked++;
        }
        $this->revokeIfManaged($user);
        $this->syncVendorProFlag($user, FALSE);
        $this->invalidateUserProTags($user);
      }

      $userStorage->resetCache($chunk);
    }

    return $revoked;
  }

  /**
   * Ensures user has mel_pro and managed flag set.
   *
   * @return bool
   *   TRUE if user entity was changed and saved.
   */
  private function ensureRole(UserInterface $user): bool {
    if (!$this->hasRequiredFields($user)) {
      $this->logger->error('User entity missing @field field. Run database updates.', [
        '@field' => self::MANAGED_FIELD . ', ' . self::GRACE_FIELD,
      ]);
      return FALSE;
    }

    $changed = FALSE;

    if (!$user->hasRole(self::PRO_ROLE)) {
      $user->addRole(self::PRO_ROLE);
      $changed = TRUE;
    }

    if (!(bool) $user->get(self::MANAGED_FIELD)->value) {
      $user->set(self::MANAGED_FIELD, TRUE);
      $changed = TRUE;
    }

    if ($changed) {
      $user->save();
    }

    return $changed;
  }

  /**
   * Revokes mel_pro only if subscription-managed.
   */
  private function revokeIfManaged(UserInterface $user): void {
    if (!$this->hasRequiredFields($user)) {
      return;
    }

    if (!(bool) $user->get(self::MANAGED_FIELD)->value) {
      return;
    }

    if ($user->hasRole(self::PRO_ROLE)) {
      $user->removeRole(self::PRO_ROLE);
    }

    $user->set(self::MANAGED_FIELD, FALSE);
    $user->set(self::GRACE_FIELD, NULL);
    $user->save();
  }

  /**
   * Determines whether user is currently inside grace period.
   */
  private function isInGrace(UserInterface $user): bool {
    if (!$user->hasField(self::GRACE_FIELD)) {
      return FALSE;
    }

    $expiry = (int) ($user->get(self::GRACE_FIELD)->value ?? 0);
    if ($expiry <= 0) {
      return FALSE;
    }

    return $expiry >= $this->time->getRequestTime();
  }

  /**
   * Verifies required lifecycle fields exist.
   */
  private function hasRequiredFields(UserInterface $user): bool {
    return $user->hasField(self::MANAGED_FIELD) && $user->hasField(self::GRACE_FIELD);
  }

  /**
   * Invalidates store-scoped Pro cache tags for a user.
   */
  private function invalidateUserProTags(UserInterface $user): void {
    $vendorIds = $this->entityTypeManager->getStorage('myeventlane_vendor')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', (int) $user->id())
      ->execute();

    if ($vendorIds === []) {
      return;
    }

    $tags = [];
    $vendors = $this->entityTypeManager->getStorage('myeventlane_vendor')->loadMultiple($vendorIds);
    foreach ($vendors as $vendor) {
      if (!$vendor instanceof Vendor || !$vendor->hasField('field_vendor_store') || $vendor->get('field_vendor_store')->isEmpty()) {
        continue;
      }
      $store = $vendor->get('field_vendor_store')->entity;
      if ($store && $store->id() !== NULL) {
        $storeId = (int) $store->id();
        $tags[] = 'pro_subscription:' . $storeId;
        $tags[] = 'commerce_store:' . $storeId;
      }
    }

    if ($tags !== []) {
      $this->cacheTagsInvalidator->invalidateTags(array_values(array_unique($tags)));
    }
  }

  /**
   * Keeps vendor.is_pro derived from canonical subscription state.
   */
  private function syncVendorProFlag(UserInterface $user, bool $isPro): void {
    $vendorStorage = $this->entityTypeManager->getStorage('myeventlane_vendor');
    $vendorIds = $vendorStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', (int) $user->id())
      ->execute();

    if ($vendorIds === []) {
      return;
    }

    $vendors = $vendorStorage->loadMultiple($vendorIds);
    foreach ($vendors as $vendor) {
      if (!$vendor instanceof Vendor || !$vendor->hasField('is_pro')) {
        continue;
      }
      if ((bool) $vendor->get('is_pro')->value === $isPro) {
        continue;
      }
      $vendor->set('is_pro', $isPro ? 1 : 0);
      $vendor->save();
    }
  }

}
