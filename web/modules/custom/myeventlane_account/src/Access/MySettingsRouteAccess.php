<?php

declare(strict_types=1);

namespace Drupal\myeventlane_account\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;

/**
 * Ensures customer profile settings are only reachable for the account owner.
 */
final class MySettingsRouteAccess {

  /**
   * Checks access to the canonical profile settings route.
   */
  public static function access(RouteMatchInterface $route_match, AccountInterface $account): AccessResult {
    $user = $route_match->getParameter('user');
    if (!$user instanceof UserInterface) {
      return AccessResult::forbidden()->addCacheContexts(['user']);
    }

    $allowed = $account->isAuthenticated()
      && (int) $account->id() === (int) $user->id();

    return AccessResult::allowedIf($allowed)
      ->addCacheContexts(['user'])
      ->cachePerUser();
  }

}
