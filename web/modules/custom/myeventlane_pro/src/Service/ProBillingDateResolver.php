<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

use Drupal\commerce_recurring\Entity\SubscriptionInterface;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Resolves the canonical upcoming Pro charge date.
 */
final class ProBillingDateResolver {

  public function __construct(
    private readonly TimeInterface $time,
  ) {}

  /**
   * @return array{timestamp: int|null, days: int|null, stale: bool}
   *   The future billing timestamp, rounded days remaining, and stale flag.
   */
  public function resolve(SubscriptionInterface $subscription, bool $isTrial): array {
    $timestamp = $isTrial
      ? (int) $subscription->getTrialEndTime()
      : (int) $subscription->getNextRenewalTime();

    if ($timestamp <= 0) {
      return ['timestamp' => NULL, 'days' => NULL, 'stale' => FALSE];
    }

    $delta = $timestamp - $this->time->getRequestTime();
    if ($delta <= 0) {
      return ['timestamp' => NULL, 'days' => NULL, 'stale' => TRUE];
    }

    return [
      'timestamp' => $timestamp,
      'days' => (int) ceil($delta / 86400),
      'stale' => FALSE,
    ];
  }

}
