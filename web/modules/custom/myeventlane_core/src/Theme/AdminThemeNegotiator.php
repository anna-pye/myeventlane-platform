<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Theme;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\Core\Theme\ThemeNegotiatorInterface;

/**
 * Theme negotiator for admin domain.
 *
 * Ensures Gin theme is used on admin domain for admin routes.
 */
final class AdminThemeNegotiator implements ThemeNegotiatorInterface {

  /**
   * Constructs an AdminThemeNegotiator object.
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
    // Only apply on admin domain.
    return $this->domainDetector->isAdminDomain();
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match): ?string {
    // Get the configured admin theme.
    $admin_theme = \Drupal::config('system.theme')->get('admin');

    // If admin theme is set and exists, use it.
    if (!empty($admin_theme)) {
      $theme_handler = \Drupal::service('theme_handler');
      $theme_list = $theme_handler->listInfo();

      if (isset($theme_list[$admin_theme]) && $theme_list[$admin_theme]->status) {
        return $admin_theme;
      }
    }

    // Fallback: return NULL to use default theme negotiation.
    return NULL;
  }

}
