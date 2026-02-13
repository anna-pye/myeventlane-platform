<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_analytics\Service;

/**
 * Evaluates vendor health from escalation metrics.
 *
 * Returns a bucket (healthy|attention|risk) with a friendly, non-punitive,
 * gender-neutral summary. No public rankings or comparisons.
 */
final class VendorHealthEvaluator {

  /**
   * Breach rate threshold: below this = healthy (if escalation_rate also OK).
   */
  private const BREACH_HEALTHY = 15.0;

  /**
   * Breach rate threshold: at or above this = risk.
   */
  private const BREACH_RISK = 35.0;

  /**
   * Escalation rate threshold: below this = healthy (if breach_rate also OK).
   */
  private const ESCALATION_HEALTHY = 10.0;

  /**
   * Escalation rate threshold: at or above this = risk.
   */
  private const ESCALATION_RISK = 25.0;

  /**
   * Minimum escalations required for meaningful evaluation.
   */
  private const MIN_SAMPLE = 3;

  /**
   * Evaluates vendor health from computed metrics.
   *
   * @param array $metrics
   *   Metrics array from EscalationMetricsCalculator::calculate().
   *
   * @return array{bucket: string, summary: string, icon: string}
   *   Health evaluation result.
   */
  public function evaluate(array $metrics): array {
    $total = (int) ($metrics['total_escalations'] ?? 0);

    // Too few escalations for a meaningful evaluation.
    if ($total < self::MIN_SAMPLE) {
      return [
        'bucket' => 'healthy',
        'summary' => 'Not enough data to evaluate yet. Keep up the good work!',
        'icon' => "\xF0\x9F\x9F\xA2",
      ];
    }

    $breach_rate = (float) ($metrics['breach_rate'] ?? 0);
    $escalation_rate = (float) ($metrics['escalation_rate'] ?? 0);

    // Risk: high breach rate OR high escalation rate.
    if ($breach_rate >= self::BREACH_RISK || $escalation_rate >= self::ESCALATION_RISK) {
      return [
        'bucket' => 'risk',
        'summary' => 'Some escalations need faster attention. Responding sooner helps resolve issues quickly and keeps everyone happy.',
        'icon' => "\xF0\x9F\x94\xB4",
      ];
    }

    // Healthy: both rates are low.
    if ($breach_rate < self::BREACH_HEALTHY && $escalation_rate < self::ESCALATION_HEALTHY) {
      return [
        'bucket' => 'healthy',
        'summary' => 'Your support response times are looking great! Customers appreciate the prompt attention.',
        'icon' => "\xF0\x9F\x9F\xA2",
      ];
    }

    // Attention: everything in between.
    return [
      'bucket' => 'attention',
      'summary' => 'A few escalations are taking longer than usual. A quicker response can make a real difference.',
      'icon' => "\xF0\x9F\x9F\xA0",
    ];
  }

}
