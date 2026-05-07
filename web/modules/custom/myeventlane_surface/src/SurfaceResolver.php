<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves the active MEL surface from the current route/request.
 */
final class SurfaceResolver {

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly RequestStack $requestStack,
  ) {}

  public function resolve(): MelSurfaceId {
    $route = $this->routeMatch->getRouteObject();
    $route_name = $this->routeMatch->getRouteName() ?? '';
    $path = $this->requestStack->getCurrentRequest()?->getPathInfo() ?? '';

    if ($route && $route->getOption('_admin_route')) {
      return MelSurfaceId::Staff;
    }

    if (str_starts_with($path, '/admin')) {
      return MelSurfaceId::Staff;
    }

    if (str_starts_with($path, '/vendor')) {
      return MelSurfaceId::Vendor;
    }

    if ($this->isAuthRoute($route_name, $path)) {
      return MelSurfaceId::Auth;
    }

    if ($this->isCustomerRoute($route_name, $path)) {
      return MelSurfaceId::Customer;
    }

    return MelSurfaceId::Public;
  }

  /**
   * Twig / debugging helper: machine name of active surface.
   */
  public function resolveMachineName(): string {
    return $this->resolve()->value;
  }

  private function isAuthRoute(string $route_name, string $path): bool {
    return match ($route_name) {
      'user.login',
      'user.register',
      'user.pass',
      'user.reset',
      'user.reset.login',
      // Future MFA / OAuth routes should be registered here once added.
      => TRUE,
      default => str_starts_with($path, '/user/login')
        || str_starts_with($path, '/user/register')
        || str_starts_with($path, '/user/password')
        || str_starts_with($path, '/user/reset'),
    };
  }

  private function isCustomerRoute(string $route_name, string $path): bool {
    if (str_starts_with($route_name, 'myeventlane_account.')) {
      return TRUE;
    }

    $customer_routes = [
      'myeventlane_checkout_flow.my_tickets',
      'myeventlane_checkout_flow.order_detail',
      'myeventlane_checkout_flow.order_tax_invoice_pdf',
      'myeventlane_dashboard.customer',
      'myeventlane_rsvp.ics_bundle',
      'myeventlane_rsvp.user_list',
    ];

    if (in_array($route_name, $customer_routes, TRUE)) {
      return TRUE;
    }

    return str_starts_with($path, '/my-account')
      || str_starts_with($path, '/my-settings')
      || str_starts_with($path, '/my-past-events')
      || str_starts_with($path, '/my-tickets')
      || str_starts_with($path, '/my-events')
      || str_starts_with($path, '/my-profile');
  }

}
