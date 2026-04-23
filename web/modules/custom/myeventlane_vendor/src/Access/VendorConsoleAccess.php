<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_core\Service\OnboardingManager;
use Drupal\myeventlane_core\VendorConsoleTrust;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Access check for vendor console routes.
 *
 * Only applies to paths under the /vendor URL namespace (i.e. /vendor or
 * /vendor/…). For any other request path, this check returns neutral so
 * /create-event, /user, /my-account, and similar routes are not subject to
 * organiser gating.
 *
 * Allows users with the "access vendor console" permission or the "vendor"
 * organiser role (config: user.role.vendor). The role check matches the gate
 * subscriber so organiser accounts are not denied when permission resolution
 * lags role assignment.
 *
 * In addition, vendor-track onboarding with completed = FALSE is restricted
 * to onboarding and Stripe connect paths, plus Event Studio under
 * /vendor/events/* and (when this access check applies) selected routes so
 * organiser flows are not dead-locked before completion.
 *
 * Note: this checker returns neutral for non-/vendor request paths, so
 * /create-event is not gated here; the create-event allow branch still applies
 * to any future route that attaches this check with a /create-event path.
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

  public function __construct(
    private readonly OnboardingManager $onboardingManager,
    private readonly RequestStack $requestStack,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Checks access for vendor console routes.
   */
  public function access(RouteMatchInterface $route_match, AccountInterface $account): AccessResult {
    $path = $this->getPathForRequest();
    if (!$this->isVendorPathNamespace($path)) {
      $this->logger->notice('Skipped vendor access (non-vendor path) path=@path', [
        '@path' => $path,
      ]);
      return AccessResult::neutral('Non-vendor request path: vendor access check not applicable.')
        ->addCacheContexts(['url.path', 'user.permissions', 'user.roles']);
    }

    // Block anonymous.
    if ($account->isAnonymous()) {
      $this->logDecision($account, $path, 'forbidden_anonymous');
      return AccessResult::forbidden()->addCacheContexts(self::CACHE_CONTEXTS);
    }

    if ($account->hasPermission('administer nodes')) {
      return AccessResult::allowed()
        ->addCacheContexts(self::CACHE_CONTEXTS);
    }

    $uid = (int) $account->id();
    if ($uid <= 0) {
      return AccessResult::forbidden()->addCacheContexts(self::CACHE_CONTEXTS);
    }

    $state = $this->onboardingManager->loadVendorStateByUid($uid);
    if ($state !== NULL && !$this->onboardingManager->isCompleted($state)) {
      if ($this->isAllowedOnboardingPath($path)) {
        return AccessResult::allowed()
          ->addCacheContexts(self::CACHE_CONTEXTS)
          ->addCacheableDependency($state);
      }

      // Allow event creation and Event Studio paths during in-progress onboarding.
      if (
        str_starts_with($path, '/create-event') ||
        str_starts_with($path, '/vendor/events')
      ) {
        $this->logger->notice('MEL: allowing event creation during onboarding uid=@uid', [
          '@uid' => (string) $uid,
        ]);

        return AccessResult::allowed()
          ->addCacheContexts(self::CACHE_CONTEXTS)
          ->addCacheableDependency($state);
      }

      $this->logger->notice('Blocked vendor route (not onboarded) uid=@uid', [
        '@uid' => (string) $uid,
      ]);
      return AccessResult::forbidden()
        ->addCacheContexts(self::CACHE_CONTEXTS)
        ->addCacheableDependency($state);
    }

    if (VendorConsoleTrust::accountIsTrustedForVendorConsole($account)) {
      $decision = $account->hasPermission('access vendor console') ? 'allowed' : 'allowed_vendor_role';
      $this->logDecision($account, $path, $decision);
      return AccessResult::allowed()->addCacheContexts(self::CACHE_CONTEXTS);
    }

    $this->logDecision($account, $path, 'forbidden_no_permission');
    return AccessResult::forbidden()->addCacheContexts(self::CACHE_CONTEXTS);
  }

  /**
   * TRUE for the vendor console path tree only (excludes e.g. /vendors public).
   */
  private function isVendorPathNamespace(string $path): bool {
    return $path === '/vendor' || str_starts_with($path, '/vendor/');
  }

  /**
   * Paths the organiser may use while vendor onboarding is incomplete.
   */
  private function isAllowedOnboardingPath(string $path): bool {
    if ($path === '/vendor/onboard' || str_starts_with($path, '/vendor/onboard/')) {
      return TRUE;
    }
    if ($path === '/vendor/stripe' || str_starts_with($path, '/vendor/stripe/')) {
      return TRUE;
    }
    return FALSE;
  }

  private function getPathForRequest(): string {
    $request = $this->requestStack->getCurrentRequest();
    if ($request !== NULL) {
      return $request->getPathInfo() ?: '/';
    }
    return '/';
  }

  /**
   * Temporarily structured decision logging (keeps host/path context).
   */
  private function logDecision(AccountInterface $account, string $path, string $decision): void {
    $host = 'cli';
    $current = $this->requestStack->getCurrentRequest();
    if ($current !== NULL) {
      $host = $current->getHost();
    }
    $this->logger->notice(
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
