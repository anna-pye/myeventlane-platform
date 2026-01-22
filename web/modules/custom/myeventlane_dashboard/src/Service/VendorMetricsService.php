<?php

declare(strict_types=1);

namespace Drupal\myeventlane_dashboard\Service;

use Drupal\commerce_price\CurrencyFormatter;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Computes vendor metrics for the dashboard.
 *
 * Requirements:
 * - Net revenue: completed orders minus refunds (from myeventlane_refund_log).
 * - Tickets sold: paid line items only (quantity summed where unit price > 0).
 * - Confirmed RSVPs: rsvp_submission status='confirmed'.
 *
 * Also provides per-event aggregation helpers (optional) used by VendorEventsService.
 */
final class VendorMetricsService implements VendorMetricsServiceInterface {

  private const DEFAULT_CURRENCY = 'AUD';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly CurrencyFormatter $currencyFormatter,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getMetrics(StoreInterface $store, array $range): array {
    $store_id = (int) $store->id();
    $start = (int) ($range['start'] ?? 0);
    $end = (int) ($range['end'] ?? time());

    $gross = $this->getGrossRevenueCents($store_id, $start, $end);
    $refunds = $this->getRefundedAmountCents($store_id, $start, $end);
    $net = max(0, $gross - $refunds);

    $tickets_sold = $this->getTicketsSoldCount($store_id, $start, $end);
    $confirmed_rsvps = $this->getConfirmedRsvpCountByStore($store_id, $start, $end);

    $net_price = $this->formatCents($net);
    $gross_price = $this->formatCents($gross);
    $refunds_price = $this->formatCents($refunds);

    $subtext = $range['label'] ?? 'Selected range';

    $items = [
      [
        'key' => 'net_revenue',
        'label' => 'Net revenue',
        'value' => $net_price,
        'subtext' => $refunds > 0
          ? sprintf('%s gross • %s refunded • %s', $gross_price, $refunds_price, $subtext)
          : sprintf('%s', $subtext),
        'url' => '',
        'state' => '',
        'provenance' => 'Completed ticket sales minus completed refunds for this store.',
      ],
      [
        'key' => 'tickets_sold',
        'label' => 'Tickets sold',
        'value' => number_format($tickets_sold),
        'subtext' => $subtext,
        'url' => '',
        'state' => '',
        'provenance' => 'Sum of paid ticket quantities from completed orders.',
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
   * Gross revenue (completed orders) summed in cents.
   */
  private function getGrossRevenueCents(int $store_id, int $start, int $end): int {
    // Commerce stores total_price as a numeric with currency_code.
    // We sum only default currency rows to avoid mixing currencies.
    $query = $this->database->select('commerce_order', 'o');
    $query->addExpression('COALESCE(SUM(o.total_price__number), 0)', 'sum_number');
    $query->condition('o.store_id', $store_id);
    // Completed orders only.
    $query->condition('o.state', 'completed');
    $query->condition('o.placed', $start, '>=');
    $query->condition('o.placed', $end, '<=');
    $query->condition('o.total_price__currency_code', self::DEFAULT_CURRENCY);

    $sum = (string) $query->execute()->fetchField();
    // total_price__number is decimal string. Convert to cents safely.
    return $this->decimalToCents($sum);
  }

  /**
   * Refunded amount in cents from myeventlane_refund_log table.
   *
   * Authoritative refund model: myeventlane_refund_log table.
   * - amount_cents: refund amount in cents
   * - status: 'completed' for successful refunds
   * - created: timestamp when refund was requested
   * - Filtered by store via order join
   */
  private function getRefundedAmountCents(int $store_id, int $start, int $end): int {
    $q = $this->database->select('myeventlane_refund_log', 'r');
    $q->join('commerce_order', 'o', 'o.order_id = r.order_id');
    $q->addExpression('COALESCE(SUM(r.amount_cents), 0)', 'sum_cents');

    $q->condition('o.store_id', $store_id);
    $q->condition('r.status', 'completed');
    $q->condition('r.created', $start, '>=');
    $q->condition('r.created', $end, '<=');

    $sum = (int) $q->execute()->fetchField();
    return max(0, $sum);
  }

  /**
   * Tickets sold count (paid line items) for completed orders in range.
   *
   * Paid line item rule:
   * - Order item unit_price > 0 (AUD)
   * - Sum quantities
   */
  private function getTicketsSoldCount(int $store_id, int $start, int $end): int {
    // Join order items to orders.
    // commerce_order_item stores unit_price__number and quantity.
    $q = $this->database->select('commerce_order_item', 'oi');
    $q->join('commerce_order', 'o', 'o.order_id = oi.order_id');
    $q->addExpression('COALESCE(SUM(oi.quantity), 0)', 'qty_sum');

    $q->condition('o.store_id', $store_id);
    $q->condition('o.state', 'completed');
    $q->condition('o.placed', $start, '>=');
    $q->condition('o.placed', $end, '<=');

    // Paid items only (AUD).
    $q->condition('oi.unit_price__currency_code', self::DEFAULT_CURRENCY);
    $q->condition('oi.unit_price__number', '0', '>');

    return (int) $q->execute()->fetchField();
  }

  /**
   * Confirmed RSVPs count across all events for a store in range.
   *
   * We count rsvp_submission entities where:
   * - status='confirmed'
   * - event_id references an event node
   * - event node field_event_store == $store_id
   * - created timestamp in range (fallback to changed if created missing)
   */
  private function getConfirmedRsvpCountByStore(int $store_id, int $start, int $end): int {
    // Entity base table per mapping: rsvp_submission.
    // We join event node fields table for field_event_store.
    $event_ref_table = 'rsvp_submission__event_id';
    if (!$this->database->schema()->tableExists($event_ref_table)) {
      $this->loggerFactory->get('myeventlane_vendor_dashboard')
        ->warning('Expected RSVP event reference table @t not found.', ['@t' => $event_ref_table]);
      return 0;
    }

    $node_store_table = 'node__field_event_store';
    if (!$this->database->schema()->tableExists($node_store_table)) {
      $this->loggerFactory->get('myeventlane_vendor_dashboard')
        ->warning('Expected event store field table @t not found.', ['@t' => $node_store_table]);
      return 0;
    }

    $q = $this->database->select('rsvp_submission', 'r');
    $q->join($event_ref_table, 're', 're.entity_id = r.id');
    $q->join($node_store_table, 'nes', 'nes.entity_id = re.event_id_target_id');

    $q->addExpression('COUNT(r.id)', 'cnt');
    // Confirmed only.
    $q->condition('r.status', 'confirmed');
    // Store isolation.
    $q->condition('nes.field_event_store_target_id', $store_id);

    // Range: prefer created if exists.
    $time_field = $this->database->schema()->fieldExists('rsvp_submission', 'created') ? 'created' : 'changed';
    if ($this->database->schema()->fieldExists('rsvp_submission', $time_field)) {
      $q->condition('r.' . $time_field, $start, '>=');
      $q->condition('r.' . $time_field, $end, '<=');
    }

    return (int) $q->execute()->fetchField();
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
    $q = $this->database->select('commerce_order_item', 'oi');
    $q->join('commerce_order', 'o', 'o.order_id = oi.order_id');
    $q->join('commerce_order_item__field_target_event', 'lnk', 'lnk.entity_id = oi.order_item_id');

    $q->addField('lnk', 'field_target_event_target_id', 'nid');
    $q->addExpression('COALESCE(SUM(oi.quantity), 0)', 'qty_sum');

    $q->condition('o.store_id', $store_id);
    $q->condition('o.state', 'completed');
    $q->condition('o.placed', $start, '>=');
    $q->condition('o.placed', $end, '<=');

    $q->condition('oi.unit_price__currency_code', self::DEFAULT_CURRENCY);
    $q->condition('oi.unit_price__number', '0', '>');

    $q->groupBy('lnk.field_target_event_target_id');

    $out = [];
    try {
      foreach ($q->execute() as $row) {
        $nid = (int) $row->nid;
        if ($nid > 0) {
          $out[$nid] = (int) $row->qty_sum;
        }
      }
    }
    catch (\Throwable $e) {
      // Return empty array if query fails (table missing or data issue).
      return [];
    }
    return $out;
  }

  /**
   * {@inheritdoc}
   */
  public function getRevenueCentsByEventForStore(int $store_id, int $start, int $end): array {
    $q = $this->database->select('commerce_order_item', 'oi');
    $q->join('commerce_order', 'o', 'o.order_id = oi.order_id');
    $q->join('commerce_order_item__field_target_event', 'lnk', 'lnk.entity_id = oi.order_item_id');

    $q->addField('lnk', 'field_target_event_target_id', 'nid');
    $q->addExpression('COALESCE(SUM(oi.total_price__number), 0)', 'sum_number');

    $q->condition('o.store_id', $store_id);
    $q->condition('o.state', 'completed');
    $q->condition('o.placed', $start, '>=');
    $q->condition('o.placed', $end, '<=');

    $q->condition('oi.total_price__currency_code', self::DEFAULT_CURRENCY);

    $q->groupBy('lnk.field_target_event_target_id');

    $out = [];
    try {
      foreach ($q->execute() as $row) {
        $nid = (int) $row->nid;
        if ($nid > 0) {
          $out[$nid] = $this->decimalToCents((string) $row->sum_number);
        }
      }
    }
    catch (\Throwable $e) {
      // Return empty array if query fails (table missing or data issue).
      return [];
    }
    return $out;
  }

  /**
   * Formats cents as a localized currency string.
   */
  private function formatCents(int $cents, string $currency = self::DEFAULT_CURRENCY): string {
    $amount = $cents / 100;
    return $this->currencyFormatter->format($amount, $currency);
  }

  /**
   * Converts a decimal string (e.g. "123.45") to integer cents (12345).
   */
  private function decimalToCents(string $decimal): int {
    $decimal = trim($decimal);
    if ($decimal === '' || $decimal === '0') {
      return 0;
    }
    // Normalize to 2dp without float.
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

  /**
   * {@inheritdoc}
   */
  public function getDebugTotals(StoreInterface $store, array $range): array {
    $store_id = (int) $store->id();
    $start = (int) ($range['start'] ?? 0);
    $end = (int) ($range['end'] ?? time());

    $gross = $this->getGrossRevenueCents($store_id, $start, $end);
    $refunds = $this->getRefundedAmountCents($store_id, $start, $end);
    $net = max(0, $gross - $refunds);

    return [
      'gross_cents' => $gross,
      'refund_cents' => $refunds,
      'net_cents' => $net,
      'tickets_sold' => $this->getTicketsSoldCount($store_id, $start, $end),
      'confirmed_rsvps' => $this->getConfirmedRsvpCountByStore($store_id, $start, $end),
    ];
  }

}
