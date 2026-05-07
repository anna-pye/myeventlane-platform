<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Cross-cutting access helpers for surfaces (checkout sensitivity, staff detection).
 */
final class SurfaceAccessHelper {

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Whether the active HTML request should use commerce/checkout-safe chrome.
   */
  public function isCheckoutSensitiveRequest(): bool {
    $route_name = $this->routeMatch->getRouteName() ?? '';
    if ($route_name !== '' && str_starts_with($route_name, 'commerce_checkout')) {
      return TRUE;
    }

    $path = $this->requestStack->getCurrentRequest()?->getPathInfo() ?? '';
    return str_starts_with($path, '/cart')
      || str_starts_with($path, '/checkout');
  }

}
