<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\myeventlane_commerce\Service\OrderItemClassifier;
use Psr\Log\LoggerInterface;

/**
 * Platform-level metrics: KPIs, revenue series, vendor ranking, payout liability.
 *
 * Commission is computed from config (commission_rate); ledger stores gross,
 * commission, net per order. Ledger rows are auto-created when getKpis runs,
 * but only for payout-eligible vendor revenue (CF-007 remediation).
 */
final class PlatformMetricsService {

  private const CACHE_MAX_AGE = 300;

  private const CACHE_TAGS = [
    'commerce_order_list',
    'config:myeventlane_admin_dashboard.settings',
    'myeventlane_payout_ledger',
  ];

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly CacheBackendInterface $cache,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly OrderItemClassifier $orderItemClassifier,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns KPI tile data for the last N days.
   *
   * Ensures ledger row exists for each completed payout-eligible order
   * (inserts unpaid if missing).
   *
   * @return array{
   *   total_orders: int,
   *   gross_total: float,
   *   commission_total: float,
   *   net_total: float,
   *   unpaid_total: float,
   *   paid_total: float
   * }
   */
  public function getKpis(int $days = 30): array {
    $cid = 'myeventlane_admin_dashboard:metrics:kpis:' . $days;
    $cached = $this->cache->get($cid);
    if ($cached !== FALSE && is_array($cached->data)) {
      return $cached->data;
    }

    $data = $this->buildKpis($days);
    $this->cache->set($cid, $data, $this->time->getRequestTime() + self::CACHE_MAX_AGE, self::CACHE_TAGS);

    return $data;
  }

  /**
   * Returns daily revenue series for chart (labels, gross, commission, net).
   *
   * @return array{
   *   labels: array<string>,
   *   gross: array<float>,
   *   commission: array<float>,
   *   net: array<float>
   * }
   */
  public function getRevenueSeriesDaily(int $days = 30): array {
    $cid = 'myeventlane_admin_dashboard:metrics:series:' . $days;
    $cached = $this->cache->get($cid);
    if ($cached !== FALSE && is_array($cached->data)) {
      return $cached->data;
    }

    $data = $this->buildRevenueSeriesDaily($days);
    $this->cache->set($cid, $data, $this->time->getRequestTime() + self::CACHE_MAX_AGE, self::CACHE_TAGS);

    return $data;
  }

  /**
   * Returns vendor ranking by store (orders, gross, commission, net, unpaid).
   *
   * @return array<int, array{
   *   store_id: int,
   *   store_label: string,
   *   orders: int,
   *   gross: float,
   *   commission: float,
   *   net: float,
   *   unpaid_liability: float
   * }>
   */
  public function getVendorRanking(int $days = 30, int $limit = 10): array {
    $cid = 'myeventlane_admin_dashboard:metrics:ranking:' . $days . ':' . $limit;
    $cached = $this->cache->get($cid);
    if ($cached !== FALSE && is_array($cached->data)) {
      return $cached->data;
    }

    $data = $this->buildVendorRanking($days, $limit);
    $this->cache->set($cid, $data, $this->time->getRequestTime() + self::CACHE_MAX_AGE, self::CACHE_TAGS);

    return $data;
  }

  /**
   * Returns payout liability summary (unpaid/paid totals from ledger).
   *
   * @return array{
   *   unpaid_total: float,
   *   paid_total: float,
   *   unpaid_by_store: array<int, array{store_id: int, store_label: string, unpaid: float}>
   * }
   */
  public function getPayoutLiabilitySummary(int $days = 90): array {
    $cid = 'myeventlane_admin_dashboard:metrics:liability:' . $days;
    $cached = $this->cache->get($cid);
    if ($cached !== FALSE && is_array($cached->data)) {
      return $cached->data;
    }

    $data = $this->buildPayoutLiabilitySummary($days);
    $this->cache->set($cid, $data, $this->time->getRequestTime() + self::CACHE_MAX_AGE, self::CACHE_TAGS);

    return $data;
  }

  /**
   * Marks an order as paid in the payout ledger.
   */
  public function markPaid(int $orderId, ?string $transferId = NULL): void {
    if (!$this->database->schema()->tableExists('myeventlane_payout_ledger')) {
      return;
    }

    $this->database->update('myeventlane_payout_ledger')
      ->fields([
        'status' => 'paid',
        'transfer_id' => $transferId,
        'paid_at' => $this->time->getRequestTime(),
      ])
      ->condition('order_id', $orderId)
      ->execute();

    $this->cache->invalidateTags(self::CACHE_TAGS);
  }

