<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;

/**
 * Platform-wide Boost benchmarks for future cohort comparisons.
 *
 * Phase 7A foundations only — not exposed to organisers. Aggregates
 * impressions, clicks, CTR, and distinct boosted events from existing
 * boost stats storage. Readiness is gated on platform-wide volume.
 */
final class BoostBenchmarkService {

  private const CACHE_TTL = 43200;

  private const CACHE_KEY_GLOBAL = 'mel:boost_benchmark:global';

  private const CACHE_KEY_EVENT_TYPE_PREFIX = 'mel:boost_benchmark:event_type:';

  private const CACHE_KEY_PLACEMENT_PREFIX = 'mel:boost_benchmark:placement:';

  private const READINESS_MIN_IMPRESSIONS = 500;

  private const READINESS_MIN_CLICKS = 50;

  private const READINESS_MIN_EVENTS = 25;

  /**
   * Allowed field_event_type values for event-type benchmarks.
   */
  private const EVENT_TYPES = ['rsvp', 'paid', 'both', 'external'];

  /**
   * Constructs a BoostBenchmarkService.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly CacheBackendInterface $cache,
    private readonly BoostPlacementResolver $placementResolver,
  ) {}

  /**
   * Returns the platform-wide Boost benchmark.
   *
   * @return array
   *   Benchmark payload with readiness, impressions, clicks, ctr, and
   *   distinct_boosted_events keys.
   */
  public function getGlobalBenchmark(): array {
    return $this->getCached(self::CACHE_KEY_GLOBAL, fn (): array => $this->loadStatsAggregate());
  }

  /**
   * Returns a Boost benchmark scoped to an event type.
   *
   * @param string $eventType
   *   Field event type value (rsvp, paid, both, external).
   *
   * @return array
   *   Benchmark payload with readiness, impressions, clicks, ctr, and
   *   distinct_boosted_events keys.
   */
  public function getEventTypeBenchmark(string $eventType): array {
    $eventType = strtolower(trim($eventType));
    if (!in_array($eventType, self::EVENT_TYPES, TRUE)) {
      return $this->buildResponse([
        'impressions' => 0,
        'clicks' => 0,
        'distinct_boosted_events' => 0,
      ]);
    }

    $cacheKey = self::CACHE_KEY_EVENT_TYPE_PREFIX . $eventType;
    return $this->getCached($cacheKey, fn (): array => $this->loadStatsAggregateForEventType($eventType));
  }

  /**
   * Returns a Boost benchmark scoped to a placement key.
   *
   * @param string $placement
   *   Placement identifier from boost stats (e.g. homepage_discover).
   *
   * @return array
   *   Benchmark payload with readiness, impressions, clicks, ctr, and
   *   distinct_boosted_events keys.
   */
  public function getPlacementBenchmark(string $placement): array {
    $placement = strtolower(trim($placement));
    if (!$this->placementResolver->isValidPlacement($placement)) {
      return $this->buildResponse([
        'impressions' => 0,
        'clicks' => 0,
        'distinct_boosted_events' => 0,
      ]);
    }

    $cacheKey = self::CACHE_KEY_PLACEMENT_PREFIX . $placement;
    return $this->getCached($cacheKey, fn (): array => $this->loadStatsAggregateForPlacement($placement));
  }

  /**
   * Loads cached benchmark data or computes and stores it.
   *
   * @param string $cacheKey
   *   Cache identifier.
   * @param callable $loader
   *   Raw aggregate loader returning impressions, clicks, and event count.
   *
   * @return array
   *   Benchmark payload.
   */
  private function getCached(string $cacheKey, callable $loader): array {
    $cached = $this->cache->get($cacheKey);
    if ($cached !== FALSE && is_array($cached->data)) {
      return $cached->data;
    }

    $payload = $this->buildResponse($loader());
    $this->cache->set($cacheKey, $payload, time() + self::CACHE_TTL);
    return $payload;
  }

  /**
   * Builds a benchmark response with readiness metadata.
   *
   * @param array $raw
   *   Raw aggregate values for the requested slice.
   *
   * @return array
   *   Benchmark payload.
   */
  private function buildResponse(array $raw): array {
    $impressions = max(0, (int) ($raw['impressions'] ?? 0));
    $clicks = max(0, (int) ($raw['clicks'] ?? 0));
    $distinctEvents = max(0, (int) ($raw['distinct_boosted_events'] ?? 0));

    $platform = $this->getPlatformTotals();
    $payload = [
      'impressions' => $impressions,
      'clicks' => $clicks,
      'ctr' => $this->computeCtr($impressions, $clicks),
      'distinct_boosted_events' => $distinctEvents,
    ];

    if ($platform['impressions'] < self::READINESS_MIN_IMPRESSIONS
      || $platform['clicks'] < self::READINESS_MIN_CLICKS
      || $platform['distinct_boosted_events'] < self::READINESS_MIN_EVENTS) {
      return $payload + [
        'ready' => FALSE,
        'reason' => 'insufficient_data',
      ];
    }

    return $payload + ['ready' => TRUE];
  }

