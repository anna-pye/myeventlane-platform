<?php

declare(strict_types=1);

namespace Drupal\myeventlane_domain_events\ProjectionReadModel;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_core\Utility\EntityLoadIds;
use Psr\Log\LoggerInterface;

/**
 * Read model for per-vendor metrics with projection-first lookup.
 */
final class VendorMetricsReadModel {

  private const PROJECTION_TTL = 300;
  private const FALLBACK_TTL = 60;

  /**
   * Order item types excluded from ticket/revenue calculations.
   *
   * @var string[]
   */
  private const EXCLUDED_ORDER_ITEM_TYPES = [
    'boost',
    'checkout_donation',
    'platform_donation',
    'rsvp_donation',
  ];

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CacheBackendInterface $cache,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns vendor metrics from projection, with safe fallback if empty.
   *
   * @return array{
   *   total_revenue:float,
   *   tickets_sold:int,
   *   active_events:int,
   *   refund_total:float
   * }
   *   Vendor metrics payload.
   */
  public function getVendorMetrics(int $vendorId): array {
    if ($vendorId <= 0) {
      return $this->emptyMetrics();
    }

    $cacheKey = sprintf('mel:vendor_metrics:%d', $vendorId);
    $cached = $this->cache->get($cacheKey);
    if ($cached !== FALSE && is_array($cached->data)) {
      return $cached->data;
    }

    $projection = $this->database->select('myeventlane_projection_vendor_metrics', 'm')
      ->fields('m', ['total_revenue', 'tickets_sold', 'active_events', 'refund_total'])
      ->condition('vendor_id', $vendorId)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    if (is_array($projection)) {
      $metrics = [
        'total_revenue' => (float) ($projection['total_revenue'] ?? 0.0),
        'tickets_sold' => (int) ($projection['tickets_sold'] ?? 0),
        'active_events' => (int) ($projection['active_events'] ?? 0),
        'refund_total' => (float) ($projection['refund_total'] ?? 0.0),
      ];
      $this->cache->set(
        $cacheKey,
        $metrics,
        time() + self::PROJECTION_TTL,
        [sprintf('vendor_metrics:%d', $vendorId)]
      );
      return $metrics;
    }

    $this->logger->debug(
      'Projection miss for vendor {vendor_id}; using fallback metrics.',
      ['vendor_id' => $vendorId]
    );

    $fallback = $this->buildFallbackMetrics($vendorId);
    $this->cache->set(
      $cacheKey,
      $fallback,
      time() + self::FALLBACK_TTL,
      [sprintf('vendor_metrics:%d', $vendorId)]
    );
    return $fallback;
  }