  /**
   * Builds KPI data. Ensures ledger rows exist for payout-eligible orders.
   */
  private function buildKpis(int $days): array {
    if (!$this->database->schema()->tableExists('commerce_order')) {
      return $this->emptyKpis();
    }

    $schema = $this->database->schema();
    if (!$schema->tableExists('myeventlane_payout_ledger')) {
      return $this->emptyKpis();
    }

    $commissionRate = (float) ($this->configFactory->get('myeventlane_admin_dashboard.settings')->get('commission_rate') ?? 0.10);
    $cutoff = strtotime("-{$days} days midnight");
    $now = $this->time->getRequestTime();

    // Fetch completed orders in period.
    $q = $this->database->select('commerce_order', 'o');
    $q->fields('o', ['order_id', 'store_id', 'total_price__number', 'placed']);
    $q->condition('o.state', 'completed');
    $q->condition('o.placed', $cutoff, '>=');
    $q->condition('o.placed', $now, '<=');
    $orders = $q->execute()->fetchAll(\PDO::FETCH_ASSOC);

    $orderIds = [];
    foreach ($orders as $o) {
      $orderIds[] = (int) ($o['order_id'] ?? 0);
    }
    if (empty($orderIds)) {
      return $this->emptyKpis();
    }

    // Existing ledger order_ids.
    $existing = $this->database->select('myeventlane_payout_ledger', 'l')
      ->fields('l', ['order_id'])
      ->condition('l.order_id', $orderIds, 'IN')
      ->execute()
      ->fetchCol();
    $existing = array_map('intval', $existing);

    $orderStorage = $this->entityTypeManager->getStorage('commerce_order');

    // Auto-create ledger rows for payout-eligible orders without one.
    foreach ($orders as $o) {
      $orderId = (int) ($o['order_id'] ?? 0);
      if ($orderId <= 0 || in_array($orderId, $existing, TRUE)) {
        continue;
      }

      $order = $orderStorage->load($orderId);
      if (!$order instanceof OrderInterface) {
        $this->logger->error('Payout ledger skip: commerce order @oid could not be loaded.', [
          '@oid' => (string) $orderId,
        ]);
        continue;
      }

      if (!$this->orderItemClassifier->isPayoutLedgerEligibleOrder($order)) {
        $this->logger->info('Payout ledger skip: order @oid type=@type is not vendor-payable revenue.', [
          '@oid' => (string) $orderId,
          '@type' => $order->bundle(),
        ]);
        continue;
      }

      $storeId = (int) ($o['store_id'] ?? 0);
      $gross = round((float) ($o['total_price__number'] ?? 0), 2);
      $commission = round($gross * $commissionRate, 2);
      $net = round($gross - $commission, 2);
      $created = (int) ($o['placed'] ?? $now);
      $this->database->insert('myeventlane_payout_ledger')
        ->fields([
          'order_id' => $orderId,
          'store_id' => $storeId,
          'gross' => $gross,
          'commission' => $commission,
          'net' => $net,
          'status' => 'unpaid',
          'created' => $created,
        ])
        ->execute();
      $existing[] = $orderId;
    }

    // Aggregate from ledger for orders in period.
    $q = $this->database->select('myeventlane_payout_ledger', 'l');
    $q->addExpression('COUNT(l.id)', 'total_orders');
    $q->addExpression('COALESCE(SUM(l.gross), 0)', 'gross_total');
    $q->addExpression('COALESCE(SUM(l.commission), 0)', 'commission_total');
    $q->addExpression('COALESCE(SUM(l.net), 0)', 'net_total');
    $q->condition('l.order_id', $orderIds, 'IN');
    $row = $q->execute()->fetchAssoc();

    $unpaidQ = $this->database->select('myeventlane_payout_ledger', 'l');
    $unpaidQ->addExpression('COALESCE(SUM(l.net), 0)', 'unpaid_total');
    $unpaidQ->condition('l.order_id', $orderIds, 'IN');
    $unpaidQ->condition('l.status', 'unpaid');
    $unpaidTotal = (float) ($unpaidQ->execute()->fetchField() ?: 0);

    $paidQ = $this->database->select('myeventlane_payout_ledger', 'l');
    $paidQ->addExpression('COALESCE(SUM(l.net), 0)', 'paid_total');
    $paidQ->condition('l.order_id', $orderIds, 'IN');
    $paidQ->condition('l.status', 'paid');
    $paidTotal = (float) ($paidQ->execute()->fetchField() ?: 0);

    return [
      'total_orders' => (int) ($row['total_orders'] ?? 0),
      'gross_total' => round((float) ($row['gross_total'] ?? 0), 2),
      'commission_total' => round((float) ($row['commission_total'] ?? 0), 2),
      'net_total' => round((float) ($row['net_total'] ?? 0), 2),
      'unpaid_total' => round($unpaidTotal, 2),
      'paid_total' => round($paidTotal, 2),
    ];
  }

