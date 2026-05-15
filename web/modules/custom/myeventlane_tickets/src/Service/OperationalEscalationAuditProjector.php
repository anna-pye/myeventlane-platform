<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

/**
 * Audit-safe projections for escalation, resolution, and suppression visibility.
 *
 * Emits Twig-safe card arrays only; no secrets, replay tokens, or QR material.
 */
final class OperationalEscalationAuditProjector {

  public function __construct(
    private readonly OperationalEscalationPolicyManager $escalationPolicyManager,
    private readonly OperationalResolutionGovernanceManager $resolutionGovernanceManager,
  ) {}

  /**
   * Builds governance sections for the venue operations workspace.
   *
   * @param array<string, mixed> $merged
   *
   * @return list<array<string, mixed>>
   */
  public function buildWorkspaceGovernanceSections(array $merged, int $requestTime): array {
    $severity = $this->escalationPolicyManager->projectSeverityFromOperationalRollup($merged);
    $escalation = $this->escalationPolicyManager->mapSeverityToEscalation($severity);
    $routing = $this->escalationPolicyManager->describeSeverityRouting($severity);
    $sla = $this->escalationPolicyManager->slaSemanticsForEscalation($escalation);
    $timing = $this->resolutionGovernanceManager->projectAcknowledgementTiming($severity, $requestTime);
    $resolution = $this->resolutionGovernanceManager->projectResolutionStateFromRollup($merged);

    $sectionTone = $severity;

    $escalation_history = $this->projectEscalationHistory($merged, $severity, $escalation, $requestTime);
    $resolution_history = $this->projectResolutionHistory($resolution, $requestTime);
    $suppression_audit = $this->projectSuppressionAudit(NULL);

    $ownership = 'Venue operations staff (workspace projection; non-binding ownership label).';

    return [
      [
        'id' => 'escalation_governance',
        'title' => 'Escalation governance',
        'section_tone' => $sectionTone,
        'cards' => [
          [
            'label' => 'Governed severity (rollup projection)',
            'value' => $severity,
          ],
          [
            'label' => 'Mapped escalation tier',
            'value' => $escalation,
          ],
          [
            'label' => 'Severity routing',
            'value' => (string) ($routing['routing_descriptor'] ?? ''),
          ],
          [
            'label' => 'Escalation descriptor',
            'value' => (string) ($this->escalationPolicyManager->describeEscalation($escalation)['escalation_descriptor'] ?? ''),
          ],
          [
            'label' => 'SLA acknowledgement window (seconds)',
            'value' => (string) (int) ($sla['acknowledgement_seconds'] ?? 0),
          ],
          [
            'label' => 'SLA descriptor',
            'value' => (string) ($sla['sla_descriptor'] ?? ''),
          ],
          [
            'label' => 'Acknowledgement deadline (unix)',
            'value' => (string) (int) ($timing['acknowledgement_deadline_unix'] ?? 0),
          ],
        ],
      ],
      [
        'id' => 'resolution_governance',
        'title' => 'Resolution governance',
        'section_tone' => $sectionTone,
        'cards' => [
          [
            'label' => 'Projected resolution state (observational)',
            'value' => $resolution,
          ],
          [
            'label' => 'Operational ownership indicator',
            'value' => $ownership,
          ],
          [
            'label' => 'Closure note',
            'value' => 'Resolved and suppressed are terminal governance states except explicit staff re-opens per transition matrix.',
          ],
          [
            'label' => 'Transition probe (monitoring → resolved)',
            'value' => $this->resolutionGovernanceManager->lifecycleTransitionAllowed('monitoring', 'resolved') ? 'allowed' : 'denied',
          ],
          [
            'label' => 'Transition probe (unresolved → suppressed)',
            'value' => $this->resolutionGovernanceManager->lifecycleTransitionAllowed('unresolved', 'suppressed') ? 'allowed' : 'denied',
          ],
        ],
      ],
      [
        'id' => 'suppression_governance',
        'title' => 'Suppression governance',
        'section_tone' => $sectionTone,
        'cards' => [
          [
            'label' => 'Active suppression (projection)',
            'value' => $suppression_audit['active'] ? 'yes' : 'no',
          ],
          [
            'label' => 'Suppression audit visibility',
            'value' => (string) ($suppression_audit['visibility_descriptor'] ?? ''),
          ],
          [
            'label' => 'Invalid suppression rehearsal (no reason)',
            'value' => ($this->resolutionGovernanceManager->validateSuppression([])['valid'] ?? FALSE) ? 'valid' : 'rejected',
          ],
        ],
      ],
      [
        'id' => 'escalation_audit',
        'title' => 'Escalation audit visibility',
        'section_tone' => $sectionTone,
        'cards' => $this->flattenAuditCards($escalation_history, $resolution_history),
      ],
    ];
  }

  /**
   * @param array<string, mixed> $merged
   *
   * @return list<array<string, string>>
   */
  public function projectEscalationHistory(array $merged, string $severity, string $escalation, int $requestTime): array {
    $scope = $merged === [] ? 'global' : 'event_sample';
    return [[
      'summary' => sprintf(
        'Observation anchor %d: scope=%s; governed_severity=%s; escalation=%s.',
        $requestTime,
        $scope,
        $severity,
        $escalation
      ),
    ]];
  }

  /**
   * @return list<array<string, string>>
   */
  public function projectResolutionHistory(string $projectedState, int $requestTime): array {
    return [[
      'summary' => sprintf(
        'Resolution projection at %d: state=%s (read-only governance; no incident store mutation).',
        $requestTime,
        $projectedState
      ),
    ]];
  }

  /**
   * @param array<string, mixed>|null $suppression
   *
   * @return array<string, mixed>
   */
  public function projectSuppressionAudit(?array $suppression): array {
    if ($suppression === NULL || $suppression === []) {
      return [
        'active' => FALSE,
        'visibility_descriptor' => 'No suppression payload supplied; historical incidents remain auditable elsewhere.',
      ];
    }
    return [
      'active' => TRUE,
      'visibility_descriptor' => 'Suppression metadata present; audit history must remain append-only.',
    ];
  }

  /**
   * @param list<array<string, string>> $escalationRows
   * @param list<array<string, string>> $resolutionRows
   *
   * @return list<array<string, string>>
   */
  private function flattenAuditCards(array $escalationRows, array $resolutionRows): array {
    $cards = [];
    foreach ($escalationRows as $row) {
      $cards[] = [
        'label' => 'Escalation history',
        'value' => (string) ($row['summary'] ?? ''),
      ];
    }
    foreach ($resolutionRows as $row) {
      $cards[] = [
        'label' => 'Resolution history',
        'value' => (string) ($row['summary'] ?? ''),
      ];
    }
    return $cards !== [] ? $cards : [
      [
        'label' => 'Audit visibility',
        'value' => 'No audit rows projected for this window.',
      ],
    ];
  }

}
