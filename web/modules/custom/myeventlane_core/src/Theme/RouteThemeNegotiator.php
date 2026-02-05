<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Theme;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;

/**
 * Theme negotiator for route options._theme.
 *
 * Applies the theme specified in route options when present.
 * Used for vendor onboarding routes on the public domain, where
 * VendorThemeNegotiator does not apply (domain-based).
 */
final class RouteThemeNegotiator implements ThemeNegotiatorInterface {

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match): bool {
    $route = $route_match->getRouteObject();
    return $route !== NULL && $route->hasOption('_theme');
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match): ?string {
    $route = $route_match->getRouteObject();
    if ($route === NULL) {
      return NULL;
    }
    $theme = $route->getOption('_theme');
    return is_string($theme) && $theme !== '' ? $theme : NULL;
  }

}
