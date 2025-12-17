<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin\Theme;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;

/**
 * Theme negotiator for MyEventLane Admin theme.
 *
 * Activates the admin theme for:
 * - Vendor routes (/vendor/*)
 * - Dashboard routes (/dashboard/*, /vendor/dashboard)
 * - Admin routes (/admin/myeventlane/*, /admin/structure/myeventlane/*)
 * - Node add/edit forms (/node/add/*, /node/*/edit)
 * - MyEventLane config routes (/admin/config/myeventlane/*)
 */
final class MyEventLaneAdminThemeNegotiator implements ThemeNegotiatorInterface {

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match): bool {
    $route_name = $route_match->getRouteName();
    $path = $route_match->getRouteObject()?->getPath() ?? '';
    
    // Never apply to anonymous users.
    $current_user = \Drupal::currentUser();
    if ($current_user->isAnonymous()) {
      return FALSE;
    }
    
    // IMPORTANT: Don't apply on vendor domain - let VendorThemeNegotiator handle it.
    $domain_detector = \Drupal::service('myeventlane_core.domain_detector');
    if ($domain_detector->isVendorDomain()) {
      return FALSE;
    }
    
    // Check if user has admin or vendor role.
    $is_admin = $current_user->hasPermission('administer site configuration') ||
                $current_user->hasPermission('administer nodes') ||
                $current_user->hasPermission('access vendor dashboard');
    
    if (!$is_admin) {
      return FALSE;
    }
    
    // Apply to vendor routes (but only on main domain, not vendor domain).
    if (strpos($path, '/vendor/') === 0 || 
        strpos($path, '/vendor') === 0 ||
        $route_name === 'myeventlane_vendor.public_list' ||
        strpos($route_name, 'myeventlane_vendor.') === 0) {
      // Skip public vendor canonical routes.
      if ($route_name === 'entity.myeventlane_vendor.canonical') {
        return FALSE;
      }
      return TRUE;
    }
    
    // Apply to dashboard routes.
    if (strpos($path, '/dashboard') === 0 ||
        strpos($route_name, 'myeventlane_dashboard.') === 0) {
      return TRUE;
    }
    
    // Apply to admin MyEventLane routes.
    if (strpos($path, '/admin/myeventlane') === 0 ||
        strpos($path, '/admin/structure/myeventlane') === 0 ||
        strpos($path, '/admin/config/myeventlane') === 0) {
      return TRUE;
    }
    
    // Apply to node add/edit forms.
    if (strpos($path, '/node/add/') === 0 ||
        preg_match('#^/node/\d+/edit$#', $path) ||
        strpos($route_name, 'node.add') === 0 ||
        strpos($route_name, 'node.edit') === 0) {
      return TRUE;
    }
    
    // Apply to create event gateway.
    if ($route_name === 'myeventlane_vendor.create_event_gateway' ||
        $path === '/create-event') {
      return TRUE;
    }
    
    // Apply to vendor onboarding.
    if ($route_name === 'myeventlane_vendor.onboard' ||
        $path === '/vendor/onboard') {
      return TRUE;
    }
    
    // Apply to my-categories.
    if ($route_name === 'myeventlane_core.my_categories' ||
        $path === '/my-categories') {
      return TRUE;
    }
    
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match): ?string {
    return 'myeventlane_admin';
  }

}


















