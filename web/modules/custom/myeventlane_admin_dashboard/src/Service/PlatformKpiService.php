<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
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
    private readonly UrlGeneratorInterface $urlGenerator,
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
   * Returns placeholder KPI tiles when data is unavailable.
   *
   * @return array<int, array{label: string, value: string, delta: array|null, severity: string, url: string|null}>
   */
  private function buildPlaceholderKpis(): array {
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

  /**
   * Builds KPI data from summary or placeholder.
   *
   * @return array<int, array{label: string, value: string, delta: array|null, severity: string, url: string|null}>
   */
  private function buildKpis(): array {
    if (!$this->summaryReader->hasData()) {
      $this->logger->warning('Platform summary has no data; KPI service returning placeholders.');
      return $this->buildPlaceholderKpis();
    }

    $totals = $this->summaryReader->getTotalsLastNDays(self::DAYS_30);
    if ($totals === NULL) {
      $this->logger->warning('Platform summary totals are NULL; KPI service returning placeholders.');
      return $this->buildPlaceholderKpis();
    }

    $reports_url = $this->urlGenerator->generateFromRoute('myeventlane_reporting.admin.overview');
    $escalations_url = $this->urlGenerator->generateFromRoute('entity.escalation.collection');
    $sla_at_risk = $this->alertService->getSlaAtRiskCount();

    return [
      [
        'label' => 'Revenue (30d)',
        'value' => '$' . number_format($totals['revenue_gross'], 2),
        'delta' => NULL,
        'severity' => 'green',
        'url' => $reports_url,
      ],
      [
        'label' => 'Orders (30d)',
        'value' => (string) $totals['orders_completed'],
        'delta' => NULL,
        'severity' => 'green',
        'url' => $reports_url,
      ],
      [
        'label' => 'Open escalations',
        'value' => (string) $totals['escalations_open'],
        'delta' => NULL,
        'severity' => $totals['escalations_open'] > 0 ? 'amber' : 'green',
        'url' => $escalations_url,
      ],
      [
        'label' => 'SLA at risk',
        'value' => (string) $sla_at_risk,
        'delta' => NULL,
        'severity' => $sla_at_risk > 0 ? 'red' : 'green',
        'url' => $escalations_url,
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
