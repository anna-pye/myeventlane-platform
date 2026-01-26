<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Phase7\Exception;

/**
 * Thrown when an analytics query is not permitted for the caller.
 *
 * Implementations MUST fail-closed and never return an empty result set when
 * access is denied.
 *
 * @internal
 *   Phase 7 isolated API. Do not use outside Phase 7 without approval.
 */
final class AccessDeniedAnalyticsException extends AnalyticsException {
}

