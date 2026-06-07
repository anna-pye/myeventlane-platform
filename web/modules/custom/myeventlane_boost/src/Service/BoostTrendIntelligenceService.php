<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Event-scoped Boost trend intelligence from daily rollup chart data.
 *
 * Analyses how a single boosted event is performing over time by comparing
 * the earlier half of available daily stats with the recent half. Consumes
 * BoostMetricsService::getEventBoostMetrics() output only — no new queries,
 * tables, or platform benchmarks.
 *
 * Momentum score (0–100):
 * - For impressions and clicks: norm = clamp((recent − earlier) / max(earlier, 1), −1, 1).
 * - For CTR: norm = clamp((recent_ctr − earlier_ctr) / max(earlier_ctr, 0.001), −1, 1).
 * - momentum_raw = (ctr_norm × 0.4) + (clicks_norm × 0.35) + (impressions_norm × 0.25).
 * - momentum = round(((momentum_raw + 1) / 2) × 100). Fifty is neutral; above is upward
 *   momentum, below is downward.
 *
 * Trend direction (improving / stable / declining):
 * - Each metric votes +1 (recent > earlier), −1 (recent < earlier), or 0 (equal).
 * - Weighted vote uses the same 40 / 35 / 25 split as momentum.
 * - Weighted sum > 0 → improving; < 0 → declining; else stable.
 *
 * Confidence reuses BoostDecisionSupportService sample thresholds:
 * - impressions < 20 → low; impressions ≥ 100 and clicks ≥ 20 → high; else medium.
 *
 * Revenue and RSVP during Boost are aggregate window totals only (not daily) — trend
 * direction for those outcomes is not computed from myeventlane_boost_stats_daily.
 */
final class BoostTrendIntelligenceService {

  use StringTranslationTrait;

  /**
   * Minimum daily data points (matches BoostMetricsService::getChartData).
   */
  private const MIN_DAILY_POINTS = 3;

  /**
   * Confidence thresholds — aligned with BoostDecisionSupportService::buildConfidence().
   */
  private const CONFIDENCE_LOW_MAX_IMPRESSIONS = 20;

  private const CONFIDENCE_HIGH_MIN_IMPRESSIONS = 100;

  private const CONFIDENCE_HIGH_MIN_CLICKS = 20;

  /**
   * Momentum component weights (documented in class docblock).
   */
  private const WEIGHT_CTR = 0.4;

  private const WEIGHT_CLICKS = 0.35;

  private const WEIGHT_IMPRESSIONS = 0.25;