  /**
   * Builds daily revenue series. Group by DATE(placed).
   */
  private function buildRevenueSeriesDaily(int $days): array {
    if (!$this->database->schema()->tableExists('commerce_order')) {
      return ['labels' => [], 'gross' => [], 'commission' => [], 'net' => []];
    }

    $schema = $this->database->schema();
    if (!$schema->tableExists('myeventlane_payout_ledger')) {
      return ['labels' => [], 'gross' => [], 'commission' => [], 'net' => []];
    }

    $commissionRate = (float) ($this->configFactory->get('myeventlane_admin_dashboard.settings')->get('commission_rate') ?? 0.10);
    $cutoff = strtotime("-{$days} days midnight");
    $now = $this->time->getRequestTime();

    $connection = $this->database;
    $dateExpr = "DATE(FROM_UNIXTIME(o.placed))";
    if ($connection->driver() === 'sqlite') {
      $dateExpr = "date(o.placed, 'unixepoch')";
    }

    $q = $this->database->select('commerce_order', 'o');
    $q->addExpression($dateExpr, 'day');
    $q->addExpression('COALESCE(SUM(o.total_price__number), 0)', 'gross');
    $q->condition('o.state', 'completed');
    $q->condition('o.placed', $cutoff, '>=');
    $q->condition('o.placed', $now, '<=');
    $q->groupBy('day');
    $q->orderBy('day', 'ASC');
    $rows = $q->execute()->fetchAll(\PDO::FETCH_ASSOC);

    $labels = [];
    $gross = [];
    $commission = [];
    $net = [];
    foreach ($rows as $r) {
      $labels[] = $r['day'];
      $gVal = (float) ($r['gross'] ?? 0);
      $gross[] = $gVal;
      $commission[] = round($gVal * $commissionRate, 2);
      $net[] = round($gVal * (1 - $commissionRate), 2);
    }

    return [
      'labels' => $labels,
      'gross' => $gross,
      'commission' => $commission,
      'net' => $net,
    ];
  }

  /**
   * Builds vendor ranking: group by store_id from ledger, top by gross.
   */
  private function buildVendorRanking(int $days, int $limit): array {
    if (!$this->database->schema()->tableExists('myeventlane_payout_ledger') ||
        !$this->database->schema()->tableExists('commerce_order')) {
      return [];
    }

    $cutoff = strtotime("-{$days} days midnight");
    $now = $this->time->getRequestTime();

    $orderIds = $this->database->select('commerce_order', 'o')
      ->fields('o', ['order_id'])
      ->condition('o.state', 'completed')
      ->condition('o.placed', $cutoff, '>=')
      ->condition('o.placed', $now, '<=')
      ->execute()
      ->fetchCol();
    $orderIds = array_map('intval', array_filter($orderIds));

    if (empty($orderIds)) {
      return [];
    }

    $q = $this->database->select('myeventlane_payout_ledger', 'l');
    $q->addField('l', 'store_id');
    $q->addExpression('COUNT(l.id)', 'orders');
    $q->addExpression('COALESCE(SUM(l.gross), 0)', 'gross');
    $q->addExpression('COALESCE(SUM(l.commission), 0)', 'commission');
    $q->addExpression('COALESCE(SUM(l.net), 0)', 'net');
    $q->condition('l.order_id', $orderIds, 'IN');
    $q->groupBy('l.store_id');
    $q->orderBy('gross', 'DESC');
    $q->range(0, $limit);
    $rows = $q->execute()->fetchAll(\PDO::FETCH_ASSOC);

    $unpaidQ = $this->database->select('myeventlane_payout_ledger', 'l');
    $unpaidQ->addField('l', 'store_id');
    $unpaidQ->addExpression('COALESCE(SUM(l.net), 0)', 'unpaid');
    $unpaidQ->condition('l.order_id', $orderIds, 'IN');
    $unpaidQ->condition('l.status', 'unpaid');
    $unpaidQ->groupBy('l.store_id');
    $unpaidRows = $unpaidQ->execute()->fetchAll(\PDO::FETCH_ASSOC);
    $unpaidByStore = [];
    foreach ($unpaidRows as $u) {
      $unpaidByStore[(int) $u['store_id']] = (float) ($u['unpaid'] ?? 0);
    }

    $storeStorage = $this->entityTypeManager->getStorage('commerce_store');
    $result = [];
    foreach ($rows as $r) {
      $storeId = (int) ($r['store_id'] ?? 0);
      if ($storeId <= 0) {
        continue;
      }
      $store = $storeStorage->load($storeId);
      $result[$storeId] = [
        'store_id' => $storeId,
        'store_label' => $store ? (string) $store->label() : "Store #{$storeId}",
        'orders' => (int) ($r['orders'] ?? 0),
        'gross' => round((float) ($r['gross'] ?? 0), 2),
        'commission' => round((float) ($r['commission'] ?? 0), 2),
        'net' => round((float) ($r['net'] ?? 0), 2),
        'unpaid_liability' => round($unpaidByStore[$storeId] ?? 0, 2),
      ];
    }

    return $result;
  }

