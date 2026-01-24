<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Service;

use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;
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
      $processedIds[] = $row->id;
      $date = gmdate('Y-m-d', (int) $row->occurred_at);
      $key = $row->boost_order_item_id . '|' . $row->placement . '|' . $date;
      if (!isset($aggregated[$key])) {
        $aggregated[$key] = [
          'boost_order_item_id' => (int) $row->boost_order_item_id,
          'event_id' => (int) $row->event_id,
          'placement' => $row->placement,
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

    $created = $now;
    foreach ($aggregated as $agg) {
      $merge = $this->database->merge('myeventlane_boost_stats_daily')
        ->key([
          'boost_order_item_id' => $agg['boost_order_item_id'],
          'placement' => $agg['placement'],
          'date' => $agg['date'],
        ])
        ->insertFields([
          'event_id' => $agg['event_id'],
          'impressions' => $agg['impressions'],
          'clicks' => $agg['clicks'],
          'created' => $created,
        ])
        ->updateFields(['event_id' => $agg['event_id']]);
      $merge->expression('impressions', 'impressions + :i', [':i' => $agg['impressions']]);
      $merge->expression('clicks', 'clicks + :c', [':c' => $agg['clicks']]);
      $merge->execute();
    }

    $this->database->delete('myeventlane_boost_stats_log')
      ->condition('id', $processedIds, 'IN')
      ->execute();

    $this->logger->info('Boost daily rollup: processed @n log rows into @d daily aggregates.', [
      '@n' => count($processedIds),
      '@d' => count($aggregated),
    ]);
  }

}
