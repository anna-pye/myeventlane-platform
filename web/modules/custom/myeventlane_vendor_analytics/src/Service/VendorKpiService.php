<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor_analytics\Service;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Psr\Log\LoggerInterface;

/**
 * Vendor KPI aggregation service (STAGE A1).
 *
 * KPIs: Net Revenue, Orders, Tickets sold, RSVPs (confirmed).
 * No Views, no Conversion. Store-based ownership per A0.
 *
 * Path: Implemented queries (VendorMetricsService exists but lacks orders_count
 * and a currency parameter; logic mirrors A0 / VendorMetricsService where
 * applicable).
 */
final class VendorKpiService {

  private const CACHE_TTL = 300;

  private const CACHE_KEY_PREFIX = 'vendor_kpi';

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns KPI values for a vendor store over a time range.
   *
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The vendor store.
   * @param int $start_ts
   *   Start timestamp (inclusive).
   * @param int $end_ts
   *   End timestamp (inclusive).
   * @param string $currency
   *   Currency code (e.g. AUD). Default AUD.
   *
   * @return array
   *   Keys: revenue_net_cents (int), orders_count (int), tickets_sold (int),
   *   rsvps_confirmed (int), currency (string).
   */
  public function getKpisForStore(StoreInterface $store, int $start_ts, int $end_ts, string $currency = 'AUD'): array {
    $store_id = (int) $store->id();
    $cid = implode(':', [
      self::CACHE_KEY_PREFIX,
      (string) $store_id,
      (string) $start_ts,
      (string) $end_ts,
      $currency,
    ]);

    $cached = $this->cache->get($cid);
    if ($cached !== FALSE && is_array($cached->data)) {
      return $cached->data;
    }

    $gross_cents = $this->getGrossRevenueCents($store_id, $start_ts, $end_ts, $currency);
    $refunded_cents = $this->getRefundedAmountCents($store_id, $start_ts, $end_ts, $currency);
    $revenue_net_cents = (int) max(0, $gross_cents - $refunded_cents);

    $orders_count = $this->getOrdersCount($store_id, $start_ts, $end_ts, $currency);
    $tickets_sold = $this->getTicketsSoldCount($store_id, $start_ts, $end_ts, $currency);
    $rsvps_confirmed = $this->getConfirmedRsvpCount($store_id, $start_ts, $end_ts);

    $result = [
      'revenue_net_cents' => $revenue_net_cents,
      'orders_count' => $orders_count,
      'tickets_sold' => $tickets_sold,
      'rsvps_confirmed' => $rsvps_confirmed,
      'currency' => $currency,
    ];

    $tags = [
      'commerce_order_list',
      'commerce_order_item_list',
      'rsvp_submission_list',
      'commerce_store:' . $store_id,
    ];
    $this->cache->set($cid, $result, $this->time->getRequestTime() + self::CACHE_TTL, $tags);

    return $result;
  }

  /**
   * Returns the default 30‑day rolling range.
   *
   * @return array
   *   Keys start (int) and end (int), unix timestamps.
   */
  public function getDefaultRangeLast30Days(): array {
    $end = $this->time->getRequestTime();
    $start = $end - (30 * 24 * 60 * 60);
    return ['start' => $start, 'end' => $end];
  }

  /**
   * Gross revenue: SUM(commerce_order.total_price__number) in cents.
   *
   * A0: store_id, state='completed', placed BETWEEN start/end,
   * total_price__currency_code = :currency.
   */
  private function getGrossRevenueCents(int $store_id, int $start, int $end, string $currency): int {
    try {
      $q = $this->database->select('commerce_order', 'o');
      $q->addExpression('COALESCE(SUM(o.total_price__number), 0)', 'sum_number');
      $q->condition('o.store_id', $store_id);
      $q->condition('o.state', 'completed');
      $q->condition('o.placed', $start, '>=');
      $q->condition('o.placed', $end, '<=');
      $q->condition('o.total_price__currency_code', $currency);

      $sum = (string) $q->execute()->fetchField();
      return $this->decimalToCents($sum);
    }
    catch (\Throwable $e) {
      $this->logger->warning('VendorKpiService::getGrossRevenueCents failed: @message', ['@message' => $e->getMessage()]);
      return 0;
    }
  }

  /**
   * Refunds: SUM(myeventlane_refund_log.amount_cents).
   *
   * A0: join commerce_order; status='completed'; created BETWEEN start/end;
   * currency filter if column exists (stored as lowercase 'aud' in schema).
   */
  private function getRefundedAmountCents(int $store_id, int $start, int $end, string $currency): int {
    try {
      if (!$this->database->schema()->tableExists('myeventlane_refund_log')) {
        return 0;
      }

      $q = $this->database->select('myeventlane_refund_log', 'r');
      $q->join('commerce_order', 'o', 'o.order_id = r.order_id');
      $q->addExpression('COALESCE(SUM(r.amount_cents), 0)', 'sum_cents');
      $q->condition('o.store_id', $store_id);
      $q->condition('r.status', 'completed');
      $q->condition('r.created', $start, '>=');
      $q->condition('r.created', $end, '<=');

      if ($this->database->schema()->fieldExists('myeventlane_refund_log', 'currency')) {
        $q->where('LOWER(r.currency) = LOWER(:cur)', [':cur' => $currency]);
      }

      return (int) $q->execute()->fetchField();
    }
    catch (\Throwable $e) {
      $this->logger->warning('VendorKpiService::getRefundedAmountCents failed: @message', ['@message' => $e->getMessage()]);
      return 0;
    }
  }

