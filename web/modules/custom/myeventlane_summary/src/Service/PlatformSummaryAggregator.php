<?php

declare(strict_types=1);

namespace Drupal\myeventlane_summary\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\myeventlane_analytics\Service\OrderItemClassifier;
use Drupal\myeventlane_core\PlatformFeeDefaults;
use Psr\Log\LoggerInterface;

/**
 * Aggregates platform metrics into platform_daily_summary.
 *
 * Runs on cron. Uses database aggregates only; never loads entities.
 * Scope: last 90 days. Only aggregates changed days.
 *
 * Revenue streams (derived from order items, not order total):
 * - vendor_ticket_revenue: Eligible ticket items (excludes donations, Boost).
 * - donation_revenue: checkout_donation, platform_donation, rsvp_donation.
 * - boost_revenue: boost order items.
 * - application_fees: Derived from vendor_ticket_revenue * fee rate.
 *
 * Platform revenue = donation_revenue + boost_revenue + application_fees.
 * Vendor revenue = vendor_ticket_revenue - refunds (not aggregated here).
 *
 * @internal
 */
final class PlatformSummaryAggregator {

  private const WINDOW_DAYS = 90;

  private const DEFAULT_CURRENCY = 'AUD';

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
    private readonly OrderItemClassifier $orderItemClassifier,
  ) {}

  /**
   * Aggregates last N days into platform_daily_summary.
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
    $feePercent = PlatformFeeDefaults::normalizePercent(
      $this->configFactory->get('myeventlane_core.settings')->get('platform_fee_percent')
    );
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
   * Aggregates Commerce order item data for one day.
   *
   * Derives revenue from order items by type (not order total).
   */
  private function aggregateDay(int $day_start, int $day_end, string $currency, float $feeRate): array {
    $vendorTicketRevenue = 0.0;
    $donationRevenue = 0.0;
    $boostRevenue = 0.0;
    $ordersCompleted = 0;
    $ordersFailed = 0;

    $excludedTypes = $this->orderItemClassifier->getExcludedTypes();
    $donationTypes = $this->orderItemClassifier->getDonationTypes();

    if (!$this->database->schema()->tableExists('commerce_order_item')) {
      return $this->emptyDayRow(0, 0, 0, $feeRate, $ordersCompleted, $ordersFailed);
    }

    // Sum order item amounts by type. Use unit_price * quantity for total.
    $oi_table = $this->database->schema()->tableExists('commerce_order_item_field_data')
      ? 'commerce_order_item_field_data'
      : 'commerce_order_item';
    $amount_expr = 'COALESCE(SUM(oi.unit_price__number * oi.quantity), 0)';

    // Vendor ticket revenue: types NOT IN excluded.
    $q_vendor = $this->database->select($oi_table, 'oi');
    $q_vendor->join('commerce_order', 'o', 'o.order_id = oi.order_id');
    $q_vendor->addExpression($amount_expr, 'revenue');
    $q_vendor->condition('o.state', 'completed');
    $q_vendor->condition('o.placed', $day_start, '>=');
    $q_vendor->condition('o.placed', $day_end, '<=');
    $q_vendor->condition('oi.type', $excludedTypes, 'NOT IN');
    if ($this->database->schema()->fieldExists($oi_table, 'unit_price__currency_code')) {
      $q_vendor->condition('oi.unit_price__currency_code', $currency);
    }
    $vendorTicketRevenue = (float) $q_vendor->execute()->fetchField();

    // Donation revenue.
    if (!empty($donationTypes)) {
      $q_donation = $this->database->select($oi_table, 'oi');
      $q_donation->join('commerce_order', 'o', 'o.order_id = oi.order_id');
      $q_donation->addExpression($amount_expr, 'revenue');
      $q_donation->condition('o.state', 'completed');
      $q_donation->condition('o.placed', $day_start, '>=');
      $q_donation->condition('o.placed', $day_end, '<=');
      $q_donation->condition('oi.type', $donationTypes, 'IN');
      if ($this->database->schema()->fieldExists($oi_table, 'unit_price__currency_code')) {
        $q_donation->condition('oi.unit_price__currency_code', $currency);
      }
      $donationRevenue = (float) $q_donation->execute()->fetchField();
    }

    // Boost revenue.
    $q_boost = $this->database->select($oi_table, 'oi');
    $q_boost->join('commerce_order', 'o', 'o.order_id = oi.order_id');
    $q_boost->addExpression($amount_expr, 'revenue');
    $q_boost->condition('o.state', 'completed');
    $q_boost->condition('o.placed', $day_start, '>=');
    $q_boost->condition('o.placed', $day_end, '<=');
    $q_boost->condition('oi.type', 'boost');
    if ($this->database->schema()->fieldExists($oi_table, 'unit_price__currency_code')) {
      $q_boost->condition('oi.unit_price__currency_code', $currency);
    }
    $boostRevenue = (float) $q_boost->execute()->fetchField();

    // Order count (completed).
    $q_orders = $this->database->select('commerce_order', 'o');
    $q_orders->addExpression('COUNT(o.order_id)', 'cnt');
    $q_orders->condition('o.state', 'completed');
    $q_orders->condition('o.placed', $day_start, '>=');
    $q_orders->condition('o.placed', $day_end, '<=');
    $q_orders->condition('o.total_price__currency_code', $currency);
    $ordersCompleted = (int) $q_orders->execute()->fetchField();

    // Application fees on vendor ticket revenue.
    $platformFees = round($vendorTicketRevenue * $feeRate, 2);
    $revenueNet = round($vendorTicketRevenue - $platformFees, 2);
    $revenueGross = round($vendorTicketRevenue + $donationRevenue + $boostRevenue, 2);

    // Failed/canceled orders.
    $q_failed = $this->database->select('commerce_order', 'o');
    $q_failed->addExpression('COUNT(o.order_id)', 'cnt');
    $q_failed->condition('o.state', ['canceled', 'cancelled'], 'IN');
    $q_failed->condition('o.placed', $day_start, '>=');
    $q_failed->condition('o.placed', $day_end, '<=');
    $resultFailed = $q_failed->execute()->fetchObject();
    if ($resultFailed) {
      $ordersFailed = (int) $resultFailed->cnt;
    }

    return [
      'revenue_gross' => $revenueGross,
      'revenue_net' => $revenueNet,
      'platform_fees' => $platformFees,
      'vendor_ticket_revenue' => round($vendorTicketRevenue, 2),
      'donation_revenue' => round($donationRevenue, 2),
      'boost_revenue' => round($boostRevenue, 2),
      'orders_completed' => $ordersCompleted,
      'orders_failed' => $ordersFailed,
    ];
  }

  /**
   * Returns empty day row structure.
   */
  private function emptyDayRow(float $vendor, float $donation, float $boost, float $feeRate, int $ordersCompleted, int $ordersFailed): array {
    $platformFees = round($vendor * $feeRate, 2);
    $revenueNet = round($vendor - $platformFees, 2);
    $revenueGross = round($vendor + $donation + $boost, 2);

    return [
      'revenue_gross' => $revenueGross,
      'revenue_net' => $revenueNet,
      'platform_fees' => $platformFees,
      'vendor_ticket_revenue' => round($vendor, 2),
      'donation_revenue' => round($donation, 2),
      'boost_revenue' => round($boost, 2),
      'orders_completed' => $ordersCompleted,
      'orders_failed' => $ordersFailed,
    ];
  }

  /**
   * Counts open escalations.
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
    $fields = [
      'revenue_gross' => $row['revenue_gross'],
      'revenue_net' => $row['revenue_net'],
      'platform_fees' => $row['platform_fees'],
      'orders_completed' => $row['orders_completed'],
      'orders_failed' => $row['orders_failed'],
      'escalations_open' => $row['escalations_open'],
      'escalations_urgent' => $row['escalations_urgent'],
    ];

    if ($this->database->schema()->fieldExists('platform_daily_summary', 'vendor_ticket_revenue')) {
      $fields['vendor_ticket_revenue'] = $row['vendor_ticket_revenue'];
      $fields['donation_revenue'] = $row['donation_revenue'];
      $fields['boost_revenue'] = $row['boost_revenue'];
    }

    $this->database->merge('platform_daily_summary')
      ->keys(['date' => $date])
      ->fields($fields)
      ->execute();
  }

  /**
   * Returns default currency from first store or constant.
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
