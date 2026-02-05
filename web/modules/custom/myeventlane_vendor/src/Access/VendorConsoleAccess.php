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
 * - Users with 'access vendor console' permission.
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
    $uid = $account->id();
    $route_name = $route_match->getRouteName();

    // ALWAYS allow vendor onboarding routes.
    // This prevents a deadlock where Stripe onboarding is blocked
    // by the vendor console access gate itself.
    if ($route_name === 'myeventlane_vendor.onboard' || str_starts_with((string) $route_name, 'myeventlane_vendor.onboard.')) {
      return AccessResult::allowed()->cachePerUser();
    }

    $request = \Drupal::request();
    $host = $request ? $request->getHost() : 'UNKNOWN';

    // DIAGNOSTIC LOGGING: Track access evaluation.
    \Drupal::logger('vendor_access')->debug('VendorConsoleAccess::access called', [
      'route_name' => $route_name ?? 'NULL',
      'host' => $host,
      'uid' => $uid,
      'is_authenticated' => $account->isAuthenticated() ? 'TRUE' : 'FALSE',
      'roles' => implode(', ', $account->getRoles()),
      'has_admin_permission' => $account->hasPermission('administer site configuration') ? 'TRUE' : 'FALSE',
      'has_vendor_console_permission' => $account->hasPermission('access vendor console') ? 'TRUE' : 'FALSE',
    ]);

    // Administrators always have access.
    if ($account->id() === 1 || $account->hasPermission('administer site configuration')) {
      \Drupal::logger('vendor_access')->debug('VendorConsoleAccess: ALLOWED for UID @uid (administrator)', ['@uid' => $account->id()]);
      return AccessResult::allowed()->cachePerPermissions();
    }

    // Users with vendor console permission.
    if ($account->hasPermission('access vendor console')) {
      \Drupal::logger('vendor_access')->debug('VendorConsoleAccess: ALLOWED for UID @uid (has access vendor console permission)', ['@uid' => $account->id()]);
      return AccessResult::allowed()->cachePerPermissions();
    }

    \Drupal::logger('vendor_access')->warning('VendorConsoleAccess: FORBIDDEN for UID @uid (no permission)', ['@uid' => $account->id()]);
    return AccessResult::forbidden()->cachePerPermissions();
  }

}
