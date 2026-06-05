<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Service;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\myeventlane_core\Service\EventCategoryUrlService;
use Drupal\taxonomy\TermInterface;

/**
 * Resolves Boost placement keys from the current request route.
 *
 * Placement keys align with myeventlane_boost_stats (e.g. homepage_discover,
 * category_music, search_results).
 */
final class BoostPlacementResolver {

  /**
   * Constructs a BoostPlacementResolver.
   */
  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly ?EventCategoryUrlService $categoryUrlService,
  ) {}

  /**
   * Resolves the placement identifier for the current page request.
   */
  public function resolveCurrentPlacement(): string {
    $routeName = (string) ($this->routeMatch->getRouteName() ?? '');

    if ($routeName === '<front>') {
      return 'homepage_discover';
    }

    if ($routeName === 'mel_search.view') {
      return 'search_results';
    }

    if ($routeName === 'view.upcoming_events.page_category') {
      $argument = $this->routeMatch->getParameter('arg_0');
      if ($this->categoryUrlService !== NULL && ($argument !== NULL && $argument !== '')) {
        $term = $this->categoryUrlService->resolveTerm($argument);
        if ($term instanceof TermInterface) {
          return 'category_' . $this->sanitizePlacementSegment($this->categoryUrlService->getSlug($term));
        }
      }
      if (is_string($argument) && $argument !== '') {
        return 'category_' . $this->sanitizePlacementSegment($argument);
      }
      return 'category_unknown';
    }

    if (str_starts_with($routeName, 'view.front_') || str_contains($routeName, 'homepage')) {
      return 'homepage_discover';
    }

    if (str_starts_with($routeName, 'view.upcoming_events.')) {
      $suffix = substr($routeName, strlen('view.upcoming_events.'));
      $suffix = $this->sanitizePlacementSegment($suffix !== '' ? $suffix : 'listing');
      return 'listing_' . $suffix;
    }

    return 'listing';
  }

  /**
   * Validates a placement key format.
   */
  public function isValidPlacement(string $placement): bool {
    return $placement !== '' && (bool) preg_match('/^[a-z][a-z0-9_]{0,63}$/', $placement);
  }

  /**
   * Normalizes a route or slug segment for placement keys.
   */
  private function sanitizePlacementSegment(string $value): string {
    $normalized = strtolower(trim($value));
    $normalized = preg_replace('/[^a-z0-9_]+/', '_', $normalized) ?? '';
    $normalized = trim($normalized, '_');
    return $normalized !== '' ? $normalized : 'unknown';
  }

}
