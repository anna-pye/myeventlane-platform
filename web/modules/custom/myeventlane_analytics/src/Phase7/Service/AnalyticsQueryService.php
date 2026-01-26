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
    $this->guard->assertScopeRules($query, $effective_store_ids);
    $this->guard->assertOrderItemAnchoringRequired(AnalyticsQueryGuard::METRIC_REFUND_AMOUNT);
    $this->guard->assertValidQueryForMoneyMetric($query, AnalyticsQueryGuard::METRIC_REFUND_AMOUNT);

    /** @var list<\Drupal\myeventlane_analytics\Phase7\Value\MoneyByStoreEventCurrencyRow> $rows */
    $rows = [];
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

