<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_admin_dashboard\Service\DashboardRenderer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Platform Control Centre – high-performance executive dashboard.
 *
 * Replaces the legacy admin dashboard. All heavy logic runs in cron;
 * this controller only builds the page skeleton and delegates to lazy builders.
 */
final class PlatformControlCentreController extends ControllerBase {

  /**
   * The dashboard renderer service.
   */
  protected DashboardRenderer $dashboardRenderer;

  /**
   * Constructs the controller.
   */
  public function __construct(DashboardRenderer $dashboardRenderer) {
    $this->dashboardRenderer = $dashboardRenderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('myeventlane_admin_dashboard.dashboard_renderer'),
    );
  }

  /**
   * Returns the Platform Control Centre page.
   *
   * @return array
   *   Render array with lazy-built sections.
   */
  public function overview(): array {
    $build = [
      '#theme' => 'platform_control_centre',
      '#attached' => [
        'library' => [
          'myeventlane_admin_dashboard/platform_control_centre',
        ],
      ],
      '#cache' => [
        'tags' => ['platform:summary', 'escalation_list', 'commerce_order_list'],
        'contexts' => ['user.permissions'],
        'max-age' => 60,
      ],
    ];

    $build['kpi_tiles'] = [
      '#lazy_builder' => [
        'myeventlane_admin_dashboard.dashboard_renderer:renderKpis',
        [],
      ],
      '#create_placeholder' => TRUE,
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => ['platform:summary'],
        'max-age' => 300,
      ],
    ];

    $build['alerts'] = [
      '#lazy_builder' => [
        'myeventlane_admin_dashboard.dashboard_renderer:renderAlerts',
        [],
      ],
      '#create_placeholder' => TRUE,
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => ['escalation_list'],
        'max-age' => 60,
      ],
    ];

    $build['trend'] = [
      '#lazy_builder' => [
        'myeventlane_admin_dashboard.dashboard_renderer:renderTrend',
        [],
      ],
      '#create_placeholder' => TRUE,
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => ['platform:summary'],
        'max-age' => 3600,
      ],
    ];

    $build['recent_activity'] = [
      '#lazy_builder' => [
        'myeventlane_admin_dashboard.dashboard_renderer:renderRecentActivity',
        [],
      ],
      '#create_placeholder' => TRUE,
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => ['escalation_list', 'commerce_order_list'],
        'max-age' => 120,
      ],
    ];

    return $build;
  }

}
