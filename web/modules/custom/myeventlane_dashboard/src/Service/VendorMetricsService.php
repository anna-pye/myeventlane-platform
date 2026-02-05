<?php

declare(strict_types=1);

namespace Drupal\myeventlane_dashboard\Service;

use Drupal\commerce_price\CurrencyFormatter;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\myeventlane_analytics\Phase7\Service\AnalyticsQueryServiceInterface;
use Drupal\myeventlane_analytics\Phase7\Value\AnalyticsQuery;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Computes vendor metrics for the dashboard.
 *
 * Revenue and tickets sold are sourced only from Phase 7
 * (myeventlane_analytics.phase7.query). No direct DB revenue queries or
 * commerce_order.total_price. Boost spend is not available to vendors.
 */
final class VendorMetricsService implements VendorMetricsServiceInterface {

  private const DEFAULT_CURRENCY = 'AUD';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly CurrencyFormatter $currencyFormatter,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly RequestStack $requestStack,
    private readonly AnalyticsQueryServiceInterface $phase7Query,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getMetrics(StoreInterface $store, array $range): array {
    $store_id = (int) $store->id();
    $start = (int) ($range['start'] ?? 0);
    $end = (int) ($range['end'] ?? time());
    $currency = $this->resolveCurrency($store);

    $q = new AnalyticsQuery(
      scope: AnalyticsQuery::SCOPE_VENDOR,
      store_ids: [],
      start_ts: $start,
      end_ts: $end,
      currency: $currency,
    );

    $net_rows = $this->phase7Query->getNetRevenue($q);
    $gross_rows = $this->phase7Query->getGrossRevenue($q);
    $refund_rows = $this->phase7Query->getRefundAmount($q);
    $tickets_rows = $this->phase7Query->getTicketsSold($q);

    $net = $this->sumCentsForStore($net_rows, $store_id);
    $gross = $this->sumCentsForStore($gross_rows, $store_id);
    $refunds = $this->sumCentsForStore($refund_rows, $store_id);
    $tickets_sold = $this->sumCountForStore($tickets_rows, $store_id);
    $confirmed_rsvps = $this->getConfirmedRsvpCountByStore($store_id, $start, $end);

    $net_price = $this->formatCents($net, $currency);
    $gross_price = $this->formatCents($gross, $currency);
    $refunds_price = $this->formatCents($refunds, $currency);
    $subtext = $range['label'] ?? 'Selected range';

    $items = [
      [
        'key' => 'net_revenue',
        'label' => 'Net revenue',
        'value' => $net_price,
        'subtext' => $refunds > 0
          ? sprintf('%s gross • %s refunded • %s', $gross_price, $refunds_price, $subtext)
          : $subtext,
        'url' => '',
        'state' => '',
        'provenance' => 'Completed ticket sales minus completed refunds for this store (Phase 7).',
      ],
      [
        'key' => 'tickets_sold',
        'label' => 'Tickets sold',
        'value' => number_format($tickets_sold),
        'subtext' => $subtext,
        'url' => '',
        'state' => '',
        'provenance' => 'Sum of paid ticket quantities from completed orders (Phase 7).',
      ],
      [
        'key' => 'confirmed_rsvps',
        'label' => 'Confirmed RSVPs',
        'value' => number_format($confirmed_rsvps),
        'subtext' => $subtext,
        'url' => '',
        'state' => '',
        'provenance' => 'Count of confirmed RSVPs linked to your events.',
      ],
    ];

    return [
      'items' => $items,
      'cache_tags' => [
        'commerce_store:' . $store_id,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmedRsvpsByEventForStore(int $store_id, int $start, int $end): array {
    $event_ref_table = 'rsvp_submission__event_id';
    $node_store_table = 'node__field_event_store';
    if (
      !$this->database->schema()->tableExists($event_ref_table) ||
      !$this->database->schema()->tableExists($node_store_table)
    ) {
      return [];
    }

    $q = $this->database->select('rsvp_submission', 'r');
    $q->join($event_ref_table, 're', 're.entity_id = r.id');
    $q->join($node_store_table, 'nes', 'nes.entity_id = re.event_id_target_id');

    $q->addField('re', 'event_id_target_id', 'nid');
    $q->addExpression('COUNT(r.id)', 'cnt');

    $q->condition('r.status', 'confirmed');
    $q->condition('nes.field_event_store_target_id', $store_id);

    $time_field = $this->database->schema()->fieldExists('rsvp_submission', 'created') ? 'created' : 'changed';
    if ($this->database->schema()->fieldExists('rsvp_submission', $time_field)) {
      $q->condition('r.' . $time_field, $start, '>=');
      $q->condition('r.' . $time_field, $end, '<=');
    }

    $q->groupBy('re.event_id_target_id');

    $out = [];
    foreach ($q->execute() as $row) {
      $out[(int) $row->nid] = (int) $row->cnt;
    }
    return $out;
  }

  /**
   * {@inheritdoc}
   */
  public function getPaidTicketsSoldByEventForStore(int $store_id, int $start, int $end): array {
    $currency = self::DEFAULT_CURRENCY;
    $q = new AnalyticsQuery(
      scope: AnalyticsQuery::SCOPE_VENDOR,
      store_ids: [],
      start_ts: $start,
      end_ts: $end,
      currency: $currency,
    );
    $rows = $this->phase7Query->getTicketsSold($q);
    $out = [];
    foreach ($rows as $row) {
      if ((int) $row->store_id === $store_id && (int) $row->event_id > 0) {
        $out[(int) $row->event_id] = ((int) ($row->count ?? 0)) + ($out[(int) $row->event_id] ?? 0);
      }
    }
    return $out;
  }

  /**
   * {@inheritdoc}
   */
  public function getRevenueCentsByEventForStore(int $store_id, int $start, int $end): array {
    $currency = self::DEFAULT_CURRENCY;
    $q = new AnalyticsQuery(
      scope: AnalyticsQuery::SCOPE_VENDOR,
      store_ids: [],
      start_ts: $start,
      end_ts: $end,
      currency: $currency,
    );
    $rows = $this->phase7Query->getNetRevenue($q);
    $out = [];
    foreach ($rows as $row) {
      if ((int) $row->store_id === $store_id && (int) $row->event_id > 0) {
        $out[(int) $row->event_id] = ((int) ($row->amount_cents ?? 0)) + ($out[(int) $row->event_id] ?? 0);
      }
    }
    return $out;
  }

  /**
   * {@inheritdoc}
   */
  public function getDebugTotals(StoreInterface $store, array $range): array {
    $store_id = (int) $store->id();
    $start = (int) ($range['start'] ?? 0);
    $end = (int) ($range['end'] ?? time());
    $currency = $this->resolveCurrency($store);

    $q = new AnalyticsQuery(
      scope: AnalyticsQuery::SCOPE_VENDOR,
      store_ids: [],
      start_ts: $start,
      end_ts: $end,
      currency: $currency,
    );

    $gross_rows = $this->phase7Query->getGrossRevenue($q);
    $refund_rows = $this->phase7Query->getRefundAmount($q);
    $net_rows = $this->phase7Query->getNetRevenue($q);
    $tickets_rows = $this->phase7Query->getTicketsSold($q);

    $gross = $this->sumCentsForStore($gross_rows, $store_id);
    $refunds = $this->sumCentsForStore($refund_rows, $store_id);
    $net = $this->sumCentsForStore($net_rows, $store_id);
    $tickets_sold = $this->sumCountForStore($tickets_rows, $store_id);
    $confirmed_rsvps = $this->getConfirmedRsvpCountByStore($store_id, $start, $end);

    return [
      'gross_cents' => $gross,
      'refund_cents' => $refunds,
      'net_cents' => $net,
      'tickets_sold' => $tickets_sold,
      'confirmed_rsvps' => $confirmed_rsvps,
    ];
  }

  /**
   * Resolves currency for the store (Phase 7 requires a currency).
   */
  private function resolveCurrency(StoreInterface $store): string {
    $code = $store->getDefaultCurrencyCode();
    return $code !== null && $code !== '' ? (string) $code : self::DEFAULT_CURRENCY;
  }

  /**
   * Sums amount_cents for rows matching the given store_id.
   *
   * @param array $rows
   *   MoneyByStoreEventCurrencyRow instances.
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
   *   CountByStoreEventRow instances.
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
   * Confirmed RSVPs count for the store in range (non–Phase 7; RSVPs unchanged).
   */
  private function getConfirmedRsvpCountByStore(int $store_id, int $start, int $end): int {
    $event_ref_table = 'rsvp_submission__event_id';
    $node_store_table = 'node__field_event_store';
    if (!$this->database->schema()->tableExists($event_ref_table) || !$this->database->schema()->tableExists($node_store_table)) {
      $this->loggerFactory->get('myeventlane_dashboard')->warning('RSVP/event store tables not found.');
      return 0;
    }

    $q = $this->database->select('rsvp_submission', 'r');
    $q->join($event_ref_table, 're', 're.entity_id = r.id');
    $q->join($node_store_table, 'nes', 'nes.entity_id = re.event_id_target_id');
    $q->addExpression('COUNT(r.id)', 'cnt');
    $q->condition('r.status', 'confirmed');
    $q->condition('nes.field_event_store_target_id', $store_id);
    $time_field = $this->database->schema()->fieldExists('rsvp_submission', 'created') ? 'created' : 'changed';
    if ($this->database->schema()->fieldExists('rsvp_submission', $time_field)) {
      $q->condition('r.' . $time_field, $start, '>=');
      $q->condition('r.' . $time_field, $end, '<=');
    }
    return (int) $q->execute()->fetchField();
  }

  private function formatCents(int $cents, string $currency = self::DEFAULT_CURRENCY): string {
    return $this->currencyFormatter->format($cents / 100, $currency);
  }

}
