<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Phase7\Exception;

/**
 * Thrown when a time window is missing or invalid for the requested metric.
 *
 * @internal
 *   Phase 7 isolated API. Do not use outside Phase 7 without approval.
 */
final class InvalidTimeWindowException extends AnalyticsException {
}

