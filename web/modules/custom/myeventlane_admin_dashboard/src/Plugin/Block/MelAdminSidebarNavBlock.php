<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Plugin\Block;

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

  /**
   * Nav items: route_name => [label, permission].
   */
  private const NAV_ITEMS = [
    'myeventlane_admin_dashboard.platform_control' => ['Overview', 'access myeventlane platform control centre'],
    'myeventlane_admin_dashboard.financials' => ['Financials', 'view myeventlane financials'],
    'myeventlane_admin_dashboard.vendors' => ['Vendors', 'view myeventlane vendors'],
    'entity.escalation.collection' => ['Escalations', 'view myeventlane escalations'],
    'myeventlane_admin_dashboard.payouts' => ['Payouts', 'view myeventlane payouts'],
    'myeventlane_admin_dashboard.reports' => ['Reports', 'view myeventlane reports'],
    'myeventlane_admin_dashboard.financial_export' => ['Export (CSV)', 'export myeventlane financials'],
  ];

  /**
   * Constructs the block.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected RouteMatchInterface $routeMatch,
    protected AccountInterface $currentUser,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $currentRoute = (string) $this->routeMatch->getRouteName();
    $links = [];

    foreach (self::NAV_ITEMS as $route => [$label, $permission]) {
      if (!$this->currentUser->hasPermission($permission)) {
        continue;
      }
      try {
        $url = Url::fromRoute($route);
      }
      catch (\Exception $e) {
        continue;
      }
      $links[] = [
        'label' => $this->t($label),
        'url' => $url->toString(),
        'active' => ($route === $currentRoute),
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

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return Cache::mergeContexts(parent::getCacheContexts(), [
      'user.permissions',
      'route',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return Cache::mergeTags(parent::getCacheTags(), [
      'config:myeventlane_admin_dashboard.settings',
    ]);
  }

}
