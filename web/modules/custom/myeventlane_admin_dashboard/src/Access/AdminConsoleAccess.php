<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;

/**
 * Access check for Platform Control Centre routes that must not depend on host.
 *
 * Only applies to paths under /admin. For other request paths, returns neutral
 * so the check does not deny /create-event, /vendor, or unrelated routes.
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
    $stackRequest = \Drupal::requestStack()->getCurrentRequest();
    $path = $stackRequest?->getPathInfo() ?: '/';

    if (!str_starts_with($path, '/admin')) {
      \Drupal::logger('mel_admin_access_debug')->notice('Skipped admin access (non-admin path) path=@path', [
        '@path' => $path,
      ]);
      return AccessResult::neutral('Non-admin request path: admin access check not applicable.')
        ->addCacheContexts(['url.path', 'user.permissions', 'user.roles']);
    }

    if ($account->isAnonymous()) {
      self::logDecision($account, $stackRequest?->getHost() ?? '', $path, 'forbidden_anonymous');
      return AccessResult::forbidden()->addCacheContexts(self::CACHE_CONTEXTS);
    }

    if ($account->hasPermission('access myeventlane platform control centre')) {
      self::logDecision($account, $stackRequest?->getHost() ?? '', $path, 'allowed');
      return AccessResult::allowed()->addCacheContexts(self::CACHE_CONTEXTS);
    }

    self::logDecision($account, $stackRequest?->getHost() ?? '', $path, 'forbidden_no_permission');
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