  /**
   * Builds fallback metrics from orders + tickets and payment refund amounts.
   *
   * @return array{
   *   total_revenue:float,
   *   tickets_sold:int,
   *   active_events:int,
   *   refund_total:float
   * }
   *   Vendor metrics payload.
   */
  private function buildFallbackMetrics(int $vendorId): array {
    try {
      $eventIds = $this->loadVendorEventIds($vendorId);
      if (count($eventIds) > 2000) {
        $this->logger->warning(
          'Vendor fallback metrics exceeded safe event count for vendor {vendor_id}. Projection may be stale.',
          ['vendor_id' => $vendorId]
        );
      }
      if ($eventIds === []) {
        return $this->emptyMetrics();
      }

      $orderIds = $this->loadOrderIdsForEvents($eventIds);

      $totalRevenue = $this->sumRevenueFromOrderItems($eventIds);
      $ticketsSold = $this->sumTicketQuantityFromOrderItems($eventIds);
      $activeEvents = $this->countActiveEventsWithSales($eventIds);
      $refundTotal = $this->sumRefundedAmountFromPayments($orderIds);

      return [
        'total_revenue' => $totalRevenue,
        'tickets_sold' => $ticketsSold,
        'active_events' => $activeEvents,
        'refund_total' => $refundTotal,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Vendor metrics fallback failed for vendor {vendor_id}: {message}', [
        'vendor_id' => $vendorId,
        'message' => $e->getMessage(),
      ]);
      return $this->emptyMetrics();
    }
  }

  /**
   * Resolves vendor event IDs from event relation fields only.
   *
   * Field priority: field_event_vendor, field_vendor, field_vendor_account.
   *
   * @return int[]
   *   Distinct event node IDs.
   */
  private function loadVendorEventIds(int $vendorId): array {
    if (!$this->database->schema()->tableExists('node_field_data')) {
      return [];
    }

    $eventIds = [];
    foreach (['field_event_vendor', 'field_vendor', 'field_vendor_account'] as $fieldName) {
      $table = 'node__' . $fieldName;
      $column = $fieldName . '_target_id';
      if (!$this->database->schema()->tableExists($table)) {
        continue;
      }

      $query = $this->database->select($table, 'f');
      $query->fields('f', ['entity_id']);
      $query->condition('f.' . $column, $vendorId);

      foreach ($query->execute() as $row) {
        $eventId = (int) ($row->entity_id ?? 0);
        if ($eventId > 0) {
          $eventIds[] = $eventId;
        }
      }
    }

    if ($eventIds === []) {
      return [];
    }

    $eventIds = array_values(array_unique($eventIds));

    // Keep only existing event nodes.
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $validIds = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('nid', $eventIds, 'IN')
      ->condition('type', 'event')
      ->execute();

    return array_values(array_map('intval', $validIds));
  }

  /**
   * @param int[] $eventIds
   *   Event node IDs.
   *
   * @return int[]
   *   Distinct order IDs for the provided events.
   */
  private function loadOrderIdsForEvents(array $eventIds): array {
    if (
      $eventIds === [] ||
      !$this->database->schema()->tableExists('commerce_order_item') ||
      !$this->database->schema()->tableExists('commerce_order_item__field_target_event')
    ) {
      return [];
    }

    $query = $this->database->select('commerce_order_item', 'oi');
    $query->join('commerce_order_item__field_target_event', 'lnk', 'lnk.entity_id = oi.order_item_id');
    $query->addField('oi', 'order_id', 'order_id');
    $query->condition('lnk.field_target_event_target_id', $eventIds, 'IN');
    $query->distinct();

    $orderIds = [];
    foreach ($query->execute() as $row) {
      $orderId = (int) ($row->order_id ?? 0);
      if ($orderId > 0) {
        $orderIds[] = $orderId;
      }
    }

    return array_values(array_unique($orderIds));
  }

  /**
   * @param int[] $eventIds
   *   Event node IDs.
   */
  private function sumRevenueFromOrderItems(array $eventIds): float {
    if (
      $eventIds === [] ||
      !$this->database->schema()->tableExists('commerce_order_item') ||
      !$this->database->schema()->tableExists('commerce_order') ||
      !$this->database->schema()->tableExists('commerce_order_item__field_target_event')
    ) {
      return 0.0;
    }

    $query = $this->database->select('commerce_order_item', 'oi');
    $query->join('commerce_order', 'o', 'o.order_id = oi.order_id');
    $query->join('commerce_order_item__field_target_event', 'lnk', 'lnk.entity_id = oi.order_item_id');
    $query->addExpression('COALESCE(SUM(oi.unit_price__number * oi.quantity), 0)', 'amount');
    $query->condition('o.state', 'completed');
    $query->condition('lnk.field_target_event_target_id', $eventIds, 'IN');
    $query->condition('oi.unit_price__number', '0', '>');
    $query->condition('oi.type', self::EXCLUDED_ORDER_ITEM_TYPES, 'NOT IN');

    return round((float) $query->execute()->fetchField(), 2);
  }

  /**
   * @param int[] $eventIds
   *   Event node IDs.
   */
  private function sumTicketQuantityFromOrderItems(array $eventIds): int {
    if (
      $eventIds === [] ||
      !$this->database->schema()->tableExists('commerce_order_item') ||
      !$this->database->schema()->tableExists('commerce_order') ||
      !$this->database->schema()->tableExists('commerce_order_item__field_target_event')
    ) {
      return 0;
    }

    $query = $this->database->select('commerce_order_item', 'oi');
    $query->join('commerce_order', 'o', 'o.order_id = oi.order_id');
    $query->join('commerce_order_item__field_target_event', 'lnk', 'lnk.entity_id = oi.order_item_id');
    $query->addExpression('COALESCE(SUM(oi.quantity), 0)', 'qty');
    $query->condition('o.state', 'completed');
    $query->condition('lnk.field_target_event_target_id', $eventIds, 'IN');
    $query->condition('oi.unit_price__number', '0', '>');
    $query->condition('oi.type', self::EXCLUDED_ORDER_ITEM_TYPES, 'NOT IN');

    return (int) $query->execute()->fetchField();
  }

  /**
   * @param int[] $eventIds
   *   Event node IDs.
   */
  private function countActiveEventsWithSales(array $eventIds): int {
    if (
      $eventIds === [] ||
      !$this->database->schema()->tableExists('commerce_order_item') ||
      !$this->database->schema()->tableExists('commerce_order') ||
      !$this->database->schema()->tableExists('commerce_order_item__field_target_event')
    ) {
      return 0;
    }

    $query = $this->database->select('commerce_order_item', 'oi');
    $query->join('commerce_order', 'o', 'o.order_id = oi.order_id');
    $query->join('commerce_order_item__field_target_event', 'lnk', 'lnk.entity_id = oi.order_item_id');
    $query->addField('lnk', 'field_target_event_target_id', 'event_id');
    $query->condition('o.state', 'completed');
    $query->condition('lnk.field_target_event_target_id', $eventIds, 'IN');
    $query->condition('oi.unit_price__number', '0', '>');
    $query->condition('oi.type', self::EXCLUDED_ORDER_ITEM_TYPES, 'NOT IN');
    $query->groupBy('lnk.field_target_event_target_id');

    $count = 0;
    foreach ($query->execute() as $row) {
      if ((int) ($row->event_id ?? 0) > 0) {
        $count++;
      }
    }

    return $count;
  }

  /**
   * Sums refunded amounts from commerce_payment for the vendor's event orders.
   *
   * @param int[] $orderIds
   *   Order IDs linked to vendor events.
   */
  private function sumRefundedAmountFromPayments(array $orderIds): float {
    if ($orderIds === []) {
      return 0.0;
    }

    $paymentStorage = $this->entityTypeManager->getStorage('commerce_payment');
    $paymentIds = $paymentStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_id', $orderIds, 'IN')
      ->condition('state', ['partially_refunded', 'refunded'], 'IN')
      ->execute();

    if ($paymentIds === []) {
      return 0.0;
    }

    $paymentIds = EntityLoadIds::normalizeForLoadMultiple($paymentIds);
    if ($paymentIds === []) {
      return 0.0;
    }

    $total = 0.0;
    $payments = $paymentStorage->loadMultiple($paymentIds);
    foreach ($payments as $payment) {
      $refunded = $payment->getRefundedAmount();
      if ($refunded !== NULL) {
        $total += (float) $refunded->getNumber();
      }
    }

    return round($total, 2);
  }

  /**
   * @return array{
   *   total_revenue:float,
   *   tickets_sold:int,
   *   active_events:int,
   *   refund_total:float
   * }
   *   Empty metrics payload.
   */
  private function emptyMetrics(): array {
    return [
      'total_revenue' => 0.0,
      'tickets_sold' => 0,
      'active_events' => 0,
      'refund_total' => 0.0,
    ];
  }

}

