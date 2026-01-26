<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Phase7\Value;

/**
 * Immutable data integrity flag (Phase 7).
 *
 * A flag is a stable, non-PII string code indicating an integrity concern.
 * Flags are surfaced to callers and MUST NOT be auto-corrected.
 *
 * @internal
 *   Phase 7 isolated API. Do not use outside Phase 7 without approval.
 */
final readonly class DataIntegrityFlag {

  /**
   * @param string $code
   *   Stable integrity code (non-PII).
   */
  public function __construct(
    public string $code,
  ) {}

}

