<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Phase7\Guard;

use Drupal\myeventlane_analytics\Phase7\Value\AnalyticsQuery;

/**
 * Phase 7 analytics guardrails contract (fail-closed).
 *
 * Implementations MUST throw fail-closed exceptions for any violation.
 *
 * @internal
 *   Phase 7 isolated API. Do not use outside Phase 7 without approval.
 */
interface AnalyticsQueryGuardInterface {

  /**
   * Asserts query invariants for money metrics (e.g. currency required).
   *
   * @param string $metric
   *   Metric name identifier (stable string).
   */
  public function assertValidQueryForMoneyMetric(AnalyticsQuery $query, string $metric): void;

  /**
   * Asserts query invariants for count metrics.
   *
   * @param string $metric
   *   Metric name identifier (stable string).
   */
  public function assertValidQueryForCountMetric(AnalyticsQuery $query, string $metric): void;

  /**
   * Asserts scope rules for the query and effective store IDs.
   *
   * @param list<int> $effective_store_ids
   *   Effective store IDs derived server-side.
   */
  public function assertScopeRules(AnalyticsQuery $query, array $effective_store_ids): void;

  /**
   * Asserts a metric does not semantically mix incompatible concepts.
   *
   * @param string $metric
   *   Metric name identifier (stable string).
   */
  public function assertNoSemanticMixing(string $metric): void;

  /**
   * Asserts currency constraints for the query (fail-closed).
   */
  public function assertNoCurrencyMixing(AnalyticsQuery $query): void;

  /**
   * Asserts a metric requires order-item anchoring (fail-closed).
   *
   * @param string $metric
   *   Metric name identifier (stable string).
   */
  public function assertOrderItemAnchoringRequired(string $metric): void;

}

