<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Single source of truth for public customer hub routes and paths.
 *
 * Used by SurfaceResolver (customer surface) and myeventlane_account
 * (page--account shell suggestions). Keep lists aligned here only.
 */
final class MelCustomerRouteCatalog {

  /**
   * Routes that render inside the customer account page shell (page--account).
   *
   * @var list<string>
   */
  public const ACCOUNT_PAGE_SHELL_ROUTE_NAMES = [
    'myeventlane_account.dashboard',
    'myeventlane_account.past_events',
    'myeventlane_account.settings',
    'myeventlane_checkout_flow.my_tickets',
    'myeventlane_checkout_flow.order_detail',
    'myeventlane_checkout_flow.order_tax_invoice_pdf',
    'myeventlane_dashboard.customer',
    'myeventlane_rsvp.user_list',
    'myeventlane_notifications.inbox',
    'myeventlane_notifications.preferences',
    'myeventlane_core.my_categories',
    'view.mel_saved_events.page_1',
    'myeventlane_account.followed_organisers',
  ];

  /**
   * Path prefixes for customer surface resolution.
   *
   * @var list<string>
   */
  public const CUSTOMER_PATH_PREFIXES = [
    '/my-account',
    '/my-settings',
    '/my-past-events',
    '/my-tickets',
    '/my-events',
    '/my-profile',
    '/my-saved-events',
    '/my-notifications',
    '/my-categories',
    '/my-organisers',
  ];

  /**
   * Whether the route should use the page--account layout.
   */
  public static function isAccountPageShellRoute(?string $route_name): bool {
    if ($route_name === NULL || $route_name === '') {
      return FALSE;
    }
    return in_array($route_name, self::ACCOUNT_PAGE_SHELL_ROUTE_NAMES, TRUE);
  }

  /**
   * Whether the request path is inside the reserved customer hub prefix space.
   */
  public static function pathMatchesCustomerPrefix(string $path): bool {
    foreach (self::CUSTOMER_PATH_PREFIXES as $prefix) {
      if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * RSVP list lives under core user path; treat as customer hub shell.
   */
  public static function isUserRsvpListPath(string $path): bool {
    return (bool) preg_match('#^/user/\d+/rsvps$#', $path);
  }

  /**
   * Whether the active route is the public customer (hub) surface.
   */
  public static function isCustomerSurface(?string $route_name, string $path): bool {
    if (self::pathMatchesCustomerPrefix($path)) {
      return TRUE;
    }
    if (self::isUserRsvpListPath($path)) {
      return TRUE;
    }
    if ($route_name === NULL || $route_name === '') {
      return FALSE;
    }
    if (str_starts_with($route_name, 'myeventlane_account.')) {
      return TRUE;
    }
    if (in_array($route_name, self::ACCOUNT_PAGE_SHELL_ROUTE_NAMES, TRUE)) {
      return TRUE;
    }
    // Calendar bundle download: customer-owned, not a page shell.
    return $route_name === 'myeventlane_rsvp.ics_bundle';
  }

}
