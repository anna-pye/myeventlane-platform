<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Phase7\Service;

use Drupal\Core\Database\Database;
use Drupal\myeventlane_analytics\Phase7\Guard\AnalyticsQueryGuard;
use Drupal\myeventlane_analytics\Phase7\Guard\AnalyticsQueryGuardInterface;
use Drupal\myeventlane_analytics\Phase7\Exception\InvariantViolationException;
use Drupal\myeventlane_analytics\Phase7\Scope\AnalyticsScopeResolverInterface;
use Drupal\myeventlane_analytics\Phase7\Value\AnalyticsQuery;
use Drupal\myeventlane_analytics\Phase7\Value\CountByStoreEventRow;
use Drupal\myeventlane_analytics\Phase7\Value\MoneyByStoreEventCurrencyRow;

/**
 * Phase 7 analytics query service (orchestration shell only).
 *
 * This service performs:
 * 1) Scope resolution
 * 2) Guardrail enforcement
 * 3) Returns empty typed arrays (no data access in Step 5.3)
 *
 * @internal
 *   Phase 7 isolated API. Do not use outside Phase 7 without approval.
 */
final class AnalyticsQueryService implements AnalyticsQueryServiceInterface {

  /**
   * Constructs the service.
   *
   * @param \Drupal\myeventlane_analytics\Phase7\Scope\AnalyticsScopeResolverInterface $scopeResolver
   *   The scope resolver.
   * @param \Drupal\myeventlane_analytics\Phase7\Guard\AnalyticsQueryGuardInterface $guard
   *   The guardrails service.
   */
  public function __construct(
    private readonly AnalyticsScopeResolverInterface $scopeResolver,
    private readonly AnalyticsQueryGuardInterface $guard,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getGrossRevenue(AnalyticsQuery $query): array {
    $effective_store_ids = $this->scopeResolver->resolveEffectiveStoreIds($query);
    $metric = AnalyticsQueryGuard::METRIC_GROSS_REVENUE;

    // Guard assertions (strict order per Phase 7 requirements).
    $this->guard->assertValidQueryForMoneyMetric($query, $metric);
    $this->guard->assertScopeRules($query, $effective_store_ids);
    $this->guard->assertNoSemanticMixing($metric);
    $this->guard->assertNoCurrencyMixing($query);
    $this->guard->assertOrderItemAnchoringRequired($metric);

    $connection = Database::getConnection();

    // Fail-closed if required schema is missing (prevents silent leakage).
    $required_tables = [
      'commerce_order_item',
      'commerce_order',
      'commerce_order_item__field_target_event',
      'node__field_event_store',
    ];
    foreach ($required_tables as $table) {
      if (!$connection->schema()->tableExists($table)) {
        throw new InvariantViolationException(sprintf('Required analytics table missing: %s', $table));
      }
    }

    $start = (int) $query->start_ts;
    $end = (int) $query->end_ts;

    // Currency is required for money metrics and validated by the guard.
    $currency = (string) $query->currency;

    // Fail-closed if any paid ticket item exists in the scope/window with a
    // currency other than the requested currency (prevents accidental mixing).
    $mismatch = $connection->select('commerce_order_item', 'oi');
    $mismatch->join('commerce_order', 'o', 'o.order_id = oi.order_id');
    $mismatch->join('commerce_order_item__field_target_event', 'lnk', 'lnk.entity_id = oi.order_item_id');
    $mismatch->join('node__field_event_store', 'nes', 'nes.entity_id = lnk.field_target_event_target_id');
    $mismatch->addExpression('COUNT(oi.order_item_id)', 'cnt');
    $mismatch->condition('o.state', 'completed');
    $mismatch->condition('o.placed', $start, '>=');
    $mismatch->condition('o.placed', $end, '<=');
    $mismatch->condition('oi.unit_price__number', '0', '>');
    $mismatch->condition('oi.type', 'boost', '<>');
    $mismatch->condition('nes.field_event_store_target_id', $effective_store_ids, 'IN');
    $or = $mismatch->orConditionGroup()
      ->condition('oi.unit_price__currency_code', $currency, '<>')
      ->isNull('oi.unit_price__currency_code');
    $mismatch->condition($or);
    $mismatch_count = (int) $mismatch->execute()->fetchField();
    if ($mismatch_count > 0) {
      throw new InvariantViolationException('Currency mismatch detected for gross revenue query.');
    }

    // Gross Revenue (locked definition):
    // - Sum paid ticket order item amounts (unit_price * quantity)
    // - Completed orders only
    // - Event-linked via field_target_event
    // - Allocated by order-item -> event -> store
    // - Single currency per call
    // - Grouped by store_id + event_id + currency
    $q = $connection->select('commerce_order_item', 'oi');
    $q->join('commerce_order', 'o', 'o.order_id = oi.order_id');
    $q->join('commerce_order_item__field_target_event', 'lnk', 'lnk.entity_id = oi.order_item_id');
    $q->join('node__field_event_store', 'nes', 'nes.entity_id = lnk.field_target_event_target_id');

    $q->addField('nes', 'field_event_store_target_id', 'store_id');
    $q->addField('lnk', 'field_target_event_target_id', 'event_id');
    $q->addField('oi', 'unit_price__currency_code', 'currency');
    // Sum in cents, rounded to the nearest cent.
    $q->addExpression('COALESCE(SUM(ROUND(oi.unit_price__number * oi.quantity * 100)), 0)', 'amount_cents');

    $q->condition('o.state', 'completed');
    $q->condition('o.placed', $start, '>=');
    $q->condition('o.placed', $end, '<=');
    $q->condition('oi.unit_price__number', '0', '>');
    $q->condition('oi.unit_price__currency_code', $currency);
    $q->condition('oi.type', 'boost', '<>');
    $q->condition('nes.field_event_store_target_id', $effective_store_ids, 'IN');

    $q->groupBy('nes.field_event_store_target_id');
    $q->groupBy('lnk.field_target_event_target_id');
    $q->groupBy('oi.unit_price__currency_code');

    /** @var list<\Drupal\myeventlane_analytics\Phase7\Value\MoneyByStoreEventCurrencyRow> $rows */
    $rows = [];
    foreach ($q->execute() as $row) {
      $store_id = (int) ($row->store_id ?? 0);
      $event_id = (int) ($row->event_id ?? 0);
      $row_currency = (string) ($row->currency ?? '');
      $amount_cents = (int) ($row->amount_cents ?? 0);

      if ($store_id <= 0 || $event_id <= 0 || $row_currency === '') {
        throw new InvariantViolationException('Invalid aggregation key for Gross Revenue.');
      }

      $rows[] = new MoneyByStoreEventCurrencyRow(
        store_id: $store_id,
        event_id: $event_id,
        currency: $row_currency,
        amount_cents: $amount_cents,
        integrity_flags: [],
      );
    }

    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  public function getNetRevenue(AnalyticsQuery $query): array {
    $effective_store_ids = $this->scopeResolver->resolveEffectiveStoreIds($query);
    $this->guard->assertScopeRules($query, $effective_store_ids);
    $this->guard->assertOrderItemAnchoringRequired(AnalyticsQueryGuard::METRIC_NET_REVENUE);
    $this->guard->assertValidQueryForMoneyMetric($query, AnalyticsQueryGuard::METRIC_NET_REVENUE);

    /** @var list<\Drupal\myeventlane_analytics\Phase7\Value\MoneyByStoreEventCurrencyRow> $rows */
    $rows = [];
    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  public function getTicketsSold(AnalyticsQuery $query): array {
    $effective_store_ids = $this->scopeResolver->resolveEffectiveStoreIds($query);

    // Guard assertions (strict order per Phase 7 requirements).
    $this->guard->assertValidQueryForCountMetric($query, AnalyticsQueryGuard::METRIC_TICKETS_SOLD);
    $this->guard->assertScopeRules($query, $effective_store_ids);
    $this->guard->assertNoSemanticMixing(AnalyticsQueryGuard::METRIC_TICKETS_SOLD);
    $this->guard->assertOrderItemAnchoringRequired(AnalyticsQueryGuard::METRIC_TICKETS_SOLD);

    $connection = Database::getConnection();

    // Fail-closed if required schema is missing (prevents silent leakage).
    $required_tables = [
      'commerce_order_item',
      'commerce_order',
      'commerce_order_item__field_target_event',
      'node__field_event_store',
    ];
    foreach ($required_tables as $table) {
      if (!$connection->schema()->tableExists($table)) {
        throw new InvariantViolationException(sprintf('Required analytics table missing: %s', $table));
      }
    }

    $start = (int) $query->start_ts;
    $end = (int) $query->end_ts;

    // Tickets Sold (locked definition):
    // - Count PAID order items (unit_price > 0)
    // - Order item linked to an event (field_target_event)
    // - Order is completed
    // - Order placed timestamp within [start_ts, end_ts]
    // - Allocate by order-item -> event -> store (event store field)
    // - Group by store_id + event_id
    // - Count order items, NOT quantities
    $q = $connection->select('commerce_order_item', 'oi');
    $q->join('commerce_order', 'o', 'o.order_id = oi.order_id');
    $q->join('commerce_order_item__field_target_event', 'lnk', 'lnk.entity_id = oi.order_item_id');
    $q->join('node__field_event_store', 'nes', 'nes.entity_id = lnk.field_target_event_target_id');

    $q->addField('nes', 'field_event_store_target_id', 'store_id');
    $q->addField('lnk', 'field_target_event_target_id', 'event_id');
    $q->addExpression('COUNT(oi.order_item_id)', 'cnt');

    $q->condition('o.state', 'completed');
    $q->condition('o.placed', $start, '>=');
    $q->condition('o.placed', $end, '<=');
    $q->condition('oi.unit_price__number', '0', '>');
    // Exclude non-ticket admin-revenue items that may also target events.
    $q->condition('oi.type', 'boost', '<>');

    // Store isolation: enforce via event->store linkage.
    $q->condition('nes.field_event_store_target_id', $effective_store_ids, 'IN');

    $q->groupBy('nes.field_event_store_target_id');
    $q->groupBy('lnk.field_target_event_target_id');

    /** @var list<\Drupal\myeventlane_analytics\Phase7\Value\CountByStoreEventRow> $rows */
    $rows = [];
    foreach ($q->execute() as $row) {
      $store_id = (int) ($row->store_id ?? 0);
      $event_id = (int) ($row->event_id ?? 0);
      $count = (int) ($row->cnt ?? 0);

      if ($store_id <= 0 || $event_id <= 0) {
        throw new InvariantViolationException('Invalid aggregation key for Tickets Sold.');
      }

      $rows[] = new CountByStoreEventRow(
        store_id: $store_id,
        event_id: $event_id,
        count: $count,
        integrity_flags: [],
      );
    }

    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  public function getReservedRsvps(AnalyticsQuery $query): array {
    $effective_store_ids = $this->scopeResolver->resolveEffectiveStoreIds($query);
    $this->guard->assertScopeRules($query, $effective_store_ids);
    $this->guard->assertValidQueryForCountMetric($query, AnalyticsQueryGuard::METRIC_RSVPS_RESERVED);

    /** @var list<\Drupal\myeventlane_analytics\Phase7\Value\CountByStoreEventRow> $rows */
    $rows = [];
    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  public function getRefundAmount(AnalyticsQuery $query): array {
    $effective_store_ids = $this->scopeResolver->resolveEffectiveStoreIds($query);
    $metric = AnalyticsQueryGuard::METRIC_REFUND_AMOUNT;

    // Guard assertions (strict order per Phase 7 requirements).
    $this->guard->assertValidQueryForMoneyMetric($query, $metric);
    $this->guard->assertScopeRules($query, $effective_store_ids);
    $this->guard->assertNoSemanticMixing($metric);
    $this->guard->assertNoCurrencyMixing($query);
    $this->guard->assertOrderItemAnchoringRequired($metric);

    $connection = Database::getConnection();

    // Fail-closed if required schema is missing (prevents silent leakage).
    $required_tables = [
      'myeventlane_refund_log',
      'commerce_order',
      'commerce_order_item',
      'commerce_order_item__field_target_event',
      'node__field_event_store',
    ];
    foreach ($required_tables as $table) {
      if (!$connection->schema()->tableExists($table)) {
        throw new InvariantViolationException(sprintf('Required analytics table missing: %s', $table));
      }
    }

    $start = (int) $query->start_ts;
    $end = (int) $query->end_ts;

    // Currency is required for money metrics and validated by the guard.
    $currency = (string) $query->currency;
    $currency_lower = strtolower($currency);

    // Build a unique set of (order_id, event_id) pairs that exist on ANY
    // order item with field_target_event.
    $any_items = $connection->select('commerce_order_item', 'oi_any');
    $any_items->join('commerce_order_item__field_target_event', 'lnk_any', 'lnk_any.entity_id = oi_any.order_item_id');
    $any_items->addField('oi_any', 'order_id', 'order_id');
    $any_items->addField('lnk_any', 'field_target_event_target_id', 'event_id');
    $any_items->distinct();

    // Build a unique set of (order_id, event_id) pairs for QUALIFYING ticket
    // order items (paid, non-boost).
    $ticket_items = $connection->select('commerce_order_item', 'oi');
    $ticket_items->join('commerce_order_item__field_target_event', 'lnk', 'lnk.entity_id = oi.order_item_id');
    $ticket_items->addField('oi', 'order_id', 'order_id');
    $ticket_items->addField('lnk', 'field_target_event_target_id', 'event_id');
    $ticket_items->condition('oi.unit_price__number', '0', '>');
    $ticket_items->condition('oi.type', 'boost', '<>');
    $ticket_items->distinct();

    // Fail-closed if there are completed refund rows in scope that cannot be
    // linked to ANY order item for the given (order_id, event_id).
    $unlinked = $connection->select('myeventlane_refund_log', 'r');
    $unlinked->join('commerce_order', 'o', 'o.order_id = r.order_id');
    $unlinked->join('node__field_event_store', 'nes', 'nes.entity_id = r.event_id');
    $unlinked->leftJoin($any_items, 'any', 'any.order_id = r.order_id AND any.event_id = r.event_id');
    $unlinked->addExpression('COUNT(r.id)', 'cnt');
    $unlinked->condition('r.status', 'completed');
    $unlinked->condition('r.amount_cents', 0, '>');
    $unlinked->condition('o.state', 'completed');
    $unlinked->condition('o.placed', $start, '>=');
    $unlinked->condition('o.placed', $end, '<=');
    $unlinked->condition('nes.field_event_store_target_id', $effective_store_ids, 'IN');
    $unlinked->isNull('any.order_id');
    $unlinked_count = (int) $unlinked->execute()->fetchField();
    if ($unlinked_count > 0) {
      throw new InvariantViolationException('Refund rows exist without valid order-item linkage.');
    }

    // Fail-closed if any includable refund rows exist in the scope/window with a
    // currency other than the requested currency (prevents accidental mixing).
    $currency_mismatch = $connection->select('myeventlane_refund_log', 'r');
    $currency_mismatch->join('commerce_order', 'o', 'o.order_id = r.order_id');
    $currency_mismatch->join('node__field_event_store', 'nes', 'nes.entity_id = r.event_id');
    $currency_mismatch->join($ticket_items, 't', 't.order_id = r.order_id AND t.event_id = r.event_id');
    $currency_mismatch->addExpression('COUNT(r.id)', 'cnt');
    $currency_mismatch->condition('r.status', 'completed');
    $currency_mismatch->condition('r.amount_cents', 0, '>');
    $currency_mismatch->condition('o.state', 'completed');
    $currency_mismatch->condition('o.placed', $start, '>=');
    $currency_mismatch->condition('o.placed', $end, '<=');
    $currency_mismatch->condition('nes.field_event_store_target_id', $effective_store_ids, 'IN');
    $currency_mismatch->where('r.currency IS NULL OR LOWER(r.currency) <> :currency', [
      ':currency' => $currency_lower,
    ]);
    $currency_mismatch_count = (int) $currency_mismatch->execute()->fetchField();
    if ($currency_mismatch_count > 0) {
      throw new InvariantViolationException('Currency mismatch detected for refund amount query.');
    }

    // Refund Amount (locked definition):
    // - Sum completed refund amounts (positive cents)
    // - Completed orders only
    // - Event-linked refunds only (validated via ANY + ticket linkage)
    // - Allocate by order-item -> event -> store (event store field)
    // - Single currency per call
    // - Grouped by store_id + event_id + currency
    $q = $connection->select('myeventlane_refund_log', 'r');
    $q->join('commerce_order', 'o', 'o.order_id = r.order_id');
    $q->join('node__field_event_store', 'nes', 'nes.entity_id = r.event_id');
    $q->join($ticket_items, 't', 't.order_id = r.order_id AND t.event_id = r.event_id');

    $q->addField('nes', 'field_event_store_target_id', 'store_id');
    $q->addField('r', 'event_id', 'event_id');
    $q->addExpression('COALESCE(SUM(r.amount_cents), 0)', 'amount_cents');

    $q->condition('r.status', 'completed');
    $q->condition('r.amount_cents', 0, '>');
    $q->condition('o.state', 'completed');
    $q->condition('o.placed', $start, '>=');
    $q->condition('o.placed', $end, '<=');
    $q->condition('nes.field_event_store_target_id', $effective_store_ids, 'IN');
    $q->where('LOWER(r.currency) = :currency', [':currency' => $currency_lower]);

    $q->groupBy('nes.field_event_store_target_id');
    $q->groupBy('r.event_id');

    /** @var list<\Drupal\myeventlane_analytics\Phase7\Value\MoneyByStoreEventCurrencyRow> $rows */
    $rows = [];
    foreach ($q->execute() as $row) {
      $store_id = (int) ($row->store_id ?? 0);
      $event_id = (int) ($row->event_id ?? 0);
      $amount_cents = (int) ($row->amount_cents ?? 0);

      if ($store_id <= 0 || $event_id <= 0) {
        throw new InvariantViolationException('Invalid aggregation key for Refund Amount.');
      }

      $rows[] = new MoneyByStoreEventCurrencyRow(
        store_id: $store_id,
        event_id: $event_id,
        currency: $currency,
        amount_cents: $amount_cents,
        integrity_flags: [],
      );
    }

    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  public function getActiveEvents(AnalyticsQuery $query): array {
    $effective_store_ids = $this->scopeResolver->resolveEffectiveStoreIds($query);
    $this->guard->assertScopeRules($query, $effective_store_ids);
    $this->guard->assertValidQueryForCountMetric($query, AnalyticsQueryGuard::METRIC_ACTIVE_EVENTS);

    /** @var list<\Drupal\myeventlane_analytics\Phase7\Value\CountByStoreRow> $rows */
    $rows = [];
    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelledEvents(AnalyticsQuery $query): array {
    $effective_store_ids = $this->scopeResolver->resolveEffectiveStoreIds($query);
    $this->guard->assertScopeRules($query, $effective_store_ids);
    $this->guard->assertValidQueryForCountMetric($query, AnalyticsQueryGuard::METRIC_CANCELLED_EVENTS);

    /** @var list<\Drupal\myeventlane_analytics\Phase7\Value\CountByStoreRow> $rows */
    $rows = [];
    return $rows;
  }

}

