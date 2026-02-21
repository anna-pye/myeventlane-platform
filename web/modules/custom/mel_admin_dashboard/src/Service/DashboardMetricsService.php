<?php

declare(strict_types=1);

namespace Drupal\mel_admin_dashboard\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Aggregates platform metrics for the MEL Admin Dashboard.
 */
class DashboardMetricsService {

  private const CACHE_MAX_AGE = 300;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    protected CacheBackendInterface $cache,
    protected TimeInterface $time,
  ) {}

  /**
   * Returns cached or fresh platform metrics.
   *
   * @return array{vendors: int, gross_30_days: string, orders_30_days: int, open_escalations: int}
   */
  public function getMetrics(): array {
    if ($cache = $this->cache->get('mel_admin_dashboard.metrics')) {
      return $cache->data;
    }

    $now = $this->time->getRequestTime();
    $month_ago = strtotime('-30 days', $now);

    $total_vendors = $this->getVendorCount();
    [$orders_count, $gross] = $this->getOrdersMetrics($month_ago);
    $escalation_count = $this->getOpenEscalationCount();

    $data = [
      'vendors' => $total_vendors,
      'gross_30_days' => number_format($gross, 2),
      'orders_30_days' => $orders_count,
      'open_escalations' => $escalation_count,
    ];

    $this->cache->set(
      'mel_admin_dashboard.metrics',
      $data,
      $now + self::CACHE_MAX_AGE,
      ['commerce_order', 'user', 'escalation_list'],
    );

    return $data;
  }

  /**
   * Counts active users with vendor role.
   */
  private function getVendorCount(): int {
    $query = $this->entityTypeManager
      ->getStorage('user')
      ->getQuery()
      ->condition('status', 1)
      ->condition('roles', 'vendor')
      ->accessCheck(FALSE);
    return (int) $query->count()->execute();
  }

  /**
   * Returns [order_count, gross_revenue] for completed orders in range.
   *
   * @param int $since Unix timestamp (inclusive)
   * @return array{0: int, 1: float}
   */
  private function getOrdersMetrics(int $since): array {
    $query = $this->entityTypeManager
      ->getStorage('commerce_order')
      ->getQuery()
      ->condition('state', 'completed')
      ->condition('created', $since, '>=')
      ->accessCheck(FALSE);

    $order_ids = $query->execute();
    $orders = $this->entityTypeManager
      ->getStorage('commerce_order')
      ->loadMultiple($order_ids);

    $gross = 0.0;
    foreach ($orders as $order) {
      $total = $order->getTotalPrice();
      if ($total) {
        $gross += (float) $total->getNumber();
      }
    }

    return [count($orders), $gross];
  }

  /**
   * Counts escalations not resolved or closed.
   */
  private function getOpenEscalationCount(): int {
    if (!$this->entityTypeManager->hasDefinition('escalation')) {
      return 0;
    }

    $query = $this->entityTypeManager
      ->getStorage('escalation')
      ->getQuery()
      ->condition('status', ['resolved', 'closed'], 'NOT IN')
      ->accessCheck(FALSE);

    return (int) $query->count()->execute();
  }

}
