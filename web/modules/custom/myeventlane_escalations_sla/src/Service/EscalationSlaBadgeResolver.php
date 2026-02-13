<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_sla\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\myeventlane_escalations\Entity\Escalation;

/**
 * Single source of truth for SLA badge resolution.
 *
 * Every SLA badge decision across the platform MUST go through this service.
 * Do NOT duplicate SLA badge logic elsewhere.
 */
final class EscalationSlaBadgeResolver {

  public function __construct(
    private readonly TimeInterface $time,
  ) {}

  /**
   * Resolve SLA badge state for an escalation.
   *
   * @param \Drupal\myeventlane_escalations\Entity\Escalation $escalation
   *   The escalation entity.
   *
   * @return array
   *   Badge array with keys: key, label, icon, severity.
   */
  public function resolve(Escalation $escalation): array {
    $now = $this->time->getRequestTime();

    $first_breached = (bool) ($escalation->get('field_sla_first_breached')->value ?? FALSE);
    $resolution_breached = (bool) ($escalation->get('field_sla_resolution_breached')->value ?? FALSE);
    $level = (int) ($escalation->get('field_escalation_level')->value ?? 0);

    // 1. Overdue always wins.
    if ($first_breached || $resolution_breached) {
      return [
        'key' => 'overdue',
        'label' => 'Overdue',
        'icon' => "\xF0\x9F\x94\xB4",
        'severity' => 'danger',
      ];
    }

    // 2. Escalated (but not overdue).
    if ($level > 0) {
      return [
        'key' => 'escalated',
        'label' => 'Escalated',
        'icon' => "\xF0\x9F\x9F\xA3",
        'severity' => 'warning',
      ];
    }

    // 3. Due soon (within 12 hours).
    $due = $this->getNearestDue($escalation);
    if ($due && ($due - $now) <= (12 * 3600)) {
      return [
        'key' => 'due_soon',
        'label' => 'Due soon',
        'icon' => "\xF0\x9F\x9F\xA0",
        'severity' => 'attention',
      ];
    }

    // 4. On track.
    return [
      'key' => 'on_track',
      'label' => 'On track',
      'icon' => "\xF0\x9F\x9F\xA2",
      'severity' => 'ok',
    ];
  }

  /**
   * Returns nearest SLA due timestamp.
   *
   * @param \Drupal\myeventlane_escalations\Entity\Escalation $escalation
   *   The escalation entity.
   *
   * @return int|null
   *   The nearest due timestamp, or NULL if no due dates are set.
   */
  protected function getNearestDue(Escalation $escalation): ?int {
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
