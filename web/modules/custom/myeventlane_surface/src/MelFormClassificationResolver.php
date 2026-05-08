<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\myeventlane_core\MelSurfaceId;

use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Derives MelFormClassification from surface + route (single source, no scatter).
 */
final class MelFormClassificationResolver {

  public function __construct(
    private readonly SurfaceResolver $surfaceResolver,
    private readonly RouteMatchInterface $routeMatch,
    private readonly RequestStack $requestStack,
  ) {}

  public function resolve(): MelFormClassification {
    $surface = $this->surfaceResolver->resolve();
    if ($surface === MelSurfaceId::Staff) {
      return MelFormClassification::Admin;
    }

    $route_name = $this->routeMatch->getRouteName() ?? '';
    $path = $this->requestStack->getCurrentRequest()?->getPathInfo() ?? '';

    if ($this->isCheckoutContext($route_name, $path)) {
      return MelFormClassification::Checkout;
    }

    if ($this->isSupportContext($route_name, $path)) {
      return MelFormClassification::Support;
    }

    return match ($surface) {
      MelSurfaceId::Auth => MelFormClassification::Auth,
      MelSurfaceId::Vendor => MelFormClassification::Vendor,
      MelSurfaceId::Customer => MelFormClassification::Customer,
      MelSurfaceId::Public => MelFormClassification::Customer,
    };
  }

  /**
   * Cart, Commerce checkout/payment/order flows, and on-site ticket booking.
   */
  private function isCheckoutContext(string $route_name, string $path): bool {
    if (str_starts_with($path, '/cart') || str_starts_with($path, '/checkout')) {
      return TRUE;
    }

    $commerce_prefixes = [
      'commerce_cart.',
      'commerce_checkout.',
      'commerce_payment.',
      'commerce_order.',
    ];
    foreach ($commerce_prefixes as $prefix) {
      if (str_starts_with($route_name, $prefix)) {
        return TRUE;
      }
    }

    // Paid / RSVP booking flows (Commerce-backed; presentation matches checkout trust bar).
    if ($route_name === 'myeventlane_commerce.event_book') {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Customer + vendor escalation/support portals (not staff admin tooling).
   */
  private function isSupportContext(string $route_name, string $path): bool {
    if (str_starts_with($path, '/my/support') || str_starts_with($path, '/vendor/support')) {
      return TRUE;
    }

    return str_starts_with($route_name, 'myeventlane_escalations_portal.customer_')
      || str_starts_with($route_name, 'myeventlane_escalations_portal.vendor_');
  }

}
