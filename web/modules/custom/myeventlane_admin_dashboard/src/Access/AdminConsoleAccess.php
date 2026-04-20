<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;

/**
 * Access check for Platform Control Centre routes that must not depend on host.
 *
 * Mirrors vendor console gating: authenticated users with the PCC permission only.
 */
final class AdminConsoleAccess {

  /**
   * @var array<string>
   */
  private const CACHE_CONTEXTS = [
    'user.permissions',
    'user.roles',
    'session',
  ];

  /**
   * Access for routes requiring "access myeventlane platform control centre".
   */
  public static function access(AccountInterface $account): AccessResult {
    $request = \Drupal::request();
    $path = $request->getPathInfo();

    // Allow organiser onboarding routes to bypass platform-control-centre gate
    // (same path can be evaluated during subrequests / cross-domain checks).
    if (str_contains($path, '/vendor/onboard')) {
      return $account->isAuthenticated()
        ? AccessResult::allowed()->addCacheContexts(['url.path', 'user.roles'])
        : AccessResult::forbidden();
    }

    $stackRequest = \Drupal::requestStack()->getCurrentRequest();
    $host = $stackRequest?->getHost() ?? '';
    $path = $stackRequest?->getPathInfo() ?? '';

    if ($account->isAnonymous()) {
      self::logDecision($account, $host, $path, 'forbidden_anonymous');
      return AccessResult::forbidden()->addCacheContexts(self::CACHE_CONTEXTS);
    }

    if ($account->hasPermission('access myeventlane platform control centre')) {
      self::logDecision($account, $host, $path, 'allowed');
      return AccessResult::allowed()->addCacheContexts(self::CACHE_CONTEXTS);
    }

    self::logDecision($account, $host, $path, 'forbidden_no_permission');
    return AccessResult::forbidden()->addCacheContexts(self::CACHE_CONTEXTS);
  }

  /**
   * Temporary structured logging for cross-subdomain auth validation.
   */
  private static function logDecision(AccountInterface $account, string $host, string $path, string $decision): void {
    \Drupal::logger('mel_admin_access_debug')->notice('AdminConsoleAccess uid=@uid host=@host path=@path decision=@decision', [
      '@uid' => (string) $account->id(),
      '@host' => $host,
      '@path' => $path,
      '@decision' => $decision,
    ]);
  }

}
