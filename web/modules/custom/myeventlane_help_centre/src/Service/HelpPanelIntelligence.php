<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Service;

/**
 * Determines ordering and selection of help panels.
 *
 * MyEventLane v2 rules:
 * - Deterministic fallback (config order)
 * - Analytics enhances, never controls
 * - Safe with zero data
 */
final class HelpPanelIntelligence {

  public function __construct(
    private readonly HelpAnalyticsSummary $analyticsSummary,
  ) {}

  /**
   * Returns ordered panel keys.
   *
   * @param array<string, array<string, mixed>> $panels
   *   Panel definitions keyed by panel_key.
   *
   * @return array<int, string>
   *   Ordered list of panel keys.
   */
  public function getOrderedPanelKeys(array $panels): array {
    if ($panels === []) {
      return [];
    }

    // Base deterministic order (config order).
    $panel_keys = array_keys($panels);

    // Fetch analytics summary (cached layer). API requires explicit keys.
    $analytics = $this->analyticsSummary->getPanelSummary($panel_keys);

    // CRITICAL: Handle empty analytics safely.
    if ($analytics === []) {
      return $panel_keys;
    }

    // Build scores.
    $scores = [];
    $has_any_data = FALSE;

    foreach ($panel_keys as $key) {
      $panel_data = $analytics[$key] ?? NULL;

      if (!is_array($panel_data)) {
        $scores[$key] = 0.0;
        continue;
      }

      $views = (int) ($panel_data['impressions'] ?? 0);
      $clicks = (int) ($panel_data['clicks'] ?? 0);

      // Avoid division by zero.
      $ctr = $views > 0 ? ($clicks / $views) : 0.0;

      // Composite score:
      // - CTR weighted higher
      // - Views provide confidence signal
      $score = ($ctr * 0.7) + (min($views, 100) / 100 * 0.3);

      if ($views > 0 || $clicks > 0) {
        $has_any_data = TRUE;
      }

      $scores[$key] = $score;
    }

    // SECOND SAFETY: No meaningful data → fallback
    if (!$has_any_data) {
      return $panel_keys;
    }

    // Stable sort:
    // - Highest score first
    // - Preserve original order on ties
    $indexed = array_values($panel_keys);

    usort($indexed, function (string $a, string $b) use ($scores, $panel_keys): int {
      $score_a = $scores[$a] ?? 0.0;
      $score_b = $scores[$b] ?? 0.0;

      if ($score_a === $score_b) {
        return array_search($a, $panel_keys, TRUE) <=> array_search($b, $panel_keys, TRUE);
      }

      return $score_b <=> $score_a;
    });

    return $indexed;
  }

  /**
   * Returns aggregate metrics for known panels (admin insights table).
   *
   * @param array<string, array<string, mixed>> $panels
   *   Support panel definitions keyed by panel ID.
   *
   * @return array<string, array<string, float|int>>
   *   Metrics keyed by panel ID.
   */
  public function getPanelMetrics(array $panels): array {
    $panel_keys = array_values(array_map('strval', array_keys($panels)));
    if ($panel_keys === []) {
      return [];
    }

    $summary = $this->analyticsSummary->getPanelSummary($panel_keys);
    $metrics = [];

    foreach ($panel_keys as $key) {
      $row = $summary[$key] ?? NULL;
      if (!is_array($row)) {
        $metrics[$key] = [
          'impressions' => 0,
          'clicks' => 0,
          'ctr' => 0.0,
          'feedback_total' => 0,
          'helpful' => 0,
          'helpful_rate' => 0.0,
        ];
        continue;
      }

      $helpful = (int) ($row['helpful_count'] ?? 0);
      $unhelpful = (int) ($row['unhelpful_count'] ?? 0);
      $feedback_total = $helpful + $unhelpful;

      $metrics[$key] = [
        'impressions' => (int) ($row['impressions'] ?? 0),
        'clicks' => (int) ($row['clicks'] ?? 0),
        'ctr' => (float) ($row['ctr'] ?? 0.0),
        'feedback_total' => $feedback_total,
        'helpful' => $helpful,
        'helpful_rate' => (float) ($row['helpful_ratio'] ?? 0.0),
      ];
    }

    return $metrics;
  }

}
