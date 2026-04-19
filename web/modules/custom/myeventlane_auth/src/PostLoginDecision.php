<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth;

/**
 * Stable machine identifiers for post-login routing audit logs and branches.
 *
 * Used by PostLoginRouter as the single authority for destination policy.
 */
final class PostLoginDecision {

  public const ANONYMOUS_OR_INVALID_UID = 'anonymous_or_invalid_uid';

  public const FALLBACK_FRONT_INVALID_ACCOUNT = 'fallback_front_invalid_account';

  public const INTENT_CREATE_EVENT = 'intent_create_event';

  /**
   * Password reset / one-time login: hand off to user edit (preserve pass-reset-token).
   */
  public const PASSWORD_RESET_CONTINUE_USER_EDIT = 'password_reset_continue_user_edit';

  public const NO_VENDOR_COMPLETED_ORDERS_CUSTOMER_DASHBOARD = 'no_vendor_completed_orders_customer_dashboard';

  public const NO_VENDOR_EVENTS_LISTING = 'no_vendor_events_listing';

  public const NO_VENDOR_USER_CANONICAL_FALLBACK = 'no_vendor_user_canonical_fallback';

  public const VENDOR_ONBOARDING_INCOMPLETE = 'vendor_onboarding_incomplete';

  public const VENDOR_COMPLETE_DASHBOARD = 'vendor_complete_dashboard';

  /**
   * Session-staged redirect: could not build absolute hub URL (infrastructure).
   */
  public const SESSION_HUB_URL_BUILD_FAILED = 'session_hub_url_build_failed';

  /**
   * PostAuthRedirectResolver: anonymous account passed to hub URL builder.
   */
  public const BUILD_POST_LOGIN_HUB_ANONYMOUS = 'build_post_login_hub_anonymous_fallback';

  /**
   * PostAuthRedirectResolver: anonymous account passed to resolveRedirectUrl.
   */
  public const RESOLVE_REDIRECT_URL_ANONYMOUS = 'resolve_redirect_url_anonymous_fallback';

  /**
   * Log placeholder when no named route applies.
   */
  public const ROUTE_LOG_NA = 'n/a';

}
