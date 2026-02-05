<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor_analytics\Service;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\myeventlane_analytics\Phase7\Service\AnalyticsQueryServiceInterface;
use Drupal\myeventlane_analytics\Phase7\Value\AnalyticsQuery;
use Psr\Log\LoggerInterface;

/**
 * Vendor KPI aggregation service.
 *
 * Revenue (net) and tickets sold are sourced only from Phase 7
 * (myeventlane_analytics.phase7.query). No commerce_order.total_price or
 * direct DB revenue/ticket queries. Boost spend is not available to vendors.
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
    private readonly AnalyticsQueryServiceInterface $phase7Query,
  ) {}

  /**
   * Returns KPI values for a vendor store over a time range.
   *
   * revenue_net_cents and tickets_sold come from Phase 7 only.
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

    $q_money = new AnalyticsQuery(
      scope: AnalyticsQuery::SCOPE_VENDOR,
      store_ids: [],
      start_ts: $start_ts,
      end_ts: $end_ts,
      currency: $currency,
    );

    $q_count = new AnalyticsQuery(
      scope: AnalyticsQuery::SCOPE_VENDOR,
      store_ids: [],
      start_ts: $start_ts,
      end_ts: $end_ts,
      currency: NULL,
    );

    $net_rows = $this->phase7Query->getNetRevenue($q_money);
    $tickets_rows = $this->phase7Query->getTicketsSold($q_count);

    $revenue_net_cents = $this->sumCentsForStore($net_rows, $store_id);
    $tickets_sold = $this->sumCountForStore($tickets_rows, $store_id);
    $orders_count = $this->getOrdersCount($store_id, $start_ts, $end_ts, $currency);
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
   * Sums amount_cents for rows matching the given store_id.
   *
   * @param array $rows
   *   MoneyByStoreEventCurrencyRow instances from Phase 7.
   */
  private function sumCentsForStore(array $rows, int $store_id): int {
    $sum = 0;
    foreach ($rows as $row) {
      if ((int) $row->store_id === $store_id) {
        $sum += (int) ($row->amount_cents ?? 0);
      }
    }
    return $sum;
  }

  /**
   * Sums count for rows matching the given store_id.
   *
   * @param array $rows
   *   CountByStoreEventRow instances from Phase 7.
   */
  private function sumCountForStore(array $rows, int $store_id): int {
    $sum = 0;
    foreach ($rows as $row) {
      if ((int) $row->store_id === $store_id) {
        $sum += (int) ($row->count ?? 0);
      }
    }
    return $sum;
  }

  /**
   * Orders count: COUNT(commerce_order.order_id).
   *
   * Not a revenue total; kept for KPI display. Phase 7 does not provide this.
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
   * RSVPs confirmed: COUNT(rsvp_submission) via event→field_event_store.
   *
   * Phase 7 does not provide RSVP counts; kept for KPI display.
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

}
