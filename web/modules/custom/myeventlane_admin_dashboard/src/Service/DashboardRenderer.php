<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Service;

use Drupal\Core\Security\TrustedCallbackInterface;

/**
 * Renders Platform Control Centre sections for lazy builders.
 *
 * Implements TrustedCallbackInterface so its methods can be used as
 * #lazy_builder callbacks. Uses dependency injection; no \Drupal::service().
 *
 * @internal
 */
final class DashboardRenderer implements TrustedCallbackInterface {

  protected PlatformKpiService $kpiService;

  protected PlatformAlertService $alertService;

  protected PlatformTrendService $trendService;

  protected PlatformRecentActivityService $recentActivityService;

  /**
   * Constructs the renderer.
   */
  public function __construct(
    PlatformKpiService $kpiService,
    PlatformAlertService $alertService,
    PlatformTrendService $trendService,
    PlatformRecentActivityService $recentActivityService,
  ) {
    $this->kpiService = $kpiService;
    $this->alertService = $alertService;
    $this->trendService = $trendService;
    $this->recentActivityService = $recentActivityService;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return [
      'renderKpis',
      'renderAlerts',
      'renderTrend',
      'renderRecentActivity',
    ];
  }

  /**
   * Lazy builder: KPI tiles.
   *
   * @return array
   *   Render array for platform_control_centre__kpi_tiles.
   */
  public function renderKpis(): array {
    $kpis = $this->kpiService->getKpis();
    $meta = $this->kpiService->getCacheMetadata();
    return [
      '#theme' => 'platform_control_centre__kpi_tiles',
      '#kpis' => $kpis,
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => $meta['tags'] ?? [],
        'max-age' => $meta['max-age'] ?? 0,
      ],
    ];
  }

  /**
   * Lazy builder: operational alerts.
   *
   * @return array
   *   Render array for platform_control_centre__alerts.
   */
  public function renderAlerts(): array {
    $alerts = $this->alertService->getAlerts();
    $meta = $this->alertService->getCacheMetadata();
    return [
      '#theme' => 'platform_control_centre__alerts',
      '#alerts' => $alerts,
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => $meta['tags'] ?? [],
        'max-age' => $meta['max-age'] ?? 0,
      ],
    ];
  }

  /**
   * Lazy builder: revenue trend snapshot.
   *
   * @return array
   *   Render array for platform_control_centre__trend.
   */
  public function renderTrend(): array {
    $trend = $this->trendService->getTrend();
    $meta = $this->trendService->getCacheMetadata();
    return [
      '#theme' => 'platform_control_centre__trend',
      '#trend' => $trend,
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => $meta['tags'] ?? [],
        'max-age' => $meta['max-age'] ?? 0,
      ],
    ];
  }

  /**
   * Lazy builder: recent escalations and orders tables.
   *
   * @return array
   *   Render array for platform_control_centre__recent_activity.
   */
  public function renderRecentActivity(): array {
    $activity = $this->recentActivityService->getRecentActivity();
    $meta = $this->recentActivityService->getCacheMetadata();
    return [
      '#theme' => 'platform_control_centre__recent_activity',
      '#activity' => $activity,
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => $meta['tags'] ?? [],
        'max-age' => $meta['max-age'] ?? 0,
      ],
    ];
  }

}
