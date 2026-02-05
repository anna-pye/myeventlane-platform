<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Phase7\Guard;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\myeventlane_analytics\Phase7\Exception\AccessDeniedAnalyticsException;
use Drupal\myeventlane_analytics\Phase7\Exception\InvalidScopeException;
use Drupal\myeventlane_analytics\Phase7\Exception\InvalidTimeWindowException;
use Drupal\myeventlane_analytics\Phase7\Exception\InvariantViolationException;
use Drupal\myeventlane_analytics\Phase7\Exception\MissingCurrencyException;
use Drupal\myeventlane_analytics\Phase7\Value\AnalyticsQuery;

/**
 * Phase 7 analytics guardrails (strict, fail-closed).
 *
 * Guard responsibilities:
 * - Validate timestamps (range vs point-in-time by metric).
 * - Validate required currency for money metrics and prevent currency mixing.
 * - Enforce no semantic mixing (locked metric set).
 * - Enforce order-item anchoring requirement by metric.
 * - Enforce vendor vs admin scope rules.
 *
 * Logging rules:
 * - Guard is the ONLY place that logs violations.
 * - Use logger.channel.myeventlane_analytics.
 * - Log only on violations (warning or error).
 * - NEVER include PII.
 * - Always include store_id(s), metric name, violation code.
 *
 * @internal
 *   Phase 7 isolated API. Do not use outside Phase 7 without approval.
 */
final class AnalyticsQueryGuard implements AnalyticsQueryGuardInterface {

  /**
   * Canonical locked metric names (see docs/analytics-metrics.md).
   */
  public const METRIC_NET_REVENUE = 'Net Revenue';
  public const METRIC_GROSS_REVENUE = 'Gross Revenue';
  public const METRIC_TICKETS_SOLD = 'Tickets Sold';
  public const METRIC_RSVPS_RESERVED = 'RSVPs (Reserved)';
  public const METRIC_REFUND_AMOUNT = 'Refund Amount';
  public const METRIC_ACTIVE_EVENTS = 'Active Events';
  public const METRIC_CANCELLED_EVENTS = 'Cancelled Events';

  /**
   * All supported Phase 7 metrics (fail-closed allow-list).
   *
   * @var list<string>
   */
  private const ALL_METRICS = [
    self::METRIC_NET_REVENUE,
    self::METRIC_GROSS_REVENUE,
    self::METRIC_TICKETS_SOLD,
    self::METRIC_RSVPS_RESERVED,
    self::METRIC_REFUND_AMOUNT,
    self::METRIC_ACTIVE_EVENTS,
    self::METRIC_CANCELLED_EVENTS,
  ];

  /**
   * Money metrics (require currency + range timestamps).
   *
   * @var list<string>
   */
  private const MONEY_METRICS = [
    self::METRIC_NET_REVENUE,
    self::METRIC_GROSS_REVENUE,
    self::METRIC_REFUND_AMOUNT,
  ];

  /**
   * Range count metrics (require start_ts + end_ts).
   *
   * @var list<string>
   */
  private const RANGE_COUNT_METRICS = [
    self::METRIC_TICKETS_SOLD,
    self::METRIC_RSVPS_RESERVED,
    self::METRIC_CANCELLED_EVENTS,
  ];

  /**
   * Point-in-time metrics (require end_ts only).
   *
   * @var list<string>
   */
  private const POINT_IN_TIME_METRICS = [
    self::METRIC_ACTIVE_EVENTS,
  ];

  /**
   * Metrics that must be order-item anchored (bookings/refunds/tickets sold).
   *
   * @var list<string>
   */
  private const ORDER_ITEM_ANCHORED_METRICS = [
    self::METRIC_NET_REVENUE,
    self::METRIC_GROSS_REVENUE,
    self::METRIC_TICKETS_SOLD,
    self::METRIC_REFUND_AMOUNT,
  ];

