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
use Symfony\Component\Routing\Route as SymfonyRoute;

/**
 * Access check for vendor console routes.
 *
 * Decisions on namespace, Stripe allow-list, and onboarding are based on the
 * path of the route under access check (the access target), not the browser's
 * current request path. That way AccessManager::checkNamedRoute() — used when
 * building menu trees — agrees with a normal page request to the same path.
 * Otherwise a menu link to /vendor/… could be treated as a non-vendor "skip"
 * while the user is viewing /user, /, etc.
 *
 * Routes whose path is not in the vendor console namespace return neutral so
 * /create-event, /user, /api/…, and similar routes that attach this check are
 * not subject to organiser gating here (those route paths are not /vendor/…
 * and keep the early neutral return).
 *
 * Allows users with the "access vendor console" permission or the "vendor"
 * organiser role (config: user.role.vendor). The role check matches the gate
 * subscriber so organiser accounts are not denied when permission resolution
 * lags role assignment.
 *
 * The vendor dashboard route (myeventlane_vendor.console.dashboard) requires
 * the "access vendor dashboard" permission (after onboarding) so menu links
 * and direct URLs stay aligned with the same check.
 *
 * In addition, vendor-track onboarding with completed = FALSE is restricted
 * to onboarding and Stripe connect paths, plus Event Studio under
 * /vendor/events/* and (when this access check applies) selected routes so
 * organiser flows are not dead-locked before completion.
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
    $path = $this->getPathForAccessTarget($route_match);
    if (str_starts_with($path, '/stripe/')) {
      $this->logger->debug('MEL: forcing allow for Stripe route uid=@uid', [
        '@uid' => (string) $account->id(),
      ]);
      return AccessResult::allowed()
        ->addCacheContexts(self::CACHE_CONTEXTS);
    }
    if (!$this->isVendorPathNamespace($path)) {
      $this->logger->debug('Skipped vendor access (non-vendor path) path=@path', [
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
        $this->logger->debug('MEL: allowing event creation during onboarding uid=@uid', [
          '@uid' => (string) $uid,
        ]);

        return AccessResult::allowed()
          ->addCacheContexts(self::CACHE_CONTEXTS)
          ->addCacheableDependency($state);
      }

      $this->logger->warning('Blocked vendor route (not onboarded) uid=@uid', [
        '@uid' => (string) $uid,
      ]);
      return AccessResult::forbidden()
        ->addCacheContexts(self::CACHE_CONTEXTS)
        ->addCacheableDependency($state);
    }

    if ($route_match->getRouteName() === 'myeventlane_vendor.console.dashboard') {
      if (!$account->hasPermission('access vendor dashboard')) {
        $this->logDecision($account, $path, 'forbidden_no_vendor_dashboard');
        return AccessResult::forbidden()
          ->addCacheContexts(self::CACHE_CONTEXTS);
      }
      $this->logDecision($account, $path, 'allowed_vendor_dashboard');
      return AccessResult::allowed()
        ->addCacheContexts(self::CACHE_CONTEXTS);
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
    if ($path === '/vendor' || str_starts_with($path, '/vendor/')) {
      return TRUE;
    }
    if (str_starts_with($path, '/stripe/')) {
      return TRUE;
    }
    return FALSE;
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
    if (str_starts_with($path, '/stripe/')) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Returns the path of the route being access-checked.
   */
  private function getPathForAccessTarget(RouteMatchInterface $route_match): string {
    $route = $route_match->getRouteObject();
    if ($route instanceof SymfonyRoute) {
      $pattern = (string) $route->getPath();
      if ($pattern !== '' && $pattern[0] !== '/') {
        $pattern = '/' . $pattern;
      }
      return $pattern === '' ? '/' : $pattern;
    }
    return $this->getPathForRequest();
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
    // Routine allow/deny — debug only (this runs very often via menu + route checks).
    $this->logger->debug(
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
