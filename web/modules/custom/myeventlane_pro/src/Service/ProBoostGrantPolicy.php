<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

/**
 * Calculates the fixed, non-renewing Pro Boost grant window.
 */
final class ProBoostGrantPolicy {

  private const SECONDS_PER_DAY = 86400;

  /**
   * Calculates the end of a new grant, capped by the Pro active period.
   */
  public function newGrantEnd(int $startsAt, ?int $proActiveEnd, int $days): ?int {
    $endsAt = $startsAt + (max(1, $days) * self::SECONDS_PER_DAY);
    if ($proActiveEnd !== NULL) {
      $endsAt = min($endsAt, $proActiveEnd);
    }

    return $endsAt > $startsAt ? $endsAt : NULL;
  }

  /**
   * Reconciles a grant without extending or reviving an expired grant.
   */
  public function existingGrantEnd(
    int $now,
    int $existingStartsAt,
    int $existingEndsAt,
    ?int $proActiveEnd,
    int $days,
  ): ?int {
    $endsAt = min(
      $existingEndsAt,
      $existingStartsAt + (max(1, $days) * self::SECONDS_PER_DAY),
    );
    if ($proActiveEnd !== NULL) {
      $endsAt = min($endsAt, $proActiveEnd);
    }

    return $endsAt > $now ? $endsAt : NULL;
  }

}
