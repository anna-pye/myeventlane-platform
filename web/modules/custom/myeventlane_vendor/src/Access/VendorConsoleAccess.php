<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_core\VendorConsoleTrust;

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
   * Cache contexts for onboarding path override (path + role; avoid stale forbid).
   *
   * @var array<string>
   */
  private const ONBOARDING_CACHE_CONTEXTS = [
    'url.path',
    'user.roles',
  ];

  /**
   * Checks access for vendor console routes.
   */
  public static function access(RouteMatchInterface $route_match, AccountInterface $account): AccessResult {
    $request = \Drupal::request();
    $path = $request?->getPathInfo() ?? '';
    $host = $request?->getHost() ?? '';

    // Path-based onboarding bypass: must run before any vendor permission logic.
    // Route name alone is unreliable (subrequests, delegates, altered routes).
    if (str_starts_with($path, '/vendor/onboard')) {
      if ($account->isAnonymous()) {
        self::logDecision($account, $host, $path, 'forbidden_anonymous');
        return AccessResult::forbidden()->addCacheContexts(self::ONBOARDING_CACHE_CONTEXTS);
      }
      \Drupal::logger('mel_vendor_access_debug')->notice(
        'Onboarding access override hit path=@path uid=@uid',
        [
          '@path' => $path,
          '@uid' => (string) $account->id(),
        ]
      );
      return AccessResult::allowed()->addCacheContexts(self::ONBOARDING_CACHE_CONTEXTS);
    }

    // Block anonymous.
    if ($account->isAnonymous()) {
      self::logDecision($account, $host, $path, 'forbidden_anonymous');
      return AccessResult::forbidden()->addCacheContexts(self::CACHE_CONTEXTS);
    }

    if (VendorConsoleTrust::accountIsTrustedForVendorConsole($account)) {
      $decision = $account->hasPermission('access vendor console') ? 'allowed' : 'allowed_vendor_role';
      self::logDecision($account, $host, $path, $decision);
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
