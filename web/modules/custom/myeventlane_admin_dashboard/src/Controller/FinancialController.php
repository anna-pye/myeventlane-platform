<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_admin_dashboard\Service\PlatformMetricsService;
use Drupal\myeventlane_core\Service\MelAdminShellBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Financials tab – financial metrics and export.
 */
final class FinancialController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected PlatformMetricsService $metricsService,
    protected MelAdminShellBuilder $adminShellBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('myeventlane_admin_dashboard.metrics'),
      $container->get('myeventlane_core.mel_admin_shell_builder'),
    );
  }

  /**
   * Returns the Financials overview page.
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

    $currentRoute = 'myeventlane_admin_dashboard.financials';

    $main = [
      '#theme' => 'platform_control_centre_financials',
      '#kpis' => $kpis,
      '#series' => $series,
      '#vendor_ranking' => $vendorRanking,
      '#payout_summary' => $payoutSummary,
      '#export_url' => $exportUrl,
      '#days' => $days,
      '#filter_url_30' => Url::fromRoute($currentRoute, [], ['query' => ['days' => 30]])->toString(),
      '#filter_url_90' => Url::fromRoute($currentRoute, [], ['query' => ['days' => 90]])->toString(),
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
        'tags' => ['commerce_order_list', 'myeventlane_payout_ledger'],
        'contexts' => ['user.roles', 'url.query_args:days'],
        'max-age' => 300,
      ],
    ];

    return $this->adminShellBuilder->wrapStandard(
      $main,
      $this->t('Financials'),
      $this->t('Revenue, payouts context, and export entry points.'),
    );
  }

  /**
   * Sanitizes the days query parameter to allowed values.
   */
  private function sanitizeDays(Request $request): int {
    $days = (int) $request->query->get('days', 30);
    return in_array($days, [7, 30, 90], TRUE) ? $days : 30;
  }

}
