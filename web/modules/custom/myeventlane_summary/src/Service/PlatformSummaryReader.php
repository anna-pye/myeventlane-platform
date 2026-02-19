<?php

declare(strict_types=1);

namespace Drupal\myeventlane_summary\Service;

use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Reads from platform_daily_summary table.
 *
 * Dashboard services use this to avoid any Commerce order queries.
 *
 * @internal
 */
final class PlatformSummaryReader {

  /**
   * Constructs the reader.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Checks if the summary table has any data.
   */
  public function hasData(): bool {
    if (!$this->database->schema()->tableExists('platform_daily_summary')) {
      return FALSE;
    }
    $count = (int) $this->database->select('platform_daily_summary', 'p')
      ->addExpression('COUNT(*)', 'cnt')
      ->execute()
      ->fetchField();
    return $count > 0;
  }

  /**
   * Returns aggregated totals for the last N days.
   *
   * @param int $days
   *   Number of days (e.g. 30).
   *
   * @return array{
   *   revenue_gross: float,
   *   revenue_net: float,
   *   platform_fees: float,
   *   orders_completed: int,
   *   orders_failed: int,
   *   escalations_open: int,
   *   escalations_urgent: int
   * }|null
   *   Row of totals, or NULL if no data.
   */
  public function getTotalsLastNDays(int $days): ?array {
    if (!$this->database->schema()->tableExists('platform_daily_summary')) {
      $this->logger->warning('platform_daily_summary table missing.');
      return NULL;
    }

    $cutoff = date('Y-m-d', strtotime("-{$days} days"));

    $q = $this->database->select('platform_daily_summary', 'p');
    $q->addExpression('COALESCE(SUM(p.revenue_gross), 0)', 'revenue_gross');
    $q->addExpression('COALESCE(SUM(p.revenue_net), 0)', 'revenue_net');
    $q->addExpression('COALESCE(SUM(p.platform_fees), 0)', 'platform_fees');
    $q->addExpression('COALESCE(SUM(p.orders_completed), 0)', 'orders_completed');
    $q->addExpression('COALESCE(SUM(p.orders_failed), 0)', 'orders_failed');
    if ($this->database->schema()->fieldExists('platform_daily_summary', 'vendor_ticket_revenue')) {
      $q->addExpression('COALESCE(SUM(p.vendor_ticket_revenue), 0)', 'vendor_ticket_revenue');
      $q->addExpression('COALESCE(SUM(p.donation_revenue), 0)', 'donation_revenue');
      $q->addExpression('COALESCE(SUM(p.boost_revenue), 0)', 'boost_revenue');
    }
    $q->condition('p.date', $cutoff, '>=');

    $row = $q->execute()->fetchAssoc();
    if (!$row) {
      return NULL;
    }

    $out = [
      'revenue_gross' => (float) $row['revenue_gross'],
      'revenue_net' => (float) $row['revenue_net'],
      'platform_fees' => (float) $row['platform_fees'],
      'orders_completed' => (int) $row['orders_completed'],
      'orders_failed' => (int) $row['orders_failed'],
      'escalations_open' => 0,
      'escalations_urgent' => 0,
    ];

    if ($this->database->schema()->fieldExists('platform_daily_summary', 'vendor_ticket_revenue')) {
      $donation = (float) ($row['donation_revenue'] ?? 0);
      $boost = (float) ($row['boost_revenue'] ?? 0);
      $fees = (float) $row['platform_fees'];
      $out['vendor_ticket_revenue'] = (float) ($row['vendor_ticket_revenue'] ?? 0);
      $out['donation_revenue'] = $donation;
      $out['boost_revenue'] = $boost;
      $out['platform_revenue'] = $donation + $boost + $fees;
    }

    // Open/urgent are stored only for "today"; get from most recent row.
    $latest = $this->database->select('platform_daily_summary', 'p')
      ->fields('p', ['escalations_open', 'escalations_urgent'])
      ->orderBy('p.date', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    if ($latest) {
      $out['escalations_open'] = (int) $latest['escalations_open'];
      $out['escalations_urgent'] = (int) $latest['escalations_urgent'];
    }

    return $out;
  }

  /**
   * Returns monthly revenue for the last 6 months.
   *
   * @return array<int, array{month: string, revenue: float}>
   */
  public function getMonthlyRevenueLast6Months(): array {
    if (!$this->database->schema()->tableExists('platform_daily_summary')) {
      return [];
    }

    $cutoff = date('Y-m-01', strtotime('-5 months'));

    $q = $this->database->select('platform_daily_summary', 'p');
    $q->addExpression('SUBSTR(p.date, 1, 7)', 'month');
    $q->addExpression('COALESCE(SUM(p.revenue_gross), 0)', 'revenue');
    $q->condition('p.date', $cutoff, '>=');
    $q->groupBy('month');
    $q->orderBy('month', 'ASC');

    $rows = $q->execute()->fetchAll(\PDO::FETCH_ASSOC);

    $by_month = [];
    foreach ($rows as $r) {
      $by_month[$r['month']] = (float) $r['revenue'];
    }

    $result = [];
    for ($i = 5; $i >= 0; $i--) {
      $ts = strtotime("-{$i} months");
      $month_key = date('Y-m', $ts);
      $result[] = [
        'month' => date('M Y', $ts),
        'revenue' => $by_month[$month_key] ?? 0.0,
      ];
    }
    return $result;
  }

}
