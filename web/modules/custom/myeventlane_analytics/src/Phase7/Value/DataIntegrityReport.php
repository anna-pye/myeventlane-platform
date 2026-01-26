<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Phase7\Value;

/**
 * Immutable data integrity report for a metric run (Phase 7).
 *
 * This report aggregates integrity flags that apply to a metric and scope.
 * It is designed to be surfaced to callers without auto-correction.
 *
 * @internal
 *   Phase 7 isolated API. Do not use outside Phase 7 without approval.
 */
final readonly class DataIntegrityReport {

  /**
   * @param string $metric
   *   Metric name identifier (stable string).
   * @param list<int> $store_ids
   *   Effective store IDs used for the metric run.
   * @param list<\Drupal\myeventlane_analytics\Phase7\Value\DataIntegrityFlag> $flags
   *   Integrity flags for the metric run.
   */
  public function __construct(
    public string $metric,
    public array $store_ids,
    public array $flags = [],
  ) {}

}

