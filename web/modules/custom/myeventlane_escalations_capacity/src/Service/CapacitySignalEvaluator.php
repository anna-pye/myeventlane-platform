<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_capacity\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Evaluates workload and activity metrics against configured thresholds.
 *
 * Returns an overall capacity signal (ok, watch, pressure) and a list
 * of plain-English reasons when thresholds are exceeded.
 *
 * No individual staff data is used or returned.
 */
final class CapacitySignalEvaluator {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Evaluates capacity signal from workload and activity metrics.
   *
   * @param array $workload
   *   Output of EscalationWorkloadQuery::getWorkloadMetrics().
   * @param array $activity
   *   Output of StaffActivityAggregator::aggregate().
   *
   * @return array
   *   Associative array:
   *   - signal: string ('ok'|'watch'|'pressure')
   *   - reasons: string[] (plain-English bullet strings)
   *   - icon: string (emoji for display)
   */
  public function evaluate(array $workload, array $activity): array {
    $config = $this->configFactory->get('myeventlane_escalations_capacity.settings');
    $thresholds = $config->get('thresholds') ?? [];

    $waiting_threshold = (int) ($thresholds['waiting_on_staff'] ?? 10);
    $response_threshold = (float) ($thresholds['avg_first_staff_response_hours'] ?? 24);
    $oldest_threshold = (int) ($thresholds['oldest_open_escalation_days'] ?? 14);

    $reasons = [];

    // Check waiting-on-staff count.
    $waiting = (int) ($workload['waiting_on_staff_count'] ?? 0);
    if ($waiting >= $waiting_threshold) {
      $reasons[] = 'A high number of escalations are waiting on staff.';
    }

    // Check average first staff response time.
    $avg_response = (float) ($activity['avg_first_staff_response_hours'] ?? 0);
    if ($avg_response > 0 && $avg_response > $response_threshold) {
      $reasons[] = 'Average first staff response time is above the target.';
    }

    // Check oldest open escalation age.
    $oldest = (int) ($workload['oldest_open_age_days'] ?? 0);
    if ($oldest > $oldest_threshold) {
      $reasons[] = 'The oldest open escalation exceeds the age threshold.';
    }

    // Check for escalations without any staff reply.
    $without_reply = (int) ($activity['escalations_without_staff_reply'] ?? 0);
    $open_count = (int) ($workload['open_count'] ?? 0);
    if ($open_count > 0 && $without_reply > 0) {
      $ratio = $without_reply / $open_count;
      if ($ratio > 0.5) {
        $reasons[] = 'More than half of open escalations have no staff reply.';
      }
      elseif ($ratio > 0.25) {
        $reasons[] = 'A notable portion of open escalations have no staff reply.';
      }
    }

    // Determine signal level.
    $reason_count = count($reasons);
    if ($reason_count >= 3) {
      $signal = 'pressure';
      $icon = '🔴';
    }
    elseif ($reason_count >= 1) {
      $signal = 'watch';
      $icon = '🟡';
    }
    else {
      $signal = 'ok';
      $icon = '🟢';
      $reasons[] = 'All capacity indicators are within acceptable thresholds.';
    }

    return [
      'signal' => $signal,
      'reasons' => $reasons,
      'icon' => $icon,
    ];
  }

}
