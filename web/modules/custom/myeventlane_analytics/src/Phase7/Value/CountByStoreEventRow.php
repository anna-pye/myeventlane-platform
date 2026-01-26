<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Phase7\Value;

/**
 * Count metric row grouped by store + event.
 *
 * @internal
 *   Phase 7 isolated API. Do not use outside Phase 7 without approval.
 */
final readonly class CountByStoreEventRow {

  /**
   * @param int $store_id
   *   Store ID.
   * @param int $event_id
   *   Event node ID.
   * @param int $count
   *   Count value (non-negative).
   * @param list<\Drupal\myeventlane_analytics\Phase7\Value\DataIntegrityFlag> $integrity_flags
   *   Integrity flags associated with this row (never auto-corrected).
   */
  public function __construct(
    public int $store_id,
    public int $event_id,
    public int $count,
    public array $integrity_flags = [],
  ) {}

}

