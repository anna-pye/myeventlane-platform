<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Guards KernelEvents::REQUEST subscribers from breaking core auth form flows.
 *
 * Use shouldBypassPostRequests() for subscribers that must still run GET on
 * /user/login (e.g. vendor host → public login redirect). Use
 * shouldBypassAuthAccountRoutes() everywhere else so POST /user/login and
 * password/reset flows are never intercepted.
 */
final class MelKernelAuthRouteSilencer {

  /**
   * Skips non-GET requests (e.g. login POST) without blocking GET /user/login.
   */
  public static function shouldBypassPostRequests(Request $request): bool {
    return $request->isMethod('POST');
  }

  /**
   * Skips any request whose path is a core user auth route.
   *
   * Do not use for subscribers whose job is to handle GET /user/login on a
   * specific host (e.g. PublicVendorLoginConflictSubscriber).
   */
  public static function shouldBypassAuthAccountRoutes(Request $request): bool {
    $path = $request->getPathInfo();
    return str_starts_with($path, '/user/login')
      || str_starts_with($path, '/user/logout')
      || str_starts_with($path, '/user/password')
      || str_starts_with($path, '/user/reset');
  }

}
