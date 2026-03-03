<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_admin_dashboard\Service\DashboardRenderer;
use Drupal\myeventlane_admin_dashboard\Service\PlatformMetricsService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Platform Control Centre – high-performance executive dashboard.
 *
 * Provides KPIs, revenue chart, vendor ranking, payout liability, and links.
 */
final class PlatformControlCentreController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected DashboardRenderer $dashboardRenderer,
    protected PlatformMetricsService $metricsService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('myeventlane_admin_dashboard.dashboard_renderer'),
      $container->get('myeventlane_admin_dashboard.metrics'),
    );
  }

  /**
   * Returns the Platform Control Centre page.
   *
   * @return array
   *   Render array.
   */
  public function overview(Request $request): array {
    $days = $this->sanitizeDays($request);
    $kpis = $this->metricsService->getKpis($days);
    $series = $this->metricsService->getRevenueSeriesDaily($days);
    $vendorRanking = $this->metricsService->getVendorRanking($days, 10);
    $payoutSummary = $this->metricsService->getPayoutLiabilitySummary($days);

    $exportUrl = Url::fromRoute('myeventlane_admin_dashboard.financial_export', [], [
      'query' => ['days' => $days],
    ])->toString();

    $currentRoute = 'myeventlane_admin_dashboard.platform_control';

    $build = [
      '#theme' => 'platform_control_centre',
      '#kpis' => $kpis,
      '#series' => $series,
      '#vendor_ranking' => $vendorRanking,
      '#payout_summary' => $payoutSummary,
      '#export_url' => $exportUrl,
      '#days' => $days,
      '#filter_url_30' => Url::fromRoute($currentRoute, [], ['query' => ['days' => 30]])->toString(),
      '#filter_url_90' => Url::fromRoute($currentRoute, [], ['query' => ['days' => 90]])->toString(),
      '#pro_subscriptions' => [
        '#lazy_builder' => [
          'myeventlane_admin_dashboard.pro_reporting_builder:renderProKpis',
          [],
        ],
        '#create_placeholder' => TRUE,
      ],
      '#attached' => [
        'library' => [
          'myeventlane_admin_dashboard/platform_control_centre',
        ],
        'drupalSettings' => [
          'myeventlaneAdminDashboard' => [
            'revenueSeries' => [
              'labels' => $series['labels'],
              'gross' => $series['gross'],
              'commission' => $series['commission'],
              'net' => $series['net'],
            ],
          ],
        ],
      ],
      '#cache' => [
        'tags' => ['platform:summary', 'escalation_list', 'commerce_order_list', 'myeventlane_payout_ledger', 'commerce_subscription_list'],
        'contexts' => ['user.roles', 'url.query_args:days'],
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

  /**
   * Sanitizes the days query parameter to allowed values.
   */
  private function sanitizeDays(Request $request): int {
    $days = (int) $request->query->get('days', 30);
    return in_array($days, [7, 30, 90], TRUE) ? $days : 30;
  }

}
