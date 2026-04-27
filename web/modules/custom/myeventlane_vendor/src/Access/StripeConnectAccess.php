<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;

/**
 * Access check for starting or resuming Stripe Connect onboarding.
 */
final class StripeConnectAccess {

  /**
   * Allows authenticated vendor-console users to start Stripe onboarding.
   */
  public function access(AccountInterface $account): AccessResult {
    if ($account->isAnonymous()) {
      return AccessResult::forbidden()
        ->addCacheContexts(['user.roles']);
    }

    if ($account->hasPermission('access vendor console')) {
      return AccessResult::allowed()
        ->addCacheContexts(['user.permissions']);
    }

    return AccessResult::forbidden()
      ->addCacheContexts(['user.permissions']);
  }

}
