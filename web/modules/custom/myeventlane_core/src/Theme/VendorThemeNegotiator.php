<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Theme;

use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;
use Drupal\myeventlane_core\Service\DomainDetector;

/**
 * Theme negotiator for vendor domain.
 *
 * Switches to vendor theme when on vendor domain.
 */
final class VendorThemeNegotiator implements ThemeNegotiatorInterface {

  /**
   * Public vendor routes that must always use the marketplace theme.
   *
   * @var string[]
   */
  private const PUBLIC_VENDOR_ROUTES = [
    'entity.myeventlane_vendor.canonical',
    'myeventlane_vendor.public_list',
    'myeventlane_vendor.organisers',
  ];

  /**
   * Constructs a VendorThemeNegotiator object.
   *
   * @param \Drupal\myeventlane_core\Service\DomainDetector $domainDetector
   *   The domain detector service.
   * @param \Drupal\Core\Extension\ThemeHandlerInterface $themeHandler
   *   The theme handler.
   */
  public function __construct(
    private readonly DomainDetector $domainDetector,
    private readonly ThemeHandlerInterface $themeHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match): bool {
    $route_name = $route_match->getRouteName();
    if ($route_name !== NULL && in_array($route_name, self::PUBLIC_VENDOR_ROUTES, TRUE)) {
      return TRUE;
    }

    return $this->domainDetector->isVendorDomain();
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match): ?string {
    $route_name = $route_match->getRouteName();
    if ($route_name !== NULL && in_array($route_name, self::PUBLIC_VENDOR_ROUTES, TRUE)) {
      return 'myeventlane_theme';
    }

    // Don't override admin theme (Gin).
    $route = $route_match->getRouteObject();
    if ($route && $route->getOption('_admin_route')) {
      return NULL;
    }

    // Check if vendor theme exists and is enabled.
    $theme_list = $this->themeHandler->listInfo();

    if (isset($theme_list['myeventlane_vendor_theme']) && $theme_list['myeventlane_vendor_theme']->status) {
      return 'myeventlane_vendor_theme';
    }

    // Fallback: return NULL to use default theme.
    return NULL;
  }

}
