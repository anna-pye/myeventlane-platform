<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

/**
 * Composes Twig-safe ownership and assignment workspace sections.
 *
 * Delegates normalization to {@see OperationalAssignmentGovernanceManager} and
 * audit shaping to {@see OperationalAssignmentAuditProjector}. Does not branch
 * on entitlement or scanner execution paths.
 */
final class OperationalOwnershipProjectionBuilder {

  public function __construct(
    private readonly OperationalAssignmentGovernanceManager $assignmentGovernanceManager,
    private readonly OperationalEscalationPolicyManager $escalationPolicyManager,
    private readonly OperationalResolutionGovernanceManager $resolutionGovernanceManager,
    private readonly OperationalAssignmentAuditProjector $assignmentAuditProjector,
  ) {}

  /**
   * @param array<string, mixed> $merged
   *
   * @return list<array<string, mixed>>
   */
  public function buildWorkspaceOwnershipSections(array $merged, int $requestTime): array {
    $severity = $this->escalationPolicyManager->projectSeverityFromOperationalRollup($merged);
    $escalation = $this->escalationPolicyManager->mapSeverityToEscalation($severity);
    $ownership = $this->assignmentGovernanceManager->projectOwnershipStateFromRollup($merged);
    $handoff = $this->assignmentGovernanceManager->normalizeHandoffType(
      $this->assignmentGovernanceManager->projectDominantHandoffType($merged)
    );
    $esc_ownership = $this->assignmentGovernanceManager->mapEscalationOwnership($escalation);
    $resolution = $this->resolutionGovernanceManager->projectResolutionStateFromRollup($merged);
    $ack = $this->resolutionGovernanceManager->projectAcknowledgementTiming($severity, $requestTime);
    $responsibility = $this->assignmentGovernanceManager->describeOperationalResponsibility($ownership, $handoff);

    $assignment_history = $this->assignmentAuditProjector->projectAssignmentHistory($merged, $requestTime);
    $handoff_audit = $this->assignmentAuditProjector->projectHandoffAuditSummary($handoff, $requestTime);
    $continuity = $this->assignmentAuditProjector->projectResponsibilityContinuitySummary($merged, $requestTime);

    $timeline_events = $this->syntheticOwnershipEvents($merged, $requestTime, $ownership, $escalation, $resolution);
    $timeline = $this->assignmentAuditProjector->projectOperationalOwnershipTimeline($timeline_events);

    $section_tone = $severity;

    return [
      [
        'id' => 'assignment_ownership',
        'title' => 'Assignment ownership',
        'section_tone' => $section_tone,
        'cards' => [
          [
            'label' => 'Normalized ownership state',
            'value' => $ownership,
          ],
          [
            'label' => 'Normalized handoff lane',
            'value' => $handoff,
          ],
          [
            'label' => 'Assignment role lane (descriptor)',
            'value' => $this->assignmentGovernanceManager->normalizeAssignmentRoleLabel(
              'venue_operations_governance'
            ),
          ],
          [
            'label' => 'Governed severity (rollup)',
            'value' => $severity,
          ],
          [
            'label' => 'Projected resolution posture',
            'value' => $resolution,
          ],
        ],
      ],
      [
        'id' => 'acknowledgement_ownership',
        'title' => 'Acknowledgement ownership',
        'section_tone' => $section_tone,
        'cards' => [
          [
            'label' => 'Acknowledgement owner pool',
            'value' => 'Venue operations acknowledgement lane (projection).',
          ],
          [
            'label' => 'Acknowledgement deadline (unix)',
            'value' => (string) (int) ($ack['acknowledgement_deadline_unix'] ?? 0),
          ],
          [
            'label' => 'SLA label (read-only)',
            'value' => (string) ($ack['sla_label'] ?? ''),
          ],
        ],
      ],
      [
        'id' => 'escalation_owner_visibility',
        'title' => 'Escalation owner visibility',
        'section_tone' => $section_tone,
        'cards' => [
          [
            'label' => 'Escalation tier',
            'value' => $escalation,
          ],
          [
            'label' => 'Escalation owner descriptor',
            'value' => (string) ($esc_ownership['escalation_owner_descriptor'] ?? ''),
          ],
        ],
      ],
      [
        'id' => 'handoff_continuity',
        'title' => 'Handoff continuity',
        'section_tone' => $section_tone,
        'cards' => $this->flattenSummaries('Handoff audit', $handoff_audit),
      ],
      [
        'id' => 'operational_responsibility',
        'title' => 'Operational responsibility',
        'section_tone' => $section_tone,
        'cards' => [
          [
            'label' => 'Responsibility summary',
            'value' => $responsibility,
          ],
          [
            'label' => 'Continuity summary',
            'value' => (string) (($continuity[0] ?? [])['summary'] ?? ''),
          ],
        ],
      ],
      [
        'id' => 'ownership_audit_timeline',
        'title' => 'Ownership audit timeline',
        'section_tone' => $section_tone,
        'cards' => $this->flattenSummaries('Assignment history', $assignment_history),
        'timeline' => $timeline,
      ],
    ];
  }

  /**
   * @param list<array<string, string>> $rows
   *
   * @return list<array<string, string>>
   */
  private function flattenSummaries(string $label, array $rows): array {
    $cards = [];
    foreach ($rows as $row) {
      $cards[] = [
        'label' => $label,
        'value' => (string) ($row['summary'] ?? ''),
      ];
    }
    return $cards !== [] ? $cards : [
      [
        'label' => $label,
        'value' => 'No audit rows projected for this window.',
      ],
    ];
  }

  /**
   * Synthetic, read-only timeline anchors for governance visibility.
   *
   * @param array<string, mixed> $merged
   *
   * @return list<array<string, mixed>>
   */
  private function syntheticOwnershipEvents(array $merged, int $requestTime, string $ownership, string $escalation, string $resolution): array {
    $events = [];
    $events[] = [
      'ownership_state' => 'unassigned',
      'recorded_at' => $requestTime - 7200,
      'note' => 'observation_window_opened',
    ];
    if ($merged !== []) {
      $events[] = [
        'ownership_state' => 'assigned',
        'recorded_at' => $requestTime - 5400,
        'note' => 'sampled_operational_material_present',
      ];
    }
    if ($resolution !== 'unresolved') {
      $events[] = [
        'ownership_state' => 'acknowledged',
        'recorded_at' => $requestTime - 3600,
        'note' => 'resolution_posture_observed',
      ];
    }
    if (in_array($escalation, ['urgent', 'executive'], TRUE)) {
      $events[] = [
        'ownership_state' => 'escalated',
        'recorded_at' => $requestTime - 1800,
        'note' => 'escalation_tier_elevated',
      ];
    }
    $events[] = [
      'ownership_state' => $ownership,
      'recorded_at' => $requestTime,
      'note' => 'current_projection_anchor',
    ];
    return $events;
  }

}
