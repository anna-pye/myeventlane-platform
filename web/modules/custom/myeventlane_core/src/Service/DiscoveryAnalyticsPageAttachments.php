<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;

/**
 * Attaches public event-click analytics to discovery listing surfaces.
 *
 * Reuses the existing vendor_public library and melPublicAnalytics settings.
 * CSRF tokens are fetched client-side so anonymous page cache stays safe.
 */
final class DiscoveryAnalyticsPageAttachments {

  /**
   * View routes that render public event discovery cards.
   *
   * @var list<string>
   */
  private const VIEW_ROUTE_PREFIXES = [
    'view.upcoming_events.',
    'view.front_featured_events.',
    'view.front_recommended_events.',
    'view.featured_events.',
    'view.mel_home_events.',
    'view.front_discover_events.',
  ];

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly PathMatcherInterface $pathMatcher,
  ) {}

  /**
   * Merges analytics library and drupalSettings when on a discovery surface.
   *
   * @param array<string, mixed> $attachments
   *   Page attachments render array (altered by reference).
   */
  public function attachIfNeeded(array &$attachments): void {
    if (!$this->isDiscoverySurface()) {
      return;
    }

    $this->ensureLibrary($attachments);

    $existing = $attachments['#attached']['drupalSettings']['melPublicAnalytics'] ?? NULL;
    if (is_array($existing) && !empty($existing['eventClickUrl'])) {
      return;
    }

    $attachments['#attached']['drupalSettings']['melPublicAnalytics'] = [
      'eventClickUrl' => Url::fromRoute('myeventlane_core.analytics_event_click')->toString(),
    ];
  }

  /**
   * Whether the current route is a public event discovery surface.
   */
  private function isDiscoverySurface(): bool {
    if ($this->pathMatcher->isFrontPage()) {
      return TRUE;
    }

    $route_name = (string) ($this->routeMatch->getRouteName() ?? '');
    if ($route_name === '') {
      return FALSE;
    }

    if ($route_name === 'mel_search.view') {
      return TRUE;
    }

    foreach (self::VIEW_ROUTE_PREFIXES as $prefix) {
      if (str_starts_with($route_name, $prefix)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * @param array<string, mixed> $attachments
   */
  private function ensureLibrary(array &$attachments): void {
    $libraries = $attachments['#attached']['library'] ?? [];
    if (!is_array($libraries)) {
      $libraries = [];
    }
    if (!in_array('myeventlane_core/vendor_public', $libraries, TRUE)) {
      $attachments['#attached']['library'][] = 'myeventlane_core/vendor_public';
    }
  }

}
