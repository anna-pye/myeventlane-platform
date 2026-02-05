<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Phase7\Service;

use Drupal\Core\Database\Connection;
use Drupal\myeventlane_analytics\Phase7\Value\AnalyticsQuery;

/**
 * Admin-only revenue aggregation: ticket net (Phase 7, Boost excluded) and
 * boost net. No reuse of vendor dashboard services. For use only by the admin
 * revenue dashboard.
 *
 * @internal
 *   Admin revenue surface only. Do not use from vendor or public context.
 */
final class AdminRevenueQueryService {

  /**
   * Constructs the service.
   *
   * @param \Drupal\myeventlane_analytics\Phase7\Service\AnalyticsQueryServiceInterface $phase7Query
   *   Phase 7 query service (ticket revenue, Boost excluded).
   * @param \Drupal\Core\Database\Connection $database
   *   Database connection for boost revenue query.
   */
  public function __construct(
    private readonly AnalyticsQueryServiceInterface $phase7Query,
    private readonly Connection $database,
  ) {}

  /**
   * Returns platform revenue for the given scope and window.
   *
   * Ticket revenue from Phase 7 (Boost excluded). Boost revenue from
   * completed boost order items only. Total = ticket_net_cents + boost_net_cents.
   *
   * @param list<int> $store_ids
   *   Store IDs to include (admin scope).
   * @param int $start_ts
   *   Range start (inclusive), epoch seconds.
   * @param int $end_ts
   *   Range end (inclusive), epoch seconds.
   * @param string $currency
   *   ISO currency code.
   *
   * @return array{ ticket_net_cents: int, boost_net_cents: int, total_cents: int }
   */
  public function getPlatformRevenue(array $store_ids, int $start_ts, int $end_ts, string $currency): array {
    $ticket_net_cents = $this->getTicketNetCents($store_ids, $start_ts, $end_ts, $currency);
    $boost_net_cents = $this->getBoostNetCents($start_ts, $end_ts, $currency);
    return [
      'ticket_net_cents' => $ticket_net_cents,
      'boost_net_cents' => $boost_net_cents,
      'total_cents' => $ticket_net_cents + $boost_net_cents,
    ];
  }

  /**
   * Ticket net revenue (Phase 7, Boost excluded) in cents.
   */
  public function getTicketNetCents(array $store_ids, int $start_ts, int $end_ts, string $currency): int {
    if ($store_ids === []) {
      return 0;
    }
    $q = new AnalyticsQuery(
      scope: AnalyticsQuery::SCOPE_ADMIN,
      store_ids: $store_ids,
      start_ts: $start_ts,
      end_ts: $end_ts,
      currency: $currency,
    );
    $rows = $this->phase7Query->getNetRevenue($q);
    $sum = 0;
    foreach ($rows as $row) {
      $sum += (int) ($row->amount_cents ?? 0);
    }
    return $sum;
  }

  /**
   * Boost net revenue (admin-only): completed boost order items in window.
   *
   * Sums (unit_price * quantity) in cents for order_item.type = 'boost',
   * completed orders, placed in [start_ts, end_ts], single currency.
   */
  public function getBoostNetCents(int $start_ts, int $end_ts, string $currency): int {
    $table = 'commerce_order_item';
    if (!$this->database->schema()->tableExists($table)) {
      return 0;
    }
    if (!$this->database->schema()->tableExists('commerce_order')) {
      return 0;
    }
    $q = $this->database->select('commerce_order_item', 'oi');
    $q->join('commerce_order', 'o', 'o.order_id = oi.order_id');
    $q->addExpression('COALESCE(SUM(ROUND(oi.unit_price__number * oi.quantity * 100)), 0)', 'amount_cents');
    $q->condition('o.state', 'completed');
    $q->condition('o.placed', $start_ts, '>=');
    $q->condition('o.placed', $end_ts, '<=');
    $q->condition('oi.type', 'boost');
    $q->condition('oi.unit_price__currency_code', $currency);
    $sum = (int) $q->execute()->fetchField();
    return max(0, $sum);
  }

}
