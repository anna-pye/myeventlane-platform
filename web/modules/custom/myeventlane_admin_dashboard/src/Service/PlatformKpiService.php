<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\myeventlane_summary\Service\PlatformSummaryReader;
use Psr\Log\LoggerInterface;

/**
 * Returns 4 KPI tiles for the Platform Control Centre.
 *
 * Reads from summary table only. Never touches Commerce orders.
 *
 * @internal
 */
final class PlatformKpiService {

  private const CACHE_KEY = 'platform_control_centre:kpis';

  private const CACHE_MAX_AGE = 300;

  private const CACHE_TAGS = ['platform:summary'];

  private const DAYS_30 = 30;

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly PlatformSummaryReader $summaryReader,
    private readonly PlatformAlertService $alertService,
    private readonly CacheBackendInterface $cache,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns KPI tile data.
   *
   * @return array<int, array{label: string, value: string, delta: array|null, severity: string, url: string|null}>
   */
  public function getKpis(): array {
    $cached = $this->cache->get(self::CACHE_KEY);
    if ($cached !== FALSE && is_array($cached->data)) {
      return $cached->data;
    }

    $data = $this->buildKpis();
    $this->cache->set(self::CACHE_KEY, $data, time() + self::CACHE_MAX_AGE, self::CACHE_TAGS);

    return $data;
  }

  /**
   * Builds KPI data from summary or placeholder.
   */
  private function buildKpis(): array {
    if (!$this->summaryReader->hasData()) {
      $this->logger->warning('Platform summary has no data; KPI service returning placeholders.');
      return [
        [
          'label' => 'Revenue (30d)',
          'value' => '—',
          'delta' => NULL,
          'severity' => 'neutral',
          'url' => NULL,
        ],
        [
          'label' => 'Orders (30d)',
          'value' => '—',
          'delta' => NULL,
          'severity' => 'neutral',
          'url' => NULL,
        ],
        [
          'label' => 'Open escalations',
          'value' => '—',
          'delta' => NULL,
          'severity' => 'neutral',
          'url' => NULL,
        ],
        [
          'label' => 'SLA at risk',
          'value' => '—',
          'delta' => NULL,
          'severity' => 'neutral',
          'url' => NULL,
        ],
      ];
    }

    $totals = $this->summaryReader->getTotalsLastNDays(self::DAYS_30);
    if ($totals === NULL) {
      return $this->buildKpis();
    }

    return [
      [
        'label' => 'Revenue (30d)',
        'value' => '$' . number_format($totals['revenue_gross'], 2),
        'delta' => NULL,
        'severity' => 'green',
        'url' => '/admin/myeventlane/reports',
      ],
      [
        'label' => 'Orders (30d)',
        'value' => (string) $totals['orders_completed'],
        'delta' => NULL,
        'severity' => 'green',
        'url' => '/admin/myeventlane/reports',
      ],
      [
        'label' => 'Open escalations',
        'value' => (string) $totals['escalations_open'],
        'delta' => NULL,
        'severity' => $totals['escalations_open'] > 0 ? 'amber' : 'green',
        'url' => '/admin/myeventlane/escalations',
      ],
      [
        'label' => 'SLA at risk',
        'value' => (string) $this->alertService->getSlaAtRiskCount(),
        'delta' => NULL,
        'severity' => $this->alertService->getSlaAtRiskCount() > 0 ? 'red' : 'green',
        'url' => '/admin/myeventlane/escalations',
      ],
    ];
  }

  /**
   * Returns cache metadata for render arrays.
   *
   * @return array{tags: string[], max-age: int}
   */
  public function getCacheMetadata(): array {
    return [
      'tags' => self::CACHE_TAGS,
      'max-age' => self::CACHE_MAX_AGE,
    ];
  }

}
