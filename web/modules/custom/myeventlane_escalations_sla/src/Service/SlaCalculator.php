<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_sla\Service;

/**
 * Computes SLA due timestamps from creation time + policy hours.
 */
final class SlaCalculator {

  public function __construct(
    private readonly SlaPolicy $policy,
  ) {}

  /**
   * Computes the first response due timestamp.
   *
   * @param int $created
   *   UNIX timestamp of escalation creation.
   * @param string $priority
   *   Priority level (urgent, high, normal, low).
   *
   * @return int
   *   UNIX timestamp when first response is due.
   */
  public function computeFirstResponseDue(int $created, string $priority): int {
    $hours = $this->policy->getFirstResponseHours($priority);
    return $created + ($hours * 3600);
  }

  /**
   * Computes the resolution due timestamp.
   *
   * @param int $created
   *   UNIX timestamp of escalation creation.
   * @param string $priority
   *   Priority level (urgent, high, normal, low).
   *
   * @return int
   *   UNIX timestamp when resolution is due.
   */
  public function computeResolutionDue(int $created, string $priority): int {
    $hours = $this->policy->getResolutionHours($priority);
    return $created + ($hours * 3600);
  }

}
