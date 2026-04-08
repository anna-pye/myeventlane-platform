<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Central session keys and cleanup for vendor OAuth state (single place).
 */
final class MelAuthOAuthSession {

  public const KEY_STATE = 'myeventlane_auth.oauth_state';

  public const KEY_STATE_CREATED = 'myeventlane_auth.oauth_state_created';

  public const KEY_REDIRECT_URI = 'myeventlane_auth.oauth_redirect_uri';

  public const KEY_FAIL_COUNT = 'myeventlane_auth.sso_fail_count';

  /**
   * Request-scoped correlation for logs (builder → callback → failure).
   */
  public const KEY_CORRELATION_ID = 'myeventlane_auth.correlation_id';

  /**
   * Max age in seconds for a valid OAuth state (CSRF correlation).
   */
  public const STATE_TTL_SECONDS = 300;

  /**
   * Request time for TTL and token metadata (matches Drupal request stack).
   *
   * Always use this instead of time() when pairing with state created in the
   * login URL builder on the same request cycle.
   */
  public static function requestTime(Request $request): int {
    return (int) $request->server->get('REQUEST_TIME', time());
  }

  /**
   * Resets SSO failure counter (e.g. user navigated away from vendor retry loop).
   */
  public static function resetFailCount(SessionInterface $session): void {
    $session->remove(self::KEY_FAIL_COUNT);
  }

  /**
   * Clears OAuth state keys (single-use step before token exchange).
   */
  public static function clearOAuthState(SessionInterface $session): void {
    $session->remove(self::KEY_STATE);
    $session->remove(self::KEY_STATE_CREATED);
    $session->remove(self::KEY_REDIRECT_URI);
  }

  /**
   * Terminal failure: no stale state, redirect_uri, partial tokens, or correlation.
   */
  public static function clearForFailure(SessionInterface $session): void {
    self::clearOAuthState($session);
    $session->remove(self::KEY_CORRELATION_ID);
  }

  /**
   * After successful login: reset failure counter; ensure no stray OAuth keys.
   *
   * OAuth state is already cleared before token exchange; this is defensive.
   */
  public static function clearForSuccess(SessionInterface $session): void {
    self::clearOAuthState($session);
    $session->remove(self::KEY_FAIL_COUNT);
    $session->remove(self::KEY_CORRELATION_ID);
  }

}