  /**
   * Orders count: COUNT(commerce_order.order_id).
   *
   * A0: same filters as gross (store, state, placed, currency).
   */
  private function getOrdersCount(int $store_id, int $start, int $end, string $currency): int {
    try {
      $q = $this->database->select('commerce_order', 'o');
      $q->addExpression('COUNT(o.order_id)', 'cnt');
      $q->condition('o.store_id', $store_id);
      $q->condition('o.state', 'completed');
      $q->condition('o.placed', $start, '>=');
      $q->condition('o.placed', $end, '<=');
      $q->condition('o.total_price__currency_code', $currency);

      return (int) $q->execute()->fetchField();
    }
    catch (\Throwable $e) {
      $this->logger->warning('VendorKpiService::getOrdersCount failed: @message', ['@message' => $e->getMessage()]);
      return 0;
    }
  }

  /**
   * Tickets sold: SUM(commerce_order_item.quantity), paid only.
   *
   * A0: order.store_id, order.state='completed', order.placed BETWEEN;
   * order_item.unit_price__currency_code = :currency;
   * order_item.unit_price__number > 0.
   * No refund deduction (A0: not implemented). Does NOT restrict to
   * field_target_event (non-trivial join; document as follow-up).
   */
  private function getTicketsSoldCount(int $store_id, int $start, int $end, string $currency): int {
    try {
      $q = $this->database->select('commerce_order_item', 'oi');
      $q->join('commerce_order', 'o', 'o.order_id = oi.order_id');
      $q->addExpression('COALESCE(SUM(oi.quantity), 0)', 'qty_sum');
      $q->condition('o.store_id', $store_id);
      $q->condition('o.state', 'completed');
      $q->condition('o.placed', $start, '>=');
      $q->condition('o.placed', $end, '<=');
      $q->condition('oi.unit_price__currency_code', $currency);
      $q->condition('oi.unit_price__number', '0', '>');

      return (int) $q->execute()->fetchField();
    }
    catch (\Throwable $e) {
      $this->logger->warning('VendorKpiService::getTicketsSoldCount failed: @message', ['@message' => $e->getMessage()]);
      return 0;
    }
  }

  /**
   * RSVPs confirmed: COUNT(rsvp_submission) via event→field_event_store.
   *
   * A0: rsvp_submission.status='confirmed'; join rsvp_submission__event_id and
   * node__field_event_store; field_event_store_target_id = store_id;
   * rsvp_submission.created BETWEEN start/end.
   * Legacy myeventlane_rsvp is NOT included; counts may understate.
   */
  private function getConfirmedRsvpCount(int $store_id, int $start, int $end): int {
    try {
      $event_ref = 'rsvp_submission__event_id';
      $node_store = 'node__field_event_store';
      if (!$this->database->schema()->tableExists($event_ref) || !$this->database->schema()->tableExists($node_store)) {
        return 0;
      }

      $time_field = $this->database->schema()->fieldExists('rsvp_submission', 'created') ? 'created' : 'changed';

      $q = $this->database->select('rsvp_submission', 'r');
      $q->join($event_ref, 're', 're.entity_id = r.id');
      $q->join($node_store, 'nes', 'nes.entity_id = re.event_id_target_id');
      $q->addExpression('COUNT(r.id)', 'cnt');
      $q->condition('r.status', 'confirmed');
      $q->condition('nes.field_event_store_target_id', $store_id);
      $q->condition('r.' . $time_field, $start, '>=');
      $q->condition('r.' . $time_field, $end, '<=');

      return (int) $q->execute()->fetchField();
    }
    catch (\Throwable $e) {
      $this->logger->warning(
        'VendorKpiService::getConfirmedRsvpCount failed: @message',
        ['@message' => $e->getMessage()]
      );
      return 0;
    }
  }

  /**
   * Converts a decimal string (e.g. "123.45") to integer cents.
   *
   * Avoids float; uses string handling. Mirrors VendorMetricsService logic.
   */
  private function decimalToCents(string $decimal): int {
    $decimal = trim($decimal);
    if ($decimal === '' || $decimal === '0') {
      return 0;
    }
    if (!str_contains($decimal, '.')) {
      return (int) $decimal * 100;
    }
    [$whole, $frac] = explode('.', $decimal, 2);
    $frac = substr(str_pad($frac, 2, '0'), 0, 2);
    $sign = 1;
    if (str_starts_with($whole, '-')) {
      $sign = -1;
      $whole = ltrim($whole, '-');
    }
    $whole_i = (int) $whole;
    $frac_i = (int) $frac;
    return $sign * (($whole_i * 100) + $frac_i);
  }

}