  /**
   * Constructs BoostTrendIntelligenceService.
   */
  public function __construct(TranslationInterface $stringTranslation) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Builds trend intelligence for an event Boost analytics panel.
   *
   * @param array<string, mixed> $boost_metrics
   *   Output from BoostMetricsService::getEventBoostMetrics().
   * @param bool $isTickets
   *   Whether ticket sales are enabled for the event.
   * @param bool $isRsvp
   *   Whether RSVP is enabled for the event.
   *
   * @return array<string, mixed>
   *   Trend payload for Twig. Hidden (show FALSE) when insufficient_data.
   */
  public function analyze(array $boost_metrics, bool $isTickets, bool $isRsvp): array {
    $impressions = (int) ($boost_metrics['impressions'] ?? 0);
    $clicks = (int) ($boost_metrics['clicks'] ?? 0);
    $spend = $this->parseFormattedAmount((string) ($boost_metrics['spend'] ?? '$0.00'));
    $has_boost_history = $spend > 0.0 || $impressions > 0 || $clicks > 0;

    $chart = is_array($boost_metrics['chart_data'] ?? NULL) ? $boost_metrics['chart_data'] : NULL;
    $series = is_array($chart['impressions_vs_clicks'] ?? NULL) ? $chart['impressions_vs_clicks'] : NULL;

    if ($series === NULL) {
      return $this->insufficientPayload();
    }

    $labels = is_array($series['labels'] ?? NULL) ? $series['labels'] : [];
    $dailyImpressions = is_array($series['impressions'] ?? NULL) ? $series['impressions'] : [];
    $dailyClicks = is_array($series['clicks'] ?? NULL) ? $series['clicks'] : [];

    if (count($labels) < self::MIN_DAILY_POINTS
      || count($dailyImpressions) < self::MIN_DAILY_POINTS
      || count($dailyClicks) < self::MIN_DAILY_POINTS) {
      return $this->insufficientPayload();
    }

    $periods = $this->splitDailySeries($dailyImpressions, $dailyClicks);
    if ($periods === NULL) {
      return $this->insufficientPayload();
    }

    $earlier = $periods['earlier'];
    $recent = $periods['recent'];
    $periodImpressions = $earlier['impressions'] + $recent['impressions'];

    if ($periodImpressions < self::CONFIDENCE_LOW_MAX_IMPRESSIONS) {
      return $this->insufficientPayload();
    }

    $metricTrends = [
      'impressions' => $this->directionForCounts($earlier['impressions'], $recent['impressions']),
      'clicks' => $this->directionForCounts($earlier['clicks'], $recent['clicks']),
      'ctr' => $this->directionForCtr($earlier, $recent),
    ];

    $status = $this->resolveOverallStatus($metricTrends);
    $momentum = $this->computeMomentum($earlier, $recent);
    $confidence = $this->buildConfidence($impressions, $clicks, $has_boost_history);
    $summary = $this->buildSummary($status, $metricTrends, $isTickets, $isRsvp, $boost_metrics);

    return [
      'show' => TRUE,
      'status' => $status,
      'status_label' => $this->statusLabel($status),
      'status_badge' => $this->statusBadge($status),
      'confidence' => $confidence,
      'momentum' => $momentum,
      'momentum_label' => (string) $this->t('@score momentum', ['@score' => (string) $momentum]),
      'summary' => $summary,
      'metrics' => $metricTrends,
      'has_chart' => TRUE,
    ];
  }

  /**
   * Splits daily impressions/clicks into earlier and recent halves.
   *
   * @param list<int|float|string> $dailyImpressions
   * @param list<int|float|string> $dailyClicks
   *
   * @return array{
   *   earlier: array{impressions: int, clicks: int},
   *   recent: array{impressions: int, clicks: int},
   * }|null
   */
  private function splitDailySeries(array $dailyImpressions, array $dailyClicks): ?array {
    $count = min(count($dailyImpressions), count($dailyClicks));
    if ($count < self::MIN_DAILY_POINTS) {
      return NULL;
    }

    $splitAt = (int) floor($count / 2);
    if ($splitAt < 1) {
      return NULL;
    }

    $earlierImpressions = 0;
    $earlierClicks = 0;
    $recentImpressions = 0;
    $recentClicks = 0;

    for ($i = 0; $i < $count; $i++) {
      $impression = (int) $dailyImpressions[$i];
      $click = (int) $dailyClicks[$i];
      if ($i < $splitAt) {
        $earlierImpressions += $impression;
        $earlierClicks += $click;
      }
      else {
        $recentImpressions += $impression;
        $recentClicks += $click;
      }
    }

    return [
      'earlier' => [
        'impressions' => $earlierImpressions,
        'clicks' => $earlierClicks,
      ],
      'recent' => [
        'impressions' => $recentImpressions,
        'clicks' => $recentClicks,
      ],
    ];
  }

  /**
   * Vote direction for count metrics.
   */
  private function directionForCounts(int $earlier, int $recent): array {
    $direction = match (TRUE) {
      $recent > $earlier => 'improving',
      $recent < $earlier => 'declining',
      default => 'stable',
    };

    return [
      'direction' => $direction,
      'label' => $this->metricDirectionLabel('counts', $direction),
    ];
  }

