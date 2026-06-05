<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Service;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\myeventlane_core\Service\EventCategoryUrlService;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\RouterInterface;

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
    private readonly RouterInterface $router,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Resolves the placement identifier for the current page request.
   */
  public function resolveCurrentPlacement(): string {
    return $this->resolvePlacementFromRoute(
      (string) ($this->routeMatch->getRouteName() ?? ''),
      $this->extractRouteParameters($this->routeMatch),
    );
  }

  /**
   * Resolves placement from the page that initiated an impression beacon.
   */
  public function resolvePlacementFromReferer(string $referer): ?string {
    $refererPath = parse_url($referer, PHP_URL_PATH);
    if (!is_string($refererPath) || $refererPath === '') {
      return NULL;
    }

    $currentRequest = $this->requestStack->getCurrentRequest();
    if ($currentRequest === NULL) {
      return NULL;
    }

    $basePath = $currentRequest->getBasePath();
    if ($basePath !== '' && str_starts_with($refererPath, $basePath)) {
      $refererPath = substr($refererPath, strlen($basePath)) ?: '/';
    }

    try {
      $matchRequest = Request::create($refererPath);
      $matchRequest->headers->set('HOST', $currentRequest->getHost());
      $attributes = $this->router->matchRequest($matchRequest);
    }
    catch (ResourceNotFoundException | MethodNotAllowedException) {
      return NULL;
    }

    $routeName = (string) ($attributes['_route'] ?? '');
    unset(
      $attributes['_route'],
      $attributes['_controller'],
      $attributes['_title'],
      $attributes['_form'],
      $attributes['_access_checks'],
    );

    return $this->resolvePlacementFromRoute($routeName, $attributes);
  }

  /**
   * Resolves a placement key from a route name and its parameters.
   */
  public function resolvePlacementFromRoute(string $routeName, array $parameters = []): string {
    if ($routeName === '<front>') {
      return 'homepage_discover';
    }

    if ($routeName === 'mel_search.view') {
      return 'search_results';
    }

    if ($routeName === 'view.upcoming_events.page_category') {
      $argument = $parameters['arg_0'] ?? NULL;
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
   * @return array<string, mixed>
   *   Raw route parameters keyed for placement resolution.
   */
  private function extractRouteParameters(RouteMatchInterface $routeMatch): array {
    $parameters = [];
    foreach ($routeMatch->getParameters()->keys() as $name) {
      $parameters[$name] = $routeMatch->getRawParameter($name);
    }
    return $parameters;
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
