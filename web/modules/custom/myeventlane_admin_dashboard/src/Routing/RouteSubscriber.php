<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Alters routes to resolve path conflict with myeventlane_reporting.
 *
 * Moves myeventlane_reporting.admin.overview to /admin/myeventlane/reports/dashboard
 * so myeventlane_admin_dashboard.reports can use /admin/myeventlane/reports.
 */
final class RouteSubscriber extends RouteSubscriberBase {

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

    $ordersView = $collection->get('view.vendor_orders.admin_orders');
    if ($ordersView !== NULL) {
      $ordersView->setOption('_menu_base_route', 'myeventlane_admin_dashboard.platform_control');
    }
  }

}
