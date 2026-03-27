<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Service;

use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Attaches the shared MEL support UI library and drupalSettings on vendor/admin shells.
 *
 * Skips routes where the main controller already supplies melSupport (e.g. /help hub).
 */
final class SupportShellPageAttachments {

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly RequestStack $requestStack,
    private readonly MelSupportSettingsBuilder $melSupportSettingsBuilder,
  ) {}

  /**
   * Merges library and drupalSettings into page attachments when appropriate.
   *
   * @param array<string, mixed> $attachments
   *   Page attachments render array (altered by reference).
   */
  public function attachIfNeeded(array &$attachments): void {
    if ($this->attachmentsAlreadyHaveMelSupport($attachments)) {
      $this->ensureLibrary($attachments);
      return;
    }

    $route_name = (string) ($this->routeMatch->getRouteName() ?? '');
    $variant = $this->resolveShellVariant($route_name);
    if ($variant === NULL) {
      return;
    }

    $this->ensureLibrary($attachments);
    $payload = $this->melSupportSettingsBuilder->buildDrupalSettings($variant, FALSE);
    $attachments['#attached']['drupalSettings']['melSupport'] = $payload;
  }

  /**
   * @param array<string, mixed> $attachments
   */
  private function attachmentsAlreadyHaveMelSupport(array &$attachments): bool {
    return isset($attachments['#attached']['drupalSettings']['melSupport']);
  }

  /**
   * @param array<string, mixed> $attachments
   */
  private function ensureLibrary(array &$attachments): void {
    $libraries = $attachments['#attached']['library'] ?? [];
    if (!is_array($libraries)) {
      $libraries = [];
    }
    if (!in_array('myeventlane_help_centre/support_components', $libraries, TRUE)) {
      $attachments['#attached']['library'][] = 'myeventlane_help_centre/support_components';
    }
  }

  /**
   * @return 'compact'|'admin'|null
   */
  private function resolveShellVariant(string $route_name): ?string {
    if ($route_name === '') {
      return NULL;
    }

    if (str_starts_with($route_name, 'myeventlane_vendor.console.')) {
      return 'compact';
    }

    if ($this->isStaffAdminSupportRoute($route_name)) {
      return 'admin';
    }

    return NULL;
  }

  private function isStaffAdminSupportRoute(string $route_name): bool {
    if (str_starts_with($route_name, 'entity.escalation.')) {
      return TRUE;
    }
    if (str_starts_with($route_name, 'myeventlane_support_console')) {
      return TRUE;
    }

    $route = $this->routeMatch->getRouteObject();
    if (!$route || !(bool) $route->getOption('_admin_route')) {
      return FALSE;
    }

    $path = $this->requestStack->getCurrentRequest()?->getPathInfo() ?? '';
    if (str_starts_with($path, '/admin/myeventlane')) {
      return TRUE;
    }
    if (str_starts_with($path, '/admin/config/myeventlane')) {
      return TRUE;
    }
    if (str_starts_with($path, '/admin/mel/')) {
      return TRUE;
    }

    return FALSE;
  }

}
