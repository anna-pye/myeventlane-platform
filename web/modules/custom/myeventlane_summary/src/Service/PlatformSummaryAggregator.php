<?php

declare(strict_types=1);

namespace Drupal\myeventlane_summary\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Aggregates platform metrics into platform_daily_summary.
 *
 * Runs on cron. Uses database aggregates only; never loads entities.
 * Scope: last 90 days. Only aggregates changed days.
 *
 * @internal
 */
final class PlatformSummaryAggregator {

  private const WINDOW_DAYS = 90;

  private const DEFAULT_CURRENCY = 'AUD';

  private const DEFAULT_FEE_PERCENT = 5.0;

  /**
   * Resolved escalation statuses (not open).
   */
  private const RESOLVED_STATUSES = ['resolved', 'closed'];

  /**
   * Constructs the aggregator.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly CacheBackendInterface $cache,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Aggregates last N days into platform_daily_summary.
   *
   * Uses commerce_order and escalation tables via SQL. No entity loading.
   */
  public function aggregate(): void {
    if (!$this->database->schema()->tableExists('commerce_order')) {
      $this->logger->warning('Commerce order table missing; skipping aggregation.');
      return;
    }

    if (!$this->database->schema()->tableExists('platform_daily_summary')) {
      $this->logger->warning('platform_daily_summary table missing; skipping aggregation.');
      return;
    }

    $currency = $this->getDefaultCurrency();
    $feePercent = (float) ($this->configFactory->get('myeventlane_core.settings')->get('platform_fee_percent') ?? self::DEFAULT_FEE_PERCENT);
    $feeRate = $feePercent / 100;

    $end_day = (int) strtotime('today midnight');
    $start_day = $end_day - (self::WINDOW_DAYS * 86400);

    for ($ts = $start_day; $ts <= $end_day; $ts += 86400) {
      $date = date('Y-m-d', $ts);
      $day_start = $ts;
      $day_end = $ts + 86400 - 1;

      $row = $this->aggregateDay($day_start, $day_end, $currency, $feeRate);
      $row['escalations_open'] = 0;
      $row['escalations_urgent'] = 0;

      // Escalation counts: only meaningful for "today" (current snapshot).
      if ($date === date('Y-m-d')) {
        $row['escalations_open'] = $this->getOpenEscalationCount();
        $row['escalations_urgent'] = $this->getUrgentEscalationCount();
      }

      $this->upsertDay($date, $row);
    }

    $this->populateRecentOrdersCache();

    $this->cacheTagsInvalidator->invalidateTags([
      'platform:summary',
      'escalation_list',
      'commerce_order_list',
    ]);
  }

  /**
   * Populates cache with 5 most recent completed orders (cron only).
   */
  private function populateRecentOrdersCache(): void {
    if (!$this->database->schema()->tableExists('commerce_order')) {
      return;
    }

    $q = $this->database->select('commerce_order', 'o');
    $q->fields('o', ['order_id', 'order_number', 'total_price__number', 'placed']);
    $q->condition('o.state', 'completed');
    $q->orderBy('o.placed', 'DESC');
    $q->range(0, 5);

    $rows = $q->execute()->fetchAll(\PDO::FETCH_ASSOC);
    $data = [];
    foreach ($rows as $r) {
      $data[] = [
        'order_id' => (int) $r['order_id'],
        'order_number' => $r['order_number'] ?? '',
        'amount' => (float) ($r['total_price__number'] ?? 0),
        'placed' => (int) ($r['placed'] ?? 0),
      ];
    }

    $this->cache->set('platform_recent_orders', $data, strtotime('+2 minutes'), ['commerce_order_list']);
  }

