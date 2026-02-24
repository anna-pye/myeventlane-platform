<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\user\UserInterface;

/**
 * Reconciles Pro Organiser role entitlements against subscription state.
 *
 * Hybrid model: subscription-managed role grants coexist with manual admin
 * assignment. Revocation only occurs when field_pro_subscription_managed is
 * TRUE and no active Pro subscription exists for the user.
 */
final class ProEntitlementReconciler {

  private const PRO_ROLE = 'pro_organiser';
  private const MANAGED_FIELD = 'field_pro_subscription_managed';
  private const BILLING_SCHEDULE = 'mel_pro_monthly';
  private const BATCH_SIZE = 50;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelInterface $logger,
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

    if (!$user->hasField(self::MANAGED_FIELD)) {
      $this->logger->error('User entity missing @field field. Run database updates.', [
        '@field' => self::MANAGED_FIELD,
      ]);
      return;
    }

    if ($this->userHasActiveProSubscription($user)) {
      $this->ensureRole($user);
    }
    else {
      $this->revokeIfManaged($user);
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
      ->condition('state', 'active')
      ->execute();

    $processedUids = [];

    foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
      /** @var \Drupal\commerce_recurring\Entity\SubscriptionInterface[] $subscriptions */
      $subscriptions = $storage->loadMultiple($chunk);

      foreach ($subscriptions as $subscription) {
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
        }
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

        if (!$this->userHasActiveProSubscription($user)) {
          $hadRole = $user->hasRole(self::PRO_ROLE);
          $this->revokeIfManaged($user);
          if ($hadRole) {
            $stats['roles_removed']++;
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
    $count = (int) $this->entityTypeManager->getStorage('commerce_subscription')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('billing_schedule', self::BILLING_SCHEDULE)
      ->condition('state', 'active')
      ->condition('uid', $user->id())
      ->count()
      ->execute();

    return $count > 0;
  }

  /**
   * Ensures user has pro_organiser and managed flag set.
   *
   * @return bool
   *   TRUE if user entity was changed and saved.
   */
  private function ensureRole(UserInterface $user): bool {
    if (!$user->hasField(self::MANAGED_FIELD)) {
      $this->logger->error('User entity missing @field field. Run database updates.', [
        '@field' => self::MANAGED_FIELD,
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
   * Revokes pro_organiser only if subscription-managed.
   */
  private function revokeIfManaged(UserInterface $user): void {
    if (!$user->hasField(self::MANAGED_FIELD)) {
      return;
    }

    if (!(bool) $user->get(self::MANAGED_FIELD)->value) {
      return;
    }

    if ($user->hasRole(self::PRO_ROLE)) {
      $user->removeRole(self::PRO_ROLE);
    }

    $user->set(self::MANAGED_FIELD, FALSE);
    $user->save();
  }

}
