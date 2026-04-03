<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Theme;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;

/**
 * Selects vendor theme for vendor routes.
 */
final class VendorThemeNegotiator implements ThemeNegotiatorInterface {

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match): bool {
    $route_name = $route_match->getRouteName();

    if (!$route_name) {
      return FALSE;
    }

    // Apply to all vendor routes.
    if (str_starts_with($route_name, 'myeventlane_vendor.')) {
      return TRUE;
    }

    // Fallback for path-based routes.
    $request = \Drupal::request();
    $path = $request->getPathInfo();

    if (str_starts_with($path, '/vendor')) {
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