  /**
   * Aggregates Commerce order data for one day.
   *
   * Uses placed timestamp for date grouping. No entity loading.
   */
  private function aggregateDay(int $day_start, int $day_end, string $currency, float $feeRate): array {
    $revenue_gross = 0.0;
    $orders_completed = 0;
    $orders_failed = 0;

    // Completed orders: sum total_price, count.
    $q_completed = $this->database->select('commerce_order', 'o');
    $q_completed->addExpression('COALESCE(SUM(o.total_price__number), 0)', 'revenue');
    $q_completed->addExpression('COUNT(o.order_id)', 'cnt');
    $q_completed->condition('o.state', 'completed');
    $q_completed->condition('o.placed', $day_start, '>=');
    $q_completed->condition('o.placed', $day_end, '<=');
    $q_completed->condition('o.total_price__currency_code', $currency);

    $result = $q_completed->execute()->fetchObject();
    if ($result) {
      $revenue_gross = (float) $result->revenue;
      $orders_completed = (int) $result->cnt;
    }

    $platform_fees = round($revenue_gross * $feeRate, 2);
    $revenue_net = round($revenue_gross - $platform_fees, 2);

    // Failed/canceled orders. Commerce uses 'canceled' (US spelling).
    $q_failed = $this->database->select('commerce_order', 'o');
    $q_failed->addExpression('COUNT(o.order_id)', 'cnt');
    $q_failed->condition('o.state', ['canceled', 'cancelled'], 'IN');
    $q_failed->condition('o.placed', $day_start, '>=');
    $q_failed->condition('o.placed', $day_end, '<=');

    $result_failed = $q_failed->execute()->fetchObject();
    if ($result_failed) {
      $orders_failed = (int) $result_failed->cnt;
    }

    return [
      'revenue_gross' => $revenue_gross,
      'revenue_net' => $revenue_net,
      'platform_fees' => $platform_fees,
      'orders_completed' => $orders_completed,
      'orders_failed' => $orders_failed,
    ];
  }

  /**
   * Counts open escalations (status not resolved/closed).
   */
  private function getOpenEscalationCount(): int {
    if (!$this->database->schema()->tableExists('escalation')) {
      return 0;
    }
    $q = $this->database->select('escalation', 'e');
    $q->addExpression('COUNT(e.id)', 'cnt');
    $q->condition('e.status', self::RESOLVED_STATUSES, 'NOT IN');
    return (int) $q->execute()->fetchField();
  }

  /**
   * Counts urgent-priority open escalations.
   */
  private function getUrgentEscalationCount(): int {
    if (!$this->database->schema()->tableExists('escalation')) {
      return 0;
    }
    $q = $this->database->select('escalation', 'e');
    $q->addExpression('COUNT(e.id)', 'cnt');
    $q->condition('e.status', self::RESOLVED_STATUSES, 'NOT IN');
    $q->condition('e.priority', 'urgent');
    return (int) $q->execute()->fetchField();
  }

  /**
   * Upserts one day into platform_daily_summary.
   */
  private function upsertDay(string $date, array $row): void {
    $this->database->merge('platform_daily_summary')
      ->keys(['date' => $date])
      ->fields([
        'revenue_gross' => $row['revenue_gross'],
        'revenue_net' => $row['revenue_net'],
        'platform_fees' => $row['platform_fees'],
        'orders_completed' => $row['orders_completed'],
        'orders_failed' => $row['orders_failed'],
        'escalations_open' => $row['escalations_open'],
        'escalations_urgent' => $row['escalations_urgent'],
      ])
      ->execute();
  }

  /**
   * Returns default currency from first store or constant.
   *
   * Commerce store uses default_currency_target_id (entity reference to currency).
   */
  private function getDefaultCurrency(): string {
    if (!$this->database->schema()->tableExists('commerce_store')) {
      return self::DEFAULT_CURRENCY;
    }
    $column = 'default_currency_target_id';
    if (!$this->database->schema()->fieldExists('commerce_store', $column)) {
      return self::DEFAULT_CURRENCY;
    }
    $currency = $this->database->select('commerce_store', 's')
      ->fields('s', [$column])
      ->range(0, 1)
      ->execute()
      ->fetchField();
    return $currency ?: self::DEFAULT_CURRENCY;
  }

}
