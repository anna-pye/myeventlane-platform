<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Nightly rollup of Boost stats log into daily aggregates.
 *
 * Idempotent: safe to re-run. Uses UTC for date boundaries.
 * Processes log rows up to end of yesterday, merges into daily, then deletes processed rows.
 */
final class BoostDailyRollupService {

  /**
   * Constructs the service.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Runs the daily rollup.
   *
   * Processes all log rows with occurred_at before today (UTC), aggregates
   * by (date, boost_order_item_id, placement, event_id), merges into
   * myeventlane_boost_stats_daily, then deletes processed log rows.
   */
  public function run(): void {
    $schema = $this->database->schema();
    if (!$schema->tableExists('myeventlane_boost_stats_log') || !$schema->tableExists('myeventlane_boost_stats_daily')) {
      $this->logger->debug('Boost daily rollup: log or daily table missing, skip.');
      return;
    }

    $now = $this->time->getRequestTime();
    $todayStart = gmmktime(0, 0, 0, (int) gmdate('n', $now), (int) gmdate('j', $now), (int) gmdate('Y', $now));

    $logIds = $this->database->select('myeventlane_boost_stats_log', 'l')
      ->fields('l', ['id', 'boost_order_item_id', 'event_id', 'placement', 'kind', 'occurred_at'])
      ->condition('l.occurred_at', $todayStart, '<')
      ->execute()
      ->fetchAll();

    if (empty($logIds)) {
      $this->logger->debug('Boost daily rollup: no log rows to process.');
      return;
    }

    $aggregated = [];
    $processedIds = [];

    foreach ($logIds as $row) {
      $boostOrderItemId = (int) ($row->boost_order_item_id ?? 0);
      $eventId = (int) ($row->event_id ?? 0);
      $placement = trim((string) ($row->placement ?? ''));
      $date = gmdate('Y-m-d', (int) ($row->occurred_at ?? 0));

      if (!$this->isValidRollupRow($boostOrderItemId, $eventId, $placement, $date)) {
        $this->logger->notice('Boost daily rollup: skipped invalid stats log row @id.', [
          '@id' => (int) ($row->id ?? 0),
          'boost_order_item_id' => $boostOrderItemId,
          'event_id' => $eventId,
          'placement' => $placement,
          'date' => $date,
        ]);
        continue;
      }

      $processedIds[] = $row->id;
      $key = $boostOrderItemId . '|' . $placement . '|' . $date;
      if (!isset($aggregated[$key])) {
        $aggregated[$key] = [
          'boost_order_item_id' => $boostOrderItemId,
          'event_id' => $eventId,
          'placement' => $placement,
          'date' => $date,
          'impressions' => 0,
          'clicks' => 0,
        ];
      }
      if ($row->kind === 'impression') {
        $aggregated[$key]['impressions']++;
      }
      else {
        $aggregated[$key]['clicks']++;
      }
    }

    $mergedCount = 0;
    foreach ($aggregated as $agg) {
      $boostOrderItemId = (int) ($agg['boost_order_item_id'] ?? 0);
      $eventId = (int) ($agg['event_id'] ?? 0);
      $placement = (string) ($agg['placement'] ?? '');
      $date = (string) ($agg['date'] ?? '');

      if (!$this->isValidRollupRow($boostOrderItemId, $eventId, $placement, $date)) {
        $this->logger->notice('Boost daily rollup: skipped invalid grouped rollup row.', [
          'boost_order_item_id' => $boostOrderItemId,
          'event_id' => $eventId,
          'placement' => $placement,
          'date' => $date,
        ]);
        continue;
      }

      try {
        $merge = $this->database->merge('myeventlane_boost_stats_daily')
          ->keys([
            'date' => $date,
            'boost_order_item_id' => $boostOrderItemId,
            'event_id' => $eventId,
            'placement' => $placement,
          ])
          ->insertFields([
            'date' => $date,
            'boost_order_item_id' => $boostOrderItemId,
            'event_id' => $eventId,
            'placement' => $placement,
            'created' => $now,
            'impressions' => (int) $agg['impressions'],
            'clicks' => (int) $agg['clicks'],
          ])
          ->updateFields([
            'event_id' => $eventId,
          ]);
        $merge->expression('impressions', 'impressions + :i', [':i' => (int) $agg['impressions']]);
        $merge->expression('clicks', 'clicks + :c', [':c' => (int) $agg['clicks']]);
        $merge->execute();
        $mergedCount++;
      }
      catch (\Exception $e) {
        $this->logger->notice('Boost daily rollup: skipped grouped rollup row after merge failure.', [
          'boost_order_item_id' => $boostOrderItemId,
          'event_id' => $eventId,
          'placement' => $placement,
          'date' => $date,
          'error' => $e->getMessage(),
        ]);
      }
    }

    if ($processedIds !== []) {
      $this->database->delete('myeventlane_boost_stats_log')
        ->condition('id', $processedIds, 'IN')
        ->execute();
    }

    $this->logger->info('Boost daily rollup: processed @n log rows into @d daily aggregates.', [
      '@n' => count($processedIds),
      '@d' => $mergedCount,
    ]);
  }

  /**
   * Validates required rollup dimensions.
   */
  private function isValidRollupRow(int $boostOrderItemId, int $eventId, string $placement, string $date): bool {
    if ($boostOrderItemId <= 0 || $eventId <= 0 || $placement === '') {
      return FALSE;
    }

    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
  }

}