  /**
   * Returns platform-wide totals used for readiness gating.
   *
   * @return array
   *   Platform aggregate totals keyed by impressions, clicks, and
   *   distinct_boosted_events.
   */
  private function getPlatformTotals(): array {
    $cached = $this->cache->get(self::CACHE_KEY_GLOBAL);
    if ($cached !== FALSE && is_array($cached->data)) {
      return [
        'impressions' => (int) ($cached->data['impressions'] ?? 0),
        'clicks' => (int) ($cached->data['clicks'] ?? 0),
        'distinct_boosted_events' => (int) ($cached->data['distinct_boosted_events'] ?? 0),
      ];
    }

    return $this->loadStatsAggregate();
  }

  /**
   * Loads platform-wide aggregates from myeventlane_boost_stats.
   *
   * @return array
   *   Aggregate totals keyed by impressions, clicks, and
   *   distinct_boosted_events.
   */
  private function loadStatsAggregate(): array {
    if (!$this->database->schema()->tableExists('myeventlane_boost_stats')) {
      return [
        'impressions' => 0,
        'clicks' => 0,
        'distinct_boosted_events' => 0,
      ];
    }

    try {
      $query = $this->database->select('myeventlane_boost_stats', 's');
      $query->addExpression('COALESCE(SUM(s.impressions), 0)', 'impressions');
      $query->addExpression('COALESCE(SUM(s.clicks), 0)', 'clicks');
      $query->addExpression('COUNT(DISTINCT s.event_id)', 'distinct_boosted_events');
      $row = $query->execute()->fetchAssoc();
    }
    catch (\Exception) {
      return [
        'impressions' => 0,
        'clicks' => 0,
        'distinct_boosted_events' => 0,
      ];
    }

    return [
      'impressions' => (int) ($row['impressions'] ?? 0),
      'clicks' => (int) ($row['clicks'] ?? 0),
      'distinct_boosted_events' => (int) ($row['distinct_boosted_events'] ?? 0),
    ];
  }

  /**
   * Loads aggregates for a single event type via node field join.
   *
   * @return array
   *   Aggregate totals keyed by impressions, clicks, and
   *   distinct_boosted_events.
   */
  private function loadStatsAggregateForEventType(string $eventType): array {
    if (!$this->database->schema()->tableExists('myeventlane_boost_stats')
      || !$this->database->schema()->tableExists('node__field_event_type')) {
      return [
        'impressions' => 0,
        'clicks' => 0,
        'distinct_boosted_events' => 0,
      ];
    }

    try {
      $query = $this->database->select('myeventlane_boost_stats', 's');
      $query->innerJoin(
        'node__field_event_type',
        'et',
        'et.entity_id = s.event_id AND et.deleted = 0',
      );
      $query->condition('et.field_event_type_value', $eventType);
      $query->addExpression('COALESCE(SUM(s.impressions), 0)', 'impressions');
      $query->addExpression('COALESCE(SUM(s.clicks), 0)', 'clicks');
      $query->addExpression('COUNT(DISTINCT s.event_id)', 'distinct_boosted_events');
      $row = $query->execute()->fetchAssoc();
    }
    catch (\Exception) {
      return [
        'impressions' => 0,
        'clicks' => 0,
        'distinct_boosted_events' => 0,
      ];
    }

    return [
      'impressions' => (int) ($row['impressions'] ?? 0),
      'clicks' => (int) ($row['clicks'] ?? 0),
      'distinct_boosted_events' => (int) ($row['distinct_boosted_events'] ?? 0),
    ];
  }

  /**
   * Loads aggregates for a single placement from myeventlane_boost_stats.
   *
   * @return array
   *   Aggregate totals keyed by impressions, clicks, and
   *   distinct_boosted_events.
   */
  private function loadStatsAggregateForPlacement(string $placement): array {
    if (!$this->database->schema()->tableExists('myeventlane_boost_stats')) {
      return [
        'impressions' => 0,
        'clicks' => 0,
        'distinct_boosted_events' => 0,
      ];
    }

    try {
      $query = $this->database->select('myeventlane_boost_stats', 's');
      $query->condition('s.placement', $placement);
      $query->addExpression('COALESCE(SUM(s.impressions), 0)', 'impressions');
      $query->addExpression('COALESCE(SUM(s.clicks), 0)', 'clicks');
      $query->addExpression('COUNT(DISTINCT s.event_id)', 'distinct_boosted_events');
      $row = $query->execute()->fetchAssoc();
    }
    catch (\Exception) {
      return [
        'impressions' => 0,
        'clicks' => 0,
        'distinct_boosted_events' => 0,
      ];
    }

    return [
      'impressions' => (int) ($row['impressions'] ?? 0),
      'clicks' => (int) ($row['clicks'] ?? 0),
      'distinct_boosted_events' => (int) ($row['distinct_boosted_events'] ?? 0),
    ];
  }

  /**
   * Computes click-through rate.
   *
   * Uses the same ratio semantics as BoostMetricsService.
   */
  private function computeCtr(int $impressions, int $clicks): float {
    return $impressions > 0 ? ($clicks / $impressions) : 0.0;
  }

}