  /**
   * Builds payout liability summary from ledger (unpaid/paid).
   */
  private function buildPayoutLiabilitySummary(int $days): array {
    if (!$this->database->schema()->tableExists('myeventlane_payout_ledger') ||
        !$this->database->schema()->tableExists('commerce_order')) {
      return [
        'unpaid_total' => 0.0,
        'paid_total' => 0.0,
        'unpaid_by_store' => [],
      ];
    }

    $cutoff = strtotime("-{$days} days midnight");
    $orderIds = $this->database->select('commerce_order', 'o')
      ->fields('o', ['order_id'])
      ->condition('o.state', 'completed')
      ->condition('o.placed', $cutoff, '>=')
      ->execute()
      ->fetchCol();
    $orderIds = array_map('intval', array_filter($orderIds));

    if (empty($orderIds)) {
      return [
        'unpaid_total' => 0.0,
        'paid_total' => 0.0,
        'unpaid_by_store' => [],
      ];
    }

    $unpaidQ = $this->database->select('myeventlane_payout_ledger', 'l');
    $unpaidQ->addExpression('COALESCE(SUM(l.net), 0)', 't');
    $unpaidQ->condition('l.order_id', $orderIds, 'IN');
    $unpaidQ->condition('l.status', 'unpaid');
    $unpaidTotal = $unpaidQ->execute()->fetchField();

    $paidQ = $this->database->select('myeventlane_payout_ledger', 'l');
    $paidQ->addExpression('COALESCE(SUM(l.net), 0)', 't');
    $paidQ->condition('l.order_id', $orderIds, 'IN');
    $paidQ->condition('l.status', 'paid');
    $paidTotal = $paidQ->execute()->fetchField();

    $unpaidByStoreQ = $this->database->select('myeventlane_payout_ledger', 'l');
    $unpaidByStoreQ->addField('l', 'store_id');
    $unpaidByStoreQ->addExpression('COALESCE(SUM(l.net), 0)', 'unpaid');
    $unpaidByStoreQ->condition('l.order_id', $orderIds, 'IN');
    $unpaidByStoreQ->condition('l.status', 'unpaid');
    $unpaidByStoreQ->groupBy('l.store_id');
    $unpaidByStoreQ->orderBy('unpaid', 'DESC');
    $unpaidByStoreQ->range(0, 10);
    $unpaidRows = $unpaidByStoreQ->execute()->fetchAll(\PDO::FETCH_ASSOC);

    $storeStorage = $this->entityTypeManager->getStorage('commerce_store');
    $unpaidByStore = [];
    foreach ($unpaidRows as $u) {
      $sid = (int) $u['store_id'];
      $store = $storeStorage->load($sid);
      $unpaidByStore[] = [
        'store_id' => $sid,
        'store_label' => $store ? (string) $store->label() : "Store #{$sid}",
        'unpaid' => round((float) ($u['unpaid'] ?? 0), 2),
      ];
    }

    return [
      'unpaid_total' => round((float) ($unpaidTotal ?: 0), 2),
      'paid_total' => round((float) ($paidTotal ?: 0), 2),
      'unpaid_by_store' => $unpaidByStore,
    ];
  }

  /**
   * Returns empty KPIs.
   */
  private function emptyKpis(): array {
    return [
      'total_orders' => 0,
      'gross_total' => 0.0,
      'commission_total' => 0.0,
      'net_total' => 0.0,
      'unpaid_total' => 0.0,
      'paid_total' => 0.0,
    ];
  }

}
