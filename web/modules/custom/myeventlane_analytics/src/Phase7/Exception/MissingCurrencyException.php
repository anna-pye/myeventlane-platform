<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Phase7\Exception;

/**
 * Thrown when a required currency is missing for a money metric query.
 *
 * @internal
 *   Phase 7 isolated API. Do not use outside Phase 7 without approval.
 */
final class MissingCurrencyException extends AnalyticsException {
}

