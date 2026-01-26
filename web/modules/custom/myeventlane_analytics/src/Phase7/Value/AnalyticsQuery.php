<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Phase7\Value;

/**
 * Immutable Phase 7 analytics query input.
 *
 * This value object represents the caller intent only. Authorization and scope
 * resolution are handled server-side (see ScopeResolver + Guard).
 *
 * Timestamps are UNIX epoch seconds (UTC).
 *
 * @internal
 *   Phase 7 isolated API. Do not use outside Phase 7 without approval.
 */
final readonly class AnalyticsQuery {

  /**
   * Vendor scope: effective store is derived server-side; store IDs are ignored.
   */
  public const SCOPE_VENDOR = 'vendor';

  /**
   * Admin scope: store IDs are required and validated server-side.
   */
  public const SCOPE_ADMIN = 'admin';

  /**
   * @param string $scope
   *   Scope identifier. Expected values: self::SCOPE_VENDOR or self::SCOPE_ADMIN.
   * @param list<int> $store_ids
   *   Requested store IDs (admin scope only). Ignored for vendor scope.
   * @param int|null $start_ts
   *   Range start timestamp (inclusive) in epoch seconds.
   * @param int|null $end_ts
   *   Range end timestamp (exclusive or inclusive per metric contract) in epoch seconds.
   * @param string|null $currency
   *   ISO currency code (required for money metrics).
   */
  public function __construct(
    public string $scope,
    public array $store_ids = [],
    public ?int $start_ts = NULL,
    public ?int $end_ts = NULL,
    public ?string $currency = NULL,
  ) {}

}

