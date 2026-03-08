<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Analytics service for Pro abandoned-cart recovery attribution.
 */
final class ProRecoveryAnalyticsService {

  private const PRO_MONTHLY_COST = 49.0;

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns store-level recovery stats.
   *
   * @return array{
   *   reminders_sent: int,
   *   recovered_orders: int,
   *   recovery_rate: float,
   *   recovered_revenue: float,
   *   avg_order_value: float
   * }
   *   Recovery statistics.
   */
  public function getStoreRecoveryStats(int $store_id, int $days = 30): array {
    if ($store_id <= 0) {
      return $this->emptyStats();
    }

    $windowStart = $this->time->getRequestTime() - (max(1, $days) * 86400);

    try {
      $remindersSent = (int) $this->database->select('myeventlane_pro_recovery_attribution', 'a')
        ->condition('store_id', $store_id)
        ->condition('sent_at', $windowStart, '>=')
        ->countQuery()
        ->execute()
        ->fetchField();

      $recoveredOrders = (int) $this->database->select('myeventlane_pro_recovery_attribution', 'a')
        ->condition('store_id', $store_id)
        ->condition('recovered', 1)
        ->condition('recovered_at', $windowStart, '>=')
        ->countQuery()
        ->execute()
        ->fetchField();

      $recoveredRevenue = $this->sumRecoveredRevenue($windowStart, $store_id);

      $recoveryRate = $remindersSent > 0 ? round(($recoveredOrders / $remindersSent) * 100, 1) : 0.0;
      $avgOrderValue = $recoveredOrders > 0 ? round($recoveredRevenue / $recoveredOrders, 2) : 0.0;

      return [
        'reminders_sent' => $remindersSent,
        'recovered_orders' => $recoveredOrders,
        'recovery_rate' => $recoveryRate,
        'recovered_revenue' => round($recoveredRevenue, 2),
        'avg_order_value' => $avgOrderValue,
      ];
    }
    catch (\Throwable $exception) {
      $this->logger->error('Failed store recovery stats for store @store_id: @message', [
        '@store_id' => $store_id,
        '@message' => $exception->getMessage(),
      ]);
      return $this->emptyStats();
    }
  }

  /**
   * Returns platform-level recovery stats.
   *
   * @return array{
   *   reminders_sent: int,
   *   recovered_orders: int,
   *   recovery_rate: float,
   *   recovered_revenue: float,
   *   avg_order_value: float
   * }
   *   Recovery statistics.
   */
  public function getPlatformRecoveryStats(int $days = 30): array {
    $since = $this->time->getRequestTime() - ($days * 86400);

    $select = $this->database->select('myeventlane_pro_recovery_attribution', 'a');

    $select->addExpression('COUNT(a.id)', 'reminders_sent');
    $select->addExpression('SUM(CASE WHEN a.recovered = 1 THEN 1 ELSE 0 END)', 'recovered_orders');
    $select->addExpression('SUM(CASE WHEN a.recovered = 1 THEN a.order_total ELSE 0 END)', 'recovered_revenue');

    $select->condition('a.created', $since, '>=');

    $result = $select->execute()->fetchAssoc();

    $reminders = (int) ($result['reminders_sent'] ?? 0);
    $recovered = (int) ($result['recovered_orders'] ?? 0);
    $revenue = (float) ($result['recovered_revenue'] ?? 0);

    $recovery_rate = $reminders > 0 ? round(($recovered / $reminders) * 100, 1) : 0.0;
    $avg_order_value = $recovered > 0 ? round($revenue / $recovered, 2) : 0.0;

    return [
      'reminders_sent' => $reminders,
      'recovered_orders' => $recovered,
      'recovery_rate' => $recovery_rate,
      'recovered_revenue' => $revenue,
      'avg_order_value' => $avg_order_value,
    ];
  }

  /**
   * Estimates Pro ROI for a store.
   *
   * @return array{pro_cost: float, recovered_revenue: float, roi_multiple: float}
   *   ROI values.
   */
  public function estimateProROI(int $store_id, int $months = 1): array {
    $effectiveMonths = max(1, $months);
    $stats = $this->getStoreRecoveryStats($store_id, $effectiveMonths * 30);
    $proCost = round(self::PRO_MONTHLY_COST * $effectiveMonths, 2);
    $recoveredRevenue = (float) $stats['recovered_revenue'];
    $roiMultiple = $proCost > 0 ? round($recoveredRevenue / $proCost, 2) : 0.0;

    return [
      'pro_cost' => $proCost,
      'recovered_revenue' => $recoveredRevenue,
      'roi_multiple' => $roiMultiple,
    ];
  }

  /**
   * Returns top recovering stores by recovered revenue.
   *
   * @return array<int, array{store_id: int, store_label: string, recovered_revenue: float, recovery_rate: float}>
   *   Top stores.
   */
  public function getTopRecoveringStores(int $days = 30, int $limit = 5): array {
    $windowStart = $this->time->getRequestTime() - (max(1, $days) * 86400);
    $effectiveLimit = max(1, $limit);

    try {
      $query = $this->database->select('myeventlane_pro_recovery_attribution', 'a')
        ->fields('a', ['store_id'])
        ->condition('a.recovered', 1)
        ->condition('a.recovered_at', $windowStart, '>=')
        ->groupBy('a.store_id')
        ->orderBy('revenue_total', 'DESC')
        ->range(0, $effectiveLimit);
      $query->addExpression('SUM(a.order_total)', 'revenue_total');

      $rows = $query
        ->execute()
        ->fetchAllAssoc('store_id');

      if ($rows === []) {
        return [];
      }

      $storeIds = array_map('intval', array_keys($rows));
      $stores = $this->entityTypeManager->getStorage('commerce_store')->loadMultiple($storeIds);
      $output = [];

      foreach ($rows as $storeId => $row) {
        $storeId = (int) $storeId;
        $stats = $this->getStoreRecoveryStats($storeId, $days);
        $store = $stores[$storeId] ?? NULL;
        $output[] = [
          'store_id' => $storeId,
          'store_label' => $store ? $store->label() : 'Store #' . $storeId,
          'recovered_revenue' => round((float) ($row->revenue_total ?? 0), 2),
          'recovery_rate' => (float) $stats['recovery_rate'],
        ];
      }

      return $output;
    }
    catch (\Throwable $exception) {
      $this->logger->error('Failed top recovering stores query: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Sums recovered revenue within a window.
   */
  private function sumRecoveredRevenue(int $windowStart, ?int $storeId = NULL): float {
    $query = $this->database->select('myeventlane_pro_recovery_attribution', 'a')
      ->condition('a.recovered', 1)
      ->condition('a.recovered_at', $windowStart, '>=');
    $query->addExpression('SUM(a.order_total)', 'recovered_revenue');

    if ($storeId !== NULL) {
      $query->condition('a.store_id', $storeId);
    }

    $value = $query->execute()->fetchField();
    return $value !== FALSE && $value !== NULL ? (float) $value : 0.0;
  }

  /**
   * Returns an empty stats payload.
   *
   * @return array{
   *   reminders_sent: int,
   *   recovered_orders: int,
   *   recovery_rate: float,
   *   recovered_revenue: float,
   *   avg_order_value: float
   * }
   *   Empty stats.
   */
  private function emptyStats(): array {
    return [
      'reminders_sent' => 0,
      'recovered_orders' => 0,
      'recovery_rate' => 0.0,
      'recovered_revenue' => 0.0,
      'avg_order_value' => 0.0,
    ];
  }

}