  /**
   * Vote direction for period CTR (clicks / impressions per half).
   *
   * @param array{impressions: int, clicks: int} $earlier
   * @param array{impressions: int, clicks: int} $recent
   */
  private function directionForCtr(array $earlier, array $recent): array {
    $earlierCtr = $earlier['impressions'] > 0
      ? ($earlier['clicks'] / $earlier['impressions'])
      : 0.0;
    $recentCtr = $recent['impressions'] > 0
      ? ($recent['clicks'] / $recent['impressions'])
      : 0.0;

    $direction = match (TRUE) {
      $recentCtr > $earlierCtr => 'improving',
      $recentCtr < $earlierCtr => 'declining',
      default => 'stable',
    };

    return [
      'direction' => $direction,
      'label' => $this->metricDirectionLabel('ctr', $direction),
    ];
  }

  /**
   * Resolves overall status from per-metric weighted votes.
   *
   * @param array<string, array{direction: string}> $metricTrends
   */
  private function resolveOverallStatus(array $metricTrends): string {
    $weights = [
      'ctr' => self::WEIGHT_CTR,
      'clicks' => self::WEIGHT_CLICKS,
      'impressions' => self::WEIGHT_IMPRESSIONS,
    ];

    $score = 0.0;
    foreach ($weights as $key => $weight) {
      $direction = (string) ($metricTrends[$key]['direction'] ?? 'stable');
      $vote = match ($direction) {
        'improving' => 1,
        'declining' => -1,
        default => 0,
      };
      $score += $vote * $weight;
    }

    return match (TRUE) {
      $score > 0.0 => 'improving',
      $score < 0.0 => 'declining',
      default => 'stable',
    };
  }

  /**
   * Computes momentum score 0–100 from period-over-period movement.
   *
   * @param array{impressions: int, clicks: int} $earlier
   * @param array{impressions: int, clicks: int} $recent
   */
  private function computeMomentum(array $earlier, array $recent): int {
    $impressionsNorm = $this->normalizeDelta($recent['impressions'], $earlier['impressions']);
    $clicksNorm = $this->normalizeDelta($recent['clicks'], $earlier['clicks']);

    $earlierCtr = $earlier['impressions'] > 0
      ? ($earlier['clicks'] / $earlier['impressions'])
      : 0.0;
    $recentCtr = $recent['impressions'] > 0
      ? ($recent['clicks'] / $recent['impressions'])
      : 0.0;
    $ctrNorm = $this->normalizeDelta($recentCtr, $earlierCtr, 0.001);

    $raw = (self::WEIGHT_CTR * $ctrNorm)
      + (self::WEIGHT_CLICKS * $clicksNorm)
      + (self::WEIGHT_IMPRESSIONS * $impressionsNorm);

    return (int) round((($raw + 1.0) / 2.0) * 100);
  }

  /**
   * Normalizes a delta to [−1, 1] using the earlier value as baseline.
   */
  private function normalizeDelta(float $recent, float $earlier, float $floor = 1.0): float {
    $baseline = max($earlier, $floor);
    $delta = ($recent - $earlier) / $baseline;
    return max(-1.0, min(1.0, $delta));
  }

  /**
   * Builds confidence metadata (thresholds match BoostDecisionSupportService).
   *
   * @return array{level: string, label: string, badge: string}
   */
  private function buildConfidence(int $impressions, int $clicks, bool $has_boost_history): array {
    if (!$has_boost_history) {
      return [
        'level' => 'low',
        'label' => (string) $this->t('Low confidence'),
        'badge' => 'muted',
      ];
    }

    if ($impressions < self::CONFIDENCE_LOW_MAX_IMPRESSIONS) {
      return [
        'level' => 'low',
        'label' => (string) $this->t('Low confidence'),
        'badge' => 'danger',
      ];
    }

    if ($impressions >= self::CONFIDENCE_HIGH_MIN_IMPRESSIONS
      && $clicks >= self::CONFIDENCE_HIGH_MIN_CLICKS) {
      return [
        'level' => 'high',
        'label' => (string) $this->t('High confidence'),
        'badge' => 'success',
      ];
    }

    return [
      'level' => 'medium',
      'label' => (string) $this->t('Medium confidence'),
      'badge' => 'warning',
    ];
  }

