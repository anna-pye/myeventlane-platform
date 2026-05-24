<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Theme;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;

/**
 * Selects vendor theme for vendor routes.
 */
final class VendorThemeNegotiator implements ThemeNegotiatorInterface {

  /**
   * Public-site routes that use the myeventlane_vendor.* name but must stay on
   * the default MEL theme (trust-first public shell, not vendor console chrome).
   *
   * @var list<string>
   */
  private const PUBLIC_DEFAULT_THEME_ROUTES = [
    'myeventlane_event_studio.create',
    'myeventlane_vendor.public_list',
    'myeventlane_vendor.organisers',
    'entity.myeventlane_vendor.canonical',
  ];

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match): bool {
    $route_name = $route_match->getRouteName();

    if (!$route_name) {
      return FALSE;
    }

    if (in_array($route_name, self::PUBLIC_DEFAULT_THEME_ROUTES, TRUE)) {
      return FALSE;
    }

    // Apply to all vendor routes.
    if (str_starts_with($route_name, 'myeventlane_vendor.')) {
      return TRUE;
    }

    // Vendor console lives under /vendor/* — not the public /vendors directory.
    $request = \Drupal::request();
    $path = $request->getPathInfo();
    if (str_starts_with($path, '/vendors')) {
      return FALSE;
    }

    if (preg_match('#^/vendor(/|$)#', $path)) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match): string {
    return 'myeventlane_vendor_theme';
  }

}