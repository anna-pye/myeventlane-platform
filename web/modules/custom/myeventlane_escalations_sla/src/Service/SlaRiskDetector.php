<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_sla\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\myeventlane_escalations\Entity\Escalation;

/**
 * Evaluates whether an escalation is at risk of an imminent SLA breach.
 *
 * This is a non-AI service. It performs deterministic evaluation of SLA fields.
 * It does NOT modify any entity fields, trigger emails, or enqueue AI jobs.
 */
final class SlaRiskDetector {

  public function __construct(
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Evaluates whether the given escalation is at risk of an SLA breach.
   *
   * Eligibility criteria (ALL must be true):
   *   - Escalation is open (not resolved/closed).
   *   - No SLA breach has occurred yet (first or resolution).
   *   - Nearest SLA due is within the configurable threshold (default 6h).
   *   - field_waiting_on = 'vendor'.
   *
   * @param \Drupal\myeventlane_escalations\Entity\Escalation $escalation
   *   The escalation entity.
   *
   * @return array{eligible: bool, hours_remaining: int, waiting_on: string}
   *   Risk evaluation result.
   */
  public function evaluate(Escalation $escalation): array {
    $now = $this->time->getRequestTime();
    $threshold_hours = (int) ($this->configFactory
      ->get('myeventlane_escalations_sla.settings')
      ->get('risk_detection.threshold_hours') ?? 6);

    $default = ['eligible' => FALSE, 'hours_remaining' => 0, 'waiting_on' => ''];

    // Must be open.
    $status = (string) $escalation->get('status')->value;
    if (in_array($status, ['resolved', 'closed'], TRUE)) {
      return $default;
    }

    // Must not be already breached.
    $first_breached = (bool) ($escalation->get('field_sla_first_breached')->value ?? FALSE);
    $resolution_breached = (bool) ($escalation->get('field_sla_resolution_breached')->value ?? FALSE);
    if ($first_breached || $resolution_breached) {
      return $default;
    }

    // Must be waiting on vendor.
    $waiting_on = $escalation->hasField('field_waiting_on')
      ? (string) ($escalation->get('field_waiting_on')->value ?? '')
      : '';
    if ($waiting_on !== 'vendor') {
      return ['eligible' => FALSE, 'hours_remaining' => 0, 'waiting_on' => $waiting_on];
    }

    // Nearest SLA due must be within threshold.
    $nearest_due = $this->getNearestDue($escalation);
    if (!$nearest_due) {
      return ['eligible' => FALSE, 'hours_remaining' => 0, 'waiting_on' => $waiting_on];
    }

    $seconds_remaining = $nearest_due - $now;

    // Already past due — not our scope (the enforcer handles breaches).
    if ($seconds_remaining <= 0) {
      return ['eligible' => FALSE, 'hours_remaining' => 0, 'waiting_on' => $waiting_on];
    }

    $hours_remaining = (int) ceil($seconds_remaining / 3600);

    if ($hours_remaining > $threshold_hours) {
      return ['eligible' => FALSE, 'hours_remaining' => $hours_remaining, 'waiting_on' => $waiting_on];
    }

    return [
      'eligible' => TRUE,
      'hours_remaining' => $hours_remaining,
      'waiting_on' => $waiting_on,
    ];
  }

  /**
   * Returns the nearest SLA due timestamp from available fields.
   *
   * @param \Drupal\myeventlane_escalations\Entity\Escalation $escalation
   *   The escalation entity.
   *
   * @return int|null
   *   The nearest due timestamp, or NULL if none set.
   */
  private function getNearestDue(Escalation $escalation): ?int {
    $first_due = $escalation->get('field_sla_first_due')->value;
    $resolution_due = $escalation->get('field_sla_resolution_due')->value;

    $first_due = $first_due ? (int) $first_due : NULL;
    $resolution_due = $resolution_due ? (int) $resolution_due : NULL;

    if ($first_due && $resolution_due) {
      return min($first_due, $resolution_due);
    }

    return $first_due ?: $resolution_due ?: NULL;
  }

}