  /**
   * Builds a human-readable summary without platform comparisons.
   */
  private function buildSummary(
    string $status,
    array $metricTrends,
    bool $isTickets,
    bool $isRsvp,
    array $boost_metrics,
  ): string {
    $lines = [];

    $impressionsDirection = (string) ($metricTrends['impressions']['direction'] ?? 'stable');
    if ($impressionsDirection === 'improving') {
      $lines[] = (string) $this->t('Boost visibility is increasing week over week.');
    }
    elseif ($impressionsDirection === 'declining') {
      $lines[] = (string) $this->t('Boost visibility has softened compared with earlier activity.');
    }

    $ctrDirection = (string) ($metricTrends['ctr']['direction'] ?? 'stable');
    if ($ctrDirection === 'declining') {
      $lines[] = (string) $this->t('CTR has declined compared with earlier boost activity.');
    }
    elseif ($ctrDirection === 'improving') {
      $lines[] = (string) $this->t('Click-through rate is trending up in recent days.');
    }

    if ($status === 'stable' && $lines === []) {
      $lines[] = (string) $this->t('Engagement has remained stable.');
    }
    elseif ($status === 'improving' && $lines === []) {
      $lines[] = (string) $this->t('Recent boost activity is picking up momentum.');
    }
    elseif ($status === 'declining' && $lines === []) {
      $lines[] = (string) $this->t('Recent boost activity is softer than earlier in the run.');
    }

    if ($isTickets && (float) ($boost_metrics['revenue_during_boost'] ?? 0.0) > 0.0) {
      $lines[] = (string) $this->t('Ticket revenue during Boost is tracking — daily revenue trend is not available yet.');
    }
    elseif ($isRsvp && (int) ($boost_metrics['rsvps_during_boost'] ?? 0) > 0) {
      $lines[] = (string) $this->t('RSVPs during Boost are tracking — daily RSVP trend is not available yet.');
    }

    return implode(' ', array_slice($lines, 0, 2));
  }

  /**
   * Human label for overall trend status.
   */
  private function statusLabel(string $status): string {
    return match ($status) {
      'improving' => (string) $this->t('Improving'),
      'declining' => (string) $this->t('Declining'),
      'stable' => (string) $this->t('Stable'),
      default => (string) $this->t('Insufficient data'),
    };
  }

  /**
   * Badge variant for overall trend status.
   */
  private function statusBadge(string $status): string {
    return match ($status) {
      'improving' => 'success',
      'declining' => 'danger',
      'stable' => 'warning',
      default => 'muted',
    };
  }

  /**
   * Short label for a single metric direction.
   */
  private function metricDirectionLabel(string $metric, string $direction): string {
    if ($metric === 'ctr') {
      return match ($direction) {
        'improving' => (string) $this->t('CTR rising'),
        'declining' => (string) $this->t('CTR falling'),
        default => (string) $this->t('CTR steady'),
      };
    }

    return match ($direction) {
      'improving' => (string) $this->t('Trending up'),
      'declining' => (string) $this->t('Trending down'),
      default => (string) $this->t('Holding steady'),
    };
  }

  /**
   * Hidden payload when daily data cannot support trend analysis.
   *
   * @return array<string, mixed>
   */
  private function insufficientPayload(): array {
    return [
      'show' => FALSE,
      'status' => 'insufficient_data',
      'status_label' => (string) $this->t('Insufficient data'),
      'status_badge' => 'muted',
      'confidence' => [
        'level' => 'low',
        'label' => '',
        'badge' => 'muted',
      ],
      'momentum' => 0,
      'momentum_label' => '',
      'summary' => '',
      'metrics' => [],
      'has_chart' => FALSE,
    ];
  }

  /**
   * Parses a formatted currency string into a float.
   */
  private function parseFormattedAmount(string $formatted): float {
    if ($formatted === '—' || $formatted === '') {
      return 0.0;
    }
    $clean = preg_replace('/[^\d.]/', '', $formatted);
    if ($clean === NULL || $clean === '') {
      return 0.0;
    }
    return (float) $clean;
  }

}