  /**
   * Constructs the guard.
   *
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The myeventlane_analytics logger channel.
   */
  public function __construct(
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function assertValidQueryForMoneyMetric(AnalyticsQuery $query, string $metric): void {
    $this->assertNoSemanticMixing($metric);

    if (!in_array($metric, self::MONEY_METRICS, TRUE)) {
      $this->logViolation('error', $metric, 'metric_type_mismatch_money', $query);
      throw new InvariantViolationException('Metric is not a money metric.');
    }

    $this->assertValidRangeWindow($query, $metric);
    $this->assertNoCurrencyMixing($query);
  }

  /**
   * {@inheritdoc}
   */
  public function assertValidQueryForCountMetric(AnalyticsQuery $query, string $metric): void {
    $this->assertNoSemanticMixing($metric);

    if (in_array($metric, self::MONEY_METRICS, TRUE)) {
      $this->logViolation('error', $metric, 'metric_type_mismatch_count', $query);
      throw new InvariantViolationException('Metric is not a count metric.');
    }

    if (in_array($metric, self::RANGE_COUNT_METRICS, TRUE)) {
      $this->assertValidRangeWindow($query, $metric);
    }
    elseif (in_array($metric, self::POINT_IN_TIME_METRICS, TRUE)) {
      $this->assertValidPointInTimeWindow($query, $metric);
    }
    else {
      // This should be unreachable due to the allow-list.
      $this->logViolation('error', $metric, 'metric_time_category_unknown', $query);
      throw new InvariantViolationException('Metric time category is unknown.');
    }

    // Prevent semantic/currency mixing: count metrics must not accept currency.
    if ($query->currency !== NULL) {
      $this->logViolation('warning', $metric, 'currency_not_allowed_for_count_metric', $query);
      throw new InvariantViolationException('Currency is not allowed for count metrics.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function assertScopeRules(AnalyticsQuery $query, array $effective_store_ids): void {
    $effective_store_ids = $this->normalizeStoreIdList($effective_store_ids);

    if ($query->scope === AnalyticsQuery::SCOPE_VENDOR) {
      if (count($effective_store_ids) < 1) {
        $this->logViolation('warning', 'scope', 'vendor_scope_requires_at_least_one_store', $query, [
          'effective_store_ids' => $effective_store_ids,
        ]);
        throw new AccessDeniedAnalyticsException('Vendor scope requires at least one effective store.');
      }
      return;
    }

    if ($query->scope === AnalyticsQuery::SCOPE_ADMIN) {
      if ($query->store_ids === []) {
        $this->logViolation('warning', 'scope', 'admin_scope_missing_store_ids', $query, [
          'effective_store_ids' => $effective_store_ids,
        ]);
        throw new AccessDeniedAnalyticsException('Admin scope requires one or more store IDs.');
      }

      if ($effective_store_ids === []) {
        $this->logViolation('warning', 'scope', 'admin_scope_missing_effective_store_ids', $query);
        throw new AccessDeniedAnalyticsException('Admin scope requires effective store IDs.');
      }

      $requested = $this->normalizeStoreIdList($query->store_ids);
      if ($requested !== $effective_store_ids) {
        $this->logViolation('warning', 'scope', 'admin_scope_store_ids_mismatch', $query, [
          'effective_store_ids' => $effective_store_ids,
        ]);
        throw new AccessDeniedAnalyticsException('Admin scope store IDs mismatch.');
      }

      return;
    }

    $this->logViolation('warning', 'scope', 'invalid_scope', $query, [
      'effective_store_ids' => $effective_store_ids,
    ]);
    throw new InvalidScopeException('Invalid analytics scope.');
  }

  /**
   * {@inheritdoc}
   */
  public function assertNoSemanticMixing(string $metric): void {
    if (!in_array($metric, self::ALL_METRICS, TRUE)) {
      // Unknown metric is a fail-closed violation.
      $this->logger->error('Phase7 analytics guardrail violation: unknown metric.', [
        'metric' => $metric,
        'violation_code' => 'unknown_metric',
        // No store IDs here because we may not have a query context.
      ]);
      throw new InvariantViolationException('Unknown analytics metric.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function assertNoCurrencyMixing(AnalyticsQuery $query): void {
    if ($query->currency === NULL) {
      $this->logViolation('warning', 'money', 'missing_currency', $query);
      throw new MissingCurrencyException('Currency is required for money metrics.');
    }

    $currency = (string) $query->currency;
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
      $this->logViolation('warning', 'money', 'invalid_currency_code', $query);
      throw new InvariantViolationException('Invalid currency code.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function assertOrderItemAnchoringRequired(string $metric): void {
    $this->assertNoSemanticMixing($metric);

    if (!in_array($metric, self::ORDER_ITEM_ANCHORED_METRICS, TRUE)) {
      // This is a developer-path violation: calling anchoring-required for a
      // metric that must not be order-item anchored (e.g. RSVP / event counts).
      $this->logger->error('Phase7 analytics guardrail violation: anchoring mismatch.', [
        'metric' => $metric,
        'violation_code' => 'order_item_anchoring_not_applicable',
      ]);
      throw new InvariantViolationException('Order-item anchoring is not applicable for this metric.');
    }
  }

  /**
   * Validates a range reporting window.
   *
   * @throws \Drupal\myeventlane_analytics\Phase7\Exception\InvalidTimeWindowException
   *   If timestamps are missing or invalid.
   */
  private function assertValidRangeWindow(AnalyticsQuery $query, string $metric): void {
    if ($query->start_ts === NULL || $query->end_ts === NULL) {
      $this->logViolation('warning', $metric, 'missing_range_timestamps', $query);
      throw new InvalidTimeWindowException('Range metrics require start_ts and end_ts.');
    }

    $start = (int) $query->start_ts;
    $end = (int) $query->end_ts;

    if ($start <= 0 || $end <= 0 || $start >= $end) {
      $this->logViolation('warning', $metric, 'invalid_range_timestamps', $query);
      throw new InvalidTimeWindowException('Invalid time window.');
    }
  }

  /**
   * Validates a point-in-time reporting window.
   *
   * @throws \Drupal\myeventlane_analytics\Phase7\Exception\InvalidTimeWindowException
   *   If timestamps are missing or invalid.
   */
  private function assertValidPointInTimeWindow(AnalyticsQuery $query, string $metric): void {
    if ($query->end_ts === NULL) {
      $this->logViolation('warning', $metric, 'missing_end_ts', $query);
      throw new InvalidTimeWindowException('Point-in-time metrics require end_ts.');
    }

    if ($query->start_ts !== NULL) {
      $this->logViolation('warning', $metric, 'start_ts_not_allowed_for_point_in_time', $query);
      throw new InvalidTimeWindowException('Point-in-time metrics must not include start_ts.');
    }

    $end = (int) $query->end_ts;
    if ($end <= 0) {
      $this->logViolation('warning', $metric, 'invalid_end_ts', $query);
      throw new InvalidTimeWindowException('Invalid end_ts.');
    }
  }

  /**
   * Normalizes and validates a list of store IDs (fail-closed).
   *
   * @param array $store_ids
   *   Store IDs.
   *
   * @return list<int>
   *   Unique, sorted store IDs.
   */
  private function normalizeStoreIdList(array $store_ids): array {
    $unique = [];
    foreach ($store_ids as $store_id) {
      $store_id = (int) $store_id;
      if ($store_id <= 0) {
        continue;
      }
      $unique[$store_id] = TRUE;
    }
    $normalized = array_keys($unique);
    sort($normalized, SORT_NUMERIC);
    /** @var list<int> $normalized */
    return $normalized;
  }

  /**
   * Logs a guardrail violation.
   *
   * @param 'warning'|'error' $level
   *   Log level.
   * @param string $metric
   *   Metric name identifier.
   * @param string $violation_code
   *   Stable violation code.
   * @param \Drupal\myeventlane_analytics\Phase7\Value\AnalyticsQuery $query
   *   The analytics query.
   * @param array<string, mixed> $extra
   *   Additional safe context. MUST NOT include PII.
   */
  private function logViolation(
    string $level,
    string $metric,
    string $violation_code,
    AnalyticsQuery $query,
    array $extra = [],
  ): void {
    $context = [
      'metric' => $metric,
      'violation_code' => $violation_code,
      'scope' => $query->scope,
      // Store IDs: use requested IDs (admin) or empty (vendor ignored).
      'store_ids' => $this->normalizeStoreIdList($query->store_ids),
    ] + $extra;

    if ($level === 'error') {
      $this->logger->error('Phase7 analytics guardrail violation.', $context);
      return;
    }

    $this->logger->warning('Phase7 analytics guardrail violation.', $context);
  }

}

