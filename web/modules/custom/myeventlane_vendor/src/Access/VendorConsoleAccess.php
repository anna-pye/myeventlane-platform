<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_core\MelVendorOrganiserRole;

/**
 * Access check for vendor console routes.
 *
 * Allows users with the "access vendor console" permission or the "vendor"
 * organiser role (config: user.role.vendor). The role check matches the gate
 * subscriber so organiser accounts are not denied when permission resolution
 * lags role assignment.
 */
final class VendorConsoleAccess {

  /**
   * Cache contexts for all outcomes.
   *
   * @var array<string>
   */
  private const CACHE_CONTEXTS = [
    'user.permissions',
    'user.roles',
    'session',
  ];

  /**
   * Checks access for vendor console routes.
   */
  public static function access(RouteMatchInterface $route_match, AccountInterface $account): AccessResult {
    $request = \Drupal::requestStack()->getCurrentRequest();
    $host = $request?->getHost() ?? '';
    $path = $request?->getPathInfo() ?? '';

    $route_name = $route_match->getRouteName();

    // Allow onboarding routes always.
    if ($route_name === 'myeventlane_vendor.onboard' || str_starts_with((string) $route_name, 'myeventlane_vendor.onboard.')) {
      self::logDecision($account, $host, $path, 'allowed_onboarding');
      return AccessResult::allowed()->addCacheContexts(self::CACHE_CONTEXTS);
    }

    // Block anonymous.
    if ($account->isAnonymous()) {
      self::logDecision($account, $host, $path, 'forbidden_anonymous');
      return AccessResult::forbidden()->addCacheContexts(self::CACHE_CONTEXTS);
    }

    // Allow with permission.
    if ($account->hasPermission('access vendor console')) {
      self::logDecision($account, $host, $path, 'allowed');
      return AccessResult::allowed()->addCacheContexts(self::CACHE_CONTEXTS);
    }

    // Allow organiser role (same trust boundary as the permission on that role).
    if ($account->hasRole(MelVendorOrganiserRole::MACHINE_NAME)) {
      self::logDecision($account, $host, $path, 'allowed_vendor_role');
      return AccessResult::allowed()->addCacheContexts(self::CACHE_CONTEXTS);
    }

    // Default deny.
    self::logDecision($account, $host, $path, 'forbidden_no_permission');
    return AccessResult::forbidden()->addCacheContexts(self::CACHE_CONTEXTS);
  }

  /**
   * Temporary structured logging.
   */
  private static function logDecision(AccountInterface $account, string $host, string $path, string $decision): void {
    \Drupal::logger('mel_vendor_access_debug')->notice(
      'VendorConsoleAccess uid=@uid host=@host path=@path decision=@decision',
      [
        '@uid' => (string) $account->id(),
        '@host' => $host,
        '@path' => $path,
        '@decision' => $decision,
      ]
    );
  }

}
