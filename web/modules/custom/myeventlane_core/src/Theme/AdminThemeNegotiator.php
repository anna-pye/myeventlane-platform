<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Theme;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;
use Drupal\myeventlane_core\Service\DomainDetector;

/**
 * Theme negotiator for admin domain.
 *
 * Ensures Gin (or configured admin theme) is used on admin domain for admin
 * routes. Platform Control Centre paths use myeventlane_admin when enabled so
 * MEL layout (page.html.twig) renders consistently on the admin hostname.
 */
final class AdminThemeNegotiator implements ThemeNegotiatorInterface {

  public function __construct(
    private readonly DomainDetector $domainDetector,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ThemeHandlerInterface $themeHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match): bool {
    // Only apply on the admin domain, and only for admin routes.
    //
    // IMPORTANT: Do not force the admin theme for public-facing routes, even if
    // they are accessed via the admin domain. This prevents public pages (e.g.
    // booking/cart/checkout) from inheriting admin styling and missing frontend
    // asset attachments.
    if (!$this->domainDetector->isAdminDomain()) {
      return FALSE;
    }

    $route = $route_match->getRouteObject();
    if ($route && $route->getOption('_admin_route')) {
      return TRUE;
    }

    $path = $route?->getPath() ?? '';
    return $path !== '' && str_starts_with($path, '/admin');
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match): ?string {
    $route = $route_match->getRouteObject();
    $path = $route?->getPath() ?? '';
    if ($path !== '' && str_starts_with($path, '/admin/myeventlane')) {
      $theme_list = $this->themeHandler->listInfo();
      if (isset($theme_list['myeventlane_admin']) && $theme_list['myeventlane_admin']->status) {
        return 'myeventlane_admin';
      }
    }

    $admin_theme = $this->configFactory->get('system.theme')->get('admin');
    if (!empty($admin_theme)) {
      $theme_list = $this->themeHandler->listInfo();
      if (isset($theme_list[$admin_theme]) && $theme_list[$admin_theme]->status) {
        return $admin_theme;
      }
    }

    return NULL;
  }

}
