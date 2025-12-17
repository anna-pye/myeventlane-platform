<?php

declare(strict_types=1);

namespace Drupal\myeventlane_finance\ValueObject;

/**
 * Date range value object for BAS reporting.
 */
final class DateRange {

  /**
   * Constructs DateRange.
   *
   * @param int $startTimestamp
   *   Unix timestamp for start date.
   * @param int $endTimestamp
   *   Unix timestamp for end date.
   */
  public function __construct(
    private readonly int $startTimestamp,
    private readonly int $endTimestamp,
  ) {
    if ($this->startTimestamp > $this->endTimestamp) {
      throw new \InvalidArgumentException('Start date must be before or equal to end date.');
    }
  }

  /**
   * Gets the start timestamp.
   *
   * @return int
   *   Unix timestamp.
   */
  public function getStartTimestamp(): int {
    return $this->startTimestamp;
  }

  /**
   * Gets the end timestamp.
   *
   * @return int
   *   Unix timestamp.
   */
  public function getEndTimestamp(): int {
    return $this->endTimestamp;
  }

  /**
   * Gets formatted date range string.
   *
   * @return string
   *   Formatted as 'YYYY-MM-DD → YYYY-MM-DD'.
   */
  public function getFormattedRange(): string {
    return date('Y-m-d', $this->startTimestamp) . ' → ' . date('Y-m-d', $this->endTimestamp);
  }

}













