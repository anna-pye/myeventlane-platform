<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Phase7\Exception;

/**
 * Thrown when a Phase 7 analytics invariant is violated (fail-closed).
 *
 * This exception is used for conditions that should never occur if upstream
 * invariants and assumptions hold, and must be surfaced loudly.
 *
 * @internal
 *   Phase 7 isolated API. Do not use outside Phase 7 without approval.
 */
final class InvariantViolationException extends AnalyticsException {
}

