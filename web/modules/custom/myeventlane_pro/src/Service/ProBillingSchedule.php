<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

/**
 * Canonical MEL Pro billing schedule identifiers.
 */
final class ProBillingSchedule {

  public const TRIAL = 'mel_pro_monthly';

  public const RESTART = 'mel_pro_monthly_restart';

  public const ALL = [
    self::TRIAL,
    self::RESTART,
  ];

  /**
   * Determines whether a billing schedule belongs to MEL Pro.
   */
  public static function isPro(string $scheduleId): bool {
    return in_array($scheduleId, self::ALL, TRUE);
  }

}
