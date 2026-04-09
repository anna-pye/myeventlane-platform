<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Alters routes to resolve path conflict with myeventlane_reporting.
 *
 * Moves myeventlane_reporting.admin.overview to /admin/myeventlane/reports/dashboard
 * so myeventlane_admin_dashboard.reports can use /admin/myeventlane/reports.
 */
final class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[RoutingEvents::ALTER] = ['onAlterRoutes', -20];
    return $events;
  }

  /**
   * Reporting routes that should allow PCC users (not only "view admin reports").
   *
   * @var array<string>
   */
  private const REPORTING_ADMIN_ROUTE_NAMES = [
    'myeventlane_reporting.admin.overview',
    'myeventlane_reporting.admin.vendors',
    'myeventlane_reporting.admin.events',
    'myeventlane_reporting.admin.finance',
  ];

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $route = $collection->get('myeventlane_reporting.admin.overview');
    if ($route !== NULL) {
      $route->setPath('/admin/myeventlane/reports/dashboard');
      $route->setOption('_menu_base_route', 'myeventlane_admin_dashboard.platform_control');
    }
    foreach (['myeventlane_reporting.admin.vendors', 'myeventlane_reporting.admin.events', 'myeventlane_reporting.admin.finance'] as $name) {
      $r = $collection->get($name);
      if ($r !== NULL) {
        $r->setOption('_menu_base_route', 'myeventlane_admin_dashboard.platform_control');
      }
    }

    foreach (self::REPORTING_ADMIN_ROUTE_NAMES as $name) {
      $r = $collection->get($name);
      if ($r === NULL) {
        continue;
      }
      $requirements = $r->getRequirements();
      unset($requirements['_permission']);
      $r->setRequirements($requirements);
      $r->setRequirement(
        '_custom_access',
        '\Drupal\myeventlane_admin_dashboard\Access\PlatformControlReportingAccess::accessAdminReportingConsole'
      );
    }
    foreach (['entity.escalation.collection', 'entity.escalation.canonical', 'entity.escalation.add_form', 'entity.escalation.edit_form'] as $name) {
      $r = $collection->get($name);
      if ($r !== NULL) {
        $r->setOption('_menu_base_route', 'myeventlane_admin_dashboard.platform_control');
      }
    }

    // Own the orders path via myeventlane_admin_dashboard.orders; drop the Views page route
    // so local tasks and menu links never depend on view.vendor_orders.admin_orders existing.
    if ($collection->get('view.vendor_orders.admin_orders') !== NULL) {
      $collection->remove('view.vendor_orders.admin_orders');
    }

    // If the module route is missing (stale router rebuild, partial deploy), register it here
    // so PCC tabs and menu links that reference myeventlane_admin_dashboard.orders do not 500.
    if ($collection->get('myeventlane_admin_dashboard.orders') === NULL) {
      $ordersRoute = new Route(
        '/admin/myeventlane/orders',
        [
          '_controller' => '\Drupal\myeventlane_admin_dashboard\Controller\OrdersController::build',
          '_title' => 'Orders',
        ],
        [
          '_permission' => 'access myeventlane platform control centre',
        ]
      );
      $ordersRoute->setOption('_admin_route', TRUE);
      $ordersRoute->setOption('_menu_base_route', 'myeventlane_admin_dashboard.platform_control');
      $collection->add('myeventlane_admin_dashboard.orders', $ordersRoute);
    }
  }

}
