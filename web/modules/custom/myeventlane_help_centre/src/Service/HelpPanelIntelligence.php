<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Provides basic performance scoring for support panels.
 */
final class HelpPanelIntelligence {

  public function __construct(
    private readonly Connection $database,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Orders panel keys by click-through rate, falling back to config order.
   *
   * @param array<string, array<string, mixed>> $panels
   *   Support panel definitions keyed by panel ID.
   *
   * @return array<int, string>
   *   Ordered panel keys (all keys are returned).
   */
  public function getTopPanels(array $panels): array {
    $panelKeys = array_values(array_map('strval', array_keys($panels)));
    if ($panelKeys === []) {
      return [];
    }

    $orderIndex = array_flip($panelKeys);
    $stats = $this->loadStats($panelKeys);

    $scores = [];
    foreach ($panelKeys as $key) {
      $stat = $stats[$key] ?? [
        'impressions' => 0,
        'clicks' => 0,
      ];
      $impressions = (int) $stat['impressions'];
      $clicks = (int) $stat['clicks'];
      $ctr = $impressions > 0 ? $clicks / $impressions : 0;
      $scores[$key] = $ctr;
    }

    usort($panelKeys, function (string $a, string $b) use ($scores, $orderIndex): int {
      $scoreDiff = $scores[$b] <=> $scores[$a];
      if ($scoreDiff !== 0) {
        return $scoreDiff;
      }
      return ($orderIndex[$a] ?? 0) <=> ($orderIndex[$b] ?? 0);
    });

    return $panelKeys;
  }

  /**
   * Returns aggregate metrics for known panels.
   *
   * @param array<string, array<string, mixed>> $panels
   *   Support panel definitions keyed by panel ID.
   *
   * @return array<string, array<string, float|int>>
   *   Metrics keyed by panel ID.
   */
  public function getPanelMetrics(array $panels): array {
    $panelKeys = array_values(array_map('strval', array_keys($panels)));
    if ($panelKeys === []) {
      return [];
    }

    $stats = $this->loadStats($panelKeys);
    $metrics = [];

    foreach ($panelKeys as $key) {
      $stat = $stats[$key] ?? [
        'impressions' => 0,
        'clicks' => 0,
        'feedback_total' => 0,
        'helpful' => 0,
      ];
      $impressions = (int) $stat['impressions'];
      $clicks = (int) $stat['clicks'];
      $feedbackTotal = (int) $stat['feedback_total'];
      $helpful = (int) $stat['helpful'];
      $ctr = $impressions > 0 ? $clicks / $impressions : 0;
      $helpfulRate = $feedbackTotal > 0 ? $helpful / $feedbackTotal : 0;

      $metrics[$key] = [
        'impressions' => $impressions,
        'clicks' => $clicks,
        'ctr' => $ctr,
        'feedback_total' => $feedbackTotal,
        'helpful' => $helpful,
        'helpful_rate' => $helpfulRate,
      ];
    }

    return $metrics;
  }

  /**
   * Loads aggregated stats for the requested panels.
   *
   * @param array<int, string> $panelKeys
   *
   * @return array<string, array<string, int>>
   */
  private function loadStats(array $panelKeys): array {
    $stats = [];
    if ($panelKeys === []) {
      return $stats;
    }

    try {
      $query = $this->database->select('mel_help_panel_analytics', 'a');
      $query->addField('a', 'panel_key');
      $query->addExpression("SUM(CASE WHEN event_type = 'click' THEN 1 ELSE 0 END)", 'clicks');
      $query->addExpression("SUM(CASE WHEN event_type = 'impression' THEN 1 ELSE 0 END)", 'impressions');
      $query->addExpression("SUM(CASE WHEN event_type = 'feedback' THEN 1 ELSE 0 END)", 'feedback_total');
      $query->addExpression("SUM(CASE WHEN event_type = 'feedback' AND is_helpful = 1 THEN 1 ELSE 0 END)", 'helpful');
      $query->condition('panel_key', $panelKeys, 'IN');
      $query->groupBy('panel_key');

      $results = $query->execute();
      foreach ($results as $row) {
        $key = (string) $row->panel_key;
        $stats[$key] = [
          'clicks' => (int) $row->clicks,
          'impressions' => (int) $row->impressions,
          'feedback_total' => (int) $row->feedback_total,
          'helpful' => (int) $row->helpful,
        ];
      }
    }
    catch (\Throwable $throwable) {
      $this->logger->error('Failed to load support panel analytics: @message', [
        '@message' => $throwable->getMessage(),
      ]);
    }

    return $stats;
  }

}
