<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Plugin\Block;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin sidebar contextual navigation for Platform Control Centre.
 *
 * @Block(
 *   id = "mel_admin_sidebar_nav",
 *   admin_label = @Translation("MEL Admin Sidebar Navigation"),
 *   category = @Translation("MyEventLane"),
 * )
 */
final class MelAdminSidebarNavBlock extends BlockBase implements ContainerFactoryPluginInterface {

  private const NAV_ITEMS = [
    'myeventlane_admin_dashboard.platform_control' => 'Overview',
    'myeventlane_admin_dashboard.vendors' => 'Vendors',
    'myeventlane_admin_dashboard.orders' => 'Orders',
    'myeventlane_reporting.admin.events' => 'Events',
    'entity.myeventlane_venue.collection' => 'Venues',
    'myeventlane_reporting.admin.finance' => 'Finance',
    'myeventlane_admin_dashboard.payouts' => 'Payouts',
    'myeventlane_reporting.admin.overview' => 'Reports',
    'entity.escalation.collection' => 'Escalations',
    'myeventlane_support_console.dashboard' => 'Support',
    'myeventlane_admin_dashboard.platform_studio' => 'Platform',
  ];

  private const REPORTING_OVERVIEW_ROUTES = [
    'myeventlane_reporting.admin.overview',
    'myeventlane_reporting.admin.vendors',
    'myeventlane_reporting.admin.events',
    'myeventlane_reporting.admin.finance',
  ];

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected RouteMatchInterface $routeMatch,
    protected AccountInterface $currentUser,
    protected AccessManagerInterface $accessManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match'),
      $container->get('current_user'),
      $container->get('access_manager'),
    );
  }

  public function build(): array {
    $currentRoute = (string) $this->routeMatch->getRouteName();
    $links = [];
    $reportingContext = in_array($currentRoute, self::REPORTING_OVERVIEW_ROUTES, TRUE);

    foreach (self::NAV_ITEMS as $route => $label) {
      $access = $this->accessManager->checkNamedRoute($route, [], $this->currentUser, TRUE);
      if (!$access->isAllowed()) {
        continue;
      }
      try {
        $url = Url::fromRoute($route);
      }
      catch (\Exception $e) {
        continue;
      }
      $isActive = ($route === $currentRoute)
        || ($route === 'myeventlane_reporting.admin.overview' && $reportingContext);
      $links[] = [
        'label' => $this->t($label),
        'url' => $url->toString(),
        'active' => $isActive,
      ];
    }

    if (empty($links)) {
      return [];
    }

    return [
      '#theme' => 'myeventlane_admin_sidebar_nav',
      '#links' => $links,
      '#current_route' => $currentRoute,
      '#attached' => [
        'library' => ['myeventlane_admin_dashboard/sidebar_nav'],
      ],
    ];
  }

  public function getCacheContexts(): array {
    return Cache::mergeContexts(parent::getCacheContexts(), [
      'user.permissions',
      'route',
    ]);
  }

  public function getCacheTags(): array {
    return Cache::mergeTags(parent::getCacheTags(), [
      'config:myeventlane_admin_dashboard.settings',
    ]);
  }

}
