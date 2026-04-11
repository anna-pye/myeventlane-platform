<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Aggregates support panel analytics in a single, cached query.
 */
final class HelpAnalyticsSummary {

  private const CACHE_TTL = 300;

  public function __construct(
    private readonly Connection $database,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Returns summary metrics for the given panel keys.
   *
   * @param array<int, string> $panelKeys
   *   Panel identifiers (config keys).
   *
   * @return array<string, array<string, float|int>>
   *   Metrics keyed by panel key.
   */
  public function getPanelSummary(array $panelKeys): array {
    $keys = array_values(array_filter(array_map('strval', $panelKeys), static fn (string $k): bool => $k !== ''));
    if ($keys === []) {
      return [];
    }

    sort($keys, SORT_STRING);
    $cid = 'myeventlane_help_centre:panel_summary:' . hash('sha256', implode('|', $keys));

    if ($cache = $this->cache->get($cid)) {
      $data = $cache->data;
      if (is_array($data)) {
        return $data;
      }
    }

    $summary = $this->loadPanelSummary($keys);
    $this->cache->set($cid, $summary, $this->time->getRequestTime() + self::CACHE_TTL, ['myeventlane_help_panel_analytics']);

    return $summary;
  }

  /**
   * Backwards-compatible alias for getPanelSummary().
   *
   * @param array<int, string> $panelKeys
   *
   * @return array<string, array<string, float|int>>
   */
  public function getSummary(array $panelKeys = []): array {
    return $this->getPanelSummary($panelKeys);
  }

  /**
   * Executes the aggregate query for the requested panels.
   *
   * @param array<int, string> $panelKeys
   *
   * @return array<string, array<string, float|int>>
   */
  private function loadPanelSummary(array $panelKeys): array {
    $metrics = [];
    try {
      $query = $this->database->select('mel_help_panel_analytics', 'a');
      $query->addField('a', 'panel_key');
      $query->addExpression("SUM(CASE WHEN a.event_type = 'impression' THEN 1 ELSE 0 END)", 'impressions');
      $query->addExpression("SUM(CASE WHEN a.event_type = 'click' THEN 1 ELSE 0 END)", 'clicks');
      $query->addExpression("SUM(CASE WHEN a.event_type = 'feedback' THEN 1 ELSE 0 END)", 'feedback_total');
      $query->addExpression("SUM(CASE WHEN a.event_type = 'feedback' AND a.is_helpful = 1 THEN 1 ELSE 0 END)", 'helpful');
      $query->addExpression("SUM(CASE WHEN a.event_type = 'feedback' AND a.is_helpful = 0 THEN 1 ELSE 0 END)", 'unhelpful');
      $query->condition('a.panel_key', $panelKeys, 'IN');
      $query->groupBy('a.panel_key');

      foreach ($query->execute() as $row) {
        $key = (string) $row->panel_key;
        $impressions = (int) $row->impressions;
        $clicks = (int) $row->clicks;
        $feedbackTotal = (int) $row->feedback_total;
        $helpful = (int) $row->helpful;
        $unhelpful = (int) $row->unhelpful;

        $metrics[$key] = [
          'impressions' => $impressions,
          'clicks' => $clicks,
          'ctr' => $impressions > 0 ? $clicks / $impressions : 0.0,
          'helpful_count' => $helpful,
          'unhelpful_count' => $unhelpful,
          'helpful_ratio' => ($helpful + $unhelpful) > 0 ? $helpful / ($helpful + $unhelpful) : 0.0,
        ];
      }
    }
    catch (\Throwable $throwable) {
      $this->logger->error('Failed to aggregate help panel analytics: @message', [
        '@message' => $throwable->getMessage(),
      ]);
    }

    return $metrics;
  }

}
