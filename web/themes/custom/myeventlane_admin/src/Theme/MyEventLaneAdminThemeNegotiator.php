<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin\Theme;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;
use Drupal\myeventlane_core\Service\DomainDetector;

/**
 * Theme negotiator for MyEventLane Admin theme.
 *
 * Activates the admin theme only for admin/editor UX:
 * - Admin routes (admin context)
 * - Node add/edit forms
 *
 * Never applies on the vendor domain.
 */
final class MyEventLaneAdminThemeNegotiator implements ThemeNegotiatorInterface {

  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly DomainDetector $domainDetector,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match): bool {
    $route_name = $route_match->getRouteName();
    $path = $route_match->getRouteObject()?->getPath() ?? '';

    // Never apply to anonymous users.
    if ($this->currentUser->isAnonymous()) {
      return FALSE;
    }

    // Never apply on vendor domain (vendor theme owns that UX).
    if ($this->domainDetector->isVendorDomain()) {
      return FALSE;
    }

    // Admin context routes.
    $route = $route_match->getRouteObject();
    $is_admin_route = $route ? (bool) $route->getOption('_admin_route') : FALSE;
    if ($is_admin_route || str_starts_with($path, '/admin')) {
      return TRUE;
    }

    // Node add/edit forms.
    if (str_starts_with($path, '/node/add/') || preg_match('#^/node/\d+/edit$#', $path)) {
      return TRUE;
    }

    // Fallback: known node form route names.
    if (str_starts_with($route_name, 'node.add') || str_starts_with($route_name, 'node.edit')) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match): ?string {
    return 'myeventlane_admin';
  }

}


















