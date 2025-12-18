<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Theme;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\Core\Theme\ThemeNegotiatorInterface;

/**
 * Theme negotiator for vendor domain.
 *
 * Switches to vendor theme when on vendor domain.
 */
final class VendorThemeNegotiator implements ThemeNegotiatorInterface {

  /**
   * Constructs a VendorThemeNegotiator object.
   *
   * @param \Drupal\myeventlane_core\Service\DomainDetector $domainDetector
   *   The domain detector service.
   */
  public function __construct(
    private readonly DomainDetector $domainDetector,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match): bool {
    // Only apply on vendor domain.
    return $this->domainDetector->isVendorDomain();
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match): ?string {
    // Don't override admin theme (Gin).
    $route = $route_match->getRouteObject();
    if ($route && $route->getOption('_admin_route')) {
      return NULL;
    }

    // Check if vendor theme exists and is enabled.
    $theme_handler = \Drupal::service('theme_handler');
    $theme_list = $theme_handler->listInfo();

    if (isset($theme_list['myeventlane_vendor_theme']) && $theme_list['myeventlane_vendor_theme']->status) {
      return 'myeventlane_vendor_theme';
    }

    // Fallback: return NULL to use default theme.
    return NULL;
  }

}
