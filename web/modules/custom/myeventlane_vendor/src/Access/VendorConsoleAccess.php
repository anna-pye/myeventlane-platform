<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access check for vendor console routes.
 *
 * Allows access for:
 * - Administrators (UID 1 or has 'administer site configuration')
 * - Users with 'access vendor console' permission
 */
final class VendorConsoleAccess {

  /**
   * Checks access for vendor console routes.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check access for.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public static function access(RouteMatchInterface $route_match, AccountInterface $account): AccessResult {
    // Administrators always have access.
    if ($account->id() === 1 || $account->hasPermission('administer site configuration')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    // Users with vendor console permission.
    if ($account->hasPermission('access vendor console')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return AccessResult::forbidden()->cachePerPermissions();
  }

}















