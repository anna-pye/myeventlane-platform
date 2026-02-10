<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_policy\Service;

use Drupal\Component\Datetime\TimeInterface;

/**
 * Helper for ISO week key operations.
 *
 * Produces YYYY-WW keys for weekly policy evaluation.
 */
final class PolicyWeek {

  public function __construct(
    private readonly TimeInterface $time,
  ) {}

  /**
   * Returns the current ISO week key (e.g. "2026-07").
   */
  public function current(): string {
    return $this->fromTimestamp($this->time->getRequestTime());
  }

  /**
   * Converts a UNIX timestamp to an ISO week key.
   */
  public function fromTimestamp(int $timestamp): string {
    $dt = new \DateTimeImmutable('@' . $timestamp);
    return $dt->format('o-W');
  }

  /**
   * Computes the number of weeks between two ISO week keys.
   *
   * Returns 0 if either key is empty or invalid.
   */
  public function weeksBetween(string $week_a, string $week_b): int {
    if ($week_a === '' || $week_b === '') {
      return 0;
    }

    try {
      $ts_a = $this->weekKeyToTimestamp($week_a);
      $ts_b = $this->weekKeyToTimestamp($week_b);
    }
    catch (\Throwable) {
      return 0;
    }

    return (int) abs(floor(($ts_b - $ts_a) / (7 * 86400)));
  }

  /**
   * Converts an ISO week key to a Monday midnight timestamp.
   */
  private function weekKeyToTimestamp(string $key): int {
    [$year, $week] = explode('-', $key);
    $dt = new \DateTimeImmutable();
    $dt = $dt->setISODate((int) $year, (int) $week, 1);
    $dt = $dt->setTime(0, 0, 0);
    return $dt->getTimestamp();
  }

}
