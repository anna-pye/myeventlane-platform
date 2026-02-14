<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\myeventlane_summary\Service\PlatformSummaryReader;
use Psr\Log\LoggerInterface;

/**
 * Returns revenue trend snapshot (6 months) for the Platform Control Centre.
 *
 * Reads from summary table only.
 *
 * @internal
 */
final class PlatformTrendService {

  private const CACHE_KEY = 'platform_control_centre:trend';

  private const CACHE_MAX_AGE = 3600;

  private const CACHE_TAGS = ['platform:summary'];

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly PlatformSummaryReader $summaryReader,
    private readonly CacheBackendInterface $cache,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns monthly revenue data for the last 6 months.
   *
   * @return array{months: array<int, array{month: string, revenue: float}>, max_revenue: float, empty: bool}
   */
  public function getTrend(): array {
    $cached = $this->cache->get(self::CACHE_KEY);
    if ($cached !== FALSE && is_array($cached->data)) {
      return $cached->data;
    }

    $data = $this->buildTrend();
    $this->cache->set(self::CACHE_KEY, $data, time() + self::CACHE_MAX_AGE, self::CACHE_TAGS);

    return $data;
  }

  /**
   * Builds trend from summary or placeholder.
   */
  private function buildTrend(): array {
    if (!$this->summaryReader->hasData()) {
      $this->logger->warning('Platform summary has no data; trend service returning empty.');
      return [
        'months' => [],
        'max_revenue' => 0.0,
        'empty' => TRUE,
      ];
    }

    $months = $this->summaryReader->getMonthlyRevenueLast6Months();
    $max_revenue = 0.0;
    foreach ($months as $m) {
      if ($m['revenue'] > $max_revenue) {
        $max_revenue = $m['revenue'];
      }
    }

    return [
      'months' => $months,
      'max_revenue' => $max_revenue,
      'empty' => empty($months),
    ];
  }

  /**
   * Returns cache metadata.
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
