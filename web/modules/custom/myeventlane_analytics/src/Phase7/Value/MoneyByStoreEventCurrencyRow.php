<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Phase7\Value;

/**
 * Money metric row grouped by store + event + currency.
 *
 * Amounts are expressed in integer cents to avoid floating point issues.
 *
 * @internal
 *   Phase 7 isolated API. Do not use outside Phase 7 without approval.
 */
final readonly class MoneyByStoreEventCurrencyRow {

  /**
   * @param int $store_id
   *   Store ID.
   * @param int $event_id
   *   Event node ID.
   * @param string $currency
   *   ISO currency code.
   * @param int $amount_cents
   *   Amount in cents.
   * @param list<\Drupal\myeventlane_analytics\Phase7\Value\DataIntegrityFlag> $integrity_flags
   *   Integrity flags associated with this row (never auto-corrected).
   */
  public function __construct(
    public int $store_id,
    public int $event_id,
    public string $currency,
    public int $amount_cents,
    public array $integrity_flags = [],
  ) {}

}

