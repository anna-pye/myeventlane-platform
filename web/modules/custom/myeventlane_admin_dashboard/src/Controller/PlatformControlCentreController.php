<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_admin_dashboard\Service\DashboardRenderer;
use Drupal\myeventlane_admin_dashboard\Service\PlatformAlertService;
use Drupal\myeventlane_admin_dashboard\Service\PlatformMetricsService;
use Drupal\myeventlane_admin_dashboard\Service\PlatformRecentActivityService;
use Drupal\myeventlane_core\Service\MelAdminShellBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Platform Control Centre shell and overview dashboard.
 */
final class PlatformControlCentreController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected DashboardRenderer $dashboardRenderer,
    protected PlatformMetricsService $metricsService,
    protected PlatformAlertService $alertService,
    protected PlatformRecentActivityService $recentActivityService,
    protected MelAdminShellBuilder $adminShellBuilder,
    protected EntityTypeManagerInterface $melEntityTypeManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_admin_dashboard.dashboard_renderer'),
      $container->get('myeventlane_admin_dashboard.metrics'),
      $container->get('myeventlane_admin_dashboard.platform_alert'),
      $container->get('myeventlane_admin_dashboard.platform_recent_activity'),
      $container->get('myeventlane_core.mel_admin_shell_builder'),
      $container->get('entity_type.manager'),
      $container->get('logger.channel.myeventlane_admin_dashboard'),
    );
  }

  /**
   * Returns the admin overview page inside the shared admin shell.
   */
  public function overview(Request $request): array {
    return $this->buildAdminShell(
      $this->buildOverviewMain($request),
      $this->t('Admin Control Centre'),
      $this->t('Monitor platform health, vendors, finance, and support in one view.'),
    );
  }

  /**
   * Returns the platform route with the same shared admin shell.
   */
  public function studio(Request $request): array {
    return $this->buildAdminShell(
      $this->buildOverviewMain($request),
      $this->t('Platform'),
      $this->t('Operational workspace for platform monitoring and interventions.'),
    );
  }

  /**
   * Builds the main overview dashboard render array.
   */
  private function buildOverviewMain(Request $request): array {
    $days = $this->sanitizeDays($request);
    $kpis = $this->metricsService->getKpis($days);
    $series = $this->metricsService->getRevenueSeriesDaily($days);
    $vendor_ranking = $this->metricsService->getVendorRanking($days, 10);
    $payout_summary = $this->metricsService->getPayoutLiabilitySummary($days);
    $active_vendors_count = $this->getActiveVendorsCount();
    $upcoming_events_count = $this->getUpcomingEventsCount();
    $open_escalations_count = $this->getOpenEscalationsCount();
    $export_url = Url::fromRoute('myeventlane_admin_dashboard.financial_export', [], [
      'query' => ['days' => $days],
    ])->toString();

    $build = [
      '#theme' => 'platform_control_centre',
      '#kpis' => $kpis,
      '#series' => $series,
      '#vendor_ranking' => $vendor_ranking,
      '#payout_summary' => $payout_summary,
      '#active_vendors_count' => $active_vendors_count,
      '#upcoming_events_count' => $upcoming_events_count,
      '#open_escalations_count' => $open_escalations_count,
      '#export_url' => $export_url,
      '#days' => $days,
      '#filter_url_30' => Url::fromRoute('myeventlane_admin_dashboard.platform_control', [], ['query' => ['days' => 30]])->toString(),
      '#filter_url_90' => Url::fromRoute('myeventlane_admin_dashboard.platform_control', [], ['query' => ['days' => 90]])->toString(),
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
   * Builds the shared admin shell around supplied main content.
   */
  private function buildAdminShell(array $main, string|\Stringable $title, string|\Stringable $subtitle): array {
    $shell = $this->adminShellBuilder->wrapStudio(
      $main,
      $title,
      $subtitle,
      $this->buildStudioRightPanel(),
      ['myeventlane_admin_dashboard/platform_control_centre'],
    );
    $extra = new CacheableMetadata();
    $extra->addCacheContexts(['user.permissions', 'user.roles', 'url.query_args:days']);
    $extra->addCacheTags([
      'platform:summary',
      'escalation_list',
      'commerce_order_list',
      'myeventlane_payout_ledger',
      'commerce_subscription_list',
    ]);
    $extra->setCacheMaxAge(120);
    CacheableMetadata::createFromRenderArray($shell)->merge($extra)->applyTo($shell);
    return $shell;
  }

  /**
   * Builds compact right panel with existing alert/activity/payout data.
   */
  private function buildStudioRightPanel(): array {
    $alerts = $this->alertService->getAlerts();
    $activity = $this->recentActivityService->getRecentActivity();
    $payout_summary = $this->metricsService->getPayoutLiabilitySummary(30);

    $alert_items = [];
    foreach (array_slice($alerts, 0, 4) as $alert) {
      $title = (string) ($alert['title'] ?? 'Alert');
      $count = (int) ($alert['count'] ?? 0);
      $url = (string) ($alert['url'] ?? '');
      $alert_items[] = ($url !== '')
        ? '<a href="' . $url . '">' . $title . ' (' . $count . ')</a>'
        : $title . ' (' . $count . ')';
    }

    $recent_items = [];
    foreach (array_slice($activity['escalations'] ?? [], 0, 3) as $row) {
      $subject = (string) ($row['subject'] ?? $this->t('Escalation'));
      $url = (string) ($row['url'] ?? '');
      $recent_items[] = ($url !== '') ? '<a href="' . $url . '">' . $subject . '</a>' : $subject;
    }

    $payouts_url = Url::fromRoute('myeventlane_admin_dashboard.payouts')->toString();
    $reports_url = Url::fromRoute('myeventlane_admin_dashboard.reports')->toString();

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-studio-sidepanel']],
      'status' => [
        '#markup' => '<h3>' . $this->t('Review status') . '</h3><p>' . $this->t('Compact operational summary from existing dashboard services.') . '</p>',
      ],
      'alerts_title' => [
        '#markup' => '<h4>' . $this->t('Alerts') . '</h4>',
      ],
      'alerts' => [
        '#theme' => 'item_list',
        '#items' => array_map(static fn(string $item): array => ['#markup' => $item], $alert_items),
      ],
      'activity_title' => [
        '#markup' => '<h4>' . $this->t('Recent escalations') . '</h4>',
      ],
      'activity' => [
        '#theme' => 'item_list',
        '#items' => array_map(static fn(string $item): array => ['#markup' => $item], $recent_items),
      ],
      'payouts' => [
        '#markup' => '<p><strong>' . $this->t('Unpaid liability') . ':</strong> $' . number_format((float) ($payout_summary['unpaid_total'] ?? 0), 2) . '</p>',
      ],
      'links' => [
        '#theme' => 'item_list',
        '#items' => [
          ['#markup' => '<a href="' . $payouts_url . '">' . $this->t('Open payouts') . '</a>'],
          ['#markup' => '<a href="' . $reports_url . '">' . $this->t('Open reports') . '</a>'],
        ],
      ],
    ];
  }

  /**
   * Sanitizes the days query parameter to allowed values.
   */
  private function sanitizeDays(Request $request): int {
    $days = (int) $request->query->get('days', 30);
    return in_array($days, [7, 30, 90], TRUE) ? $days : 30;
  }

  /**
   * Returns active vendor count for the admin KPI row.
   */
  private function getActiveVendorsCount(): int {
    if (!$this->melEntityTypeManager->hasDefinition('myeventlane_vendor')) {
      return 0;
    }

    try {
      return (int) $this->melEntityTypeManager->getStorage('myeventlane_vendor')
        ->getQuery()
        ->accessCheck(FALSE)
        ->count()
        ->execute();
    }
    catch (\Throwable $e) {
      $this->logger->warning('Active vendor count unavailable: @message', ['@message' => $e->getMessage()]);
      return 0;
    }
  }

  /**
   * Returns upcoming published event count for the admin KPI row.
   */
  private function getUpcomingEventsCount(): int {
    try {
      return (int) $this->melEntityTypeManager->getStorage('node')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'event')
        ->condition('status', 1)
        ->condition('field_event_start', date('Y-m-d\TH:i:s'), '>=')
        ->count()
        ->execute();
    }
    catch (\Throwable $e) {
      $this->logger->warning('Upcoming event count unavailable: @message', ['@message' => $e->getMessage()]);
      return 0;
    }
  }

  /**
   * Returns open escalations count for the admin KPI row.
   */
  private function getOpenEscalationsCount(): int {
    try {
      foreach ($this->alertService->getAlerts() as $alert) {
        if (($alert['title'] ?? '') === 'Open escalations') {
          return (int) ($alert['count'] ?? 0);
        }
      }
    }
    catch (\Throwable $e) {
      $this->logger->warning('Open escalations count unavailable: @message', ['@message' => $e->getMessage()]);
    }

    return 0;
  }

}
