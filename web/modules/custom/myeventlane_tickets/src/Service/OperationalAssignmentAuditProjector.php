<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

/**
 * Audit-safe assignment and ownership timeline projections.
 *
 * Emits normalized summaries only; never emits replay tokens, QR payloads, or
 * device fingerprints. Does not persist or mutate operational continuity state.
 */
final class OperationalAssignmentAuditProjector {

  public function __construct(
    private readonly OperationalAssignmentGovernanceManager $assignmentGovernanceManager,
    private readonly OperationalEscalationPolicyManager $escalationPolicyManager,
  ) {}

  /**
   * @param array<string, mixed> $merged
   *
   * @return list<array<string, string>>
   */
  public function projectAssignmentHistory(array $merged, int $requestTime): array {
    $ownership = $this->assignmentGovernanceManager->projectOwnershipStateFromRollup($merged);
    $handoff = $this->assignmentGovernanceManager->projectDominantHandoffType($merged);
    $severity = $this->escalationPolicyManager->projectSeverityFromOperationalRollup($merged);
    $escalation = $this->escalationPolicyManager->mapSeverityToEscalation($severity);
    $scope = $merged === [] ? 'global' : 'event_sample';
    return [[
      'summary' => sprintf(
        'Assignment history anchor %d: scope=%s; ownership=%s; handoff=%s; governed_severity=%s; escalation=%s.',
        $requestTime,
        $scope,
        $ownership,
        $handoff,
        $severity,
        $escalation
      ),
    ]];
  }

  /**
   * @return list<array<string, string>>
   */
  public function projectHandoffAuditSummary(string $normalizedHandoff, int $requestTime): array {
    return [[
      'summary' => sprintf(
        'Handoff continuity anchor %d: normalized_lane=%s; append-only audit posture preserved.',
        $requestTime,
        $normalizedHandoff
      ),
    ]];
  }

  /**
   * Builds a sorted ownership timeline from declarative events.
   *
   * Each event: ownership_state (string), recorded_at (int unix), note (string).
   * Stable ordering: ascending by `recorded_at_unix`, then by `ownership_state`
   * lexicographically when timestamps tie.
   *
   * @param list<array<string, mixed>> $events
   *
   * @return list<array<string, mixed>>
   */
  public function projectOperationalOwnershipTimeline(array $events): array {
    $rows = [];
    foreach ($events as $event) {
      if (!is_array($event)) {
        continue;
      }
      $state = $this->assignmentGovernanceManager->normalizeOwnershipState(
        isset($event['ownership_state']) ? (string) $event['ownership_state'] : NULL
      );
      $at = (int) ($event['recorded_at'] ?? 0);
      $note = trim((string) ($event['note'] ?? ''));
      $rows[] = [
        'ownership_state' => $state,
        'recorded_at_unix' => $at,
        'audit_note' => $note !== '' ? $note : 'ownership_event',
      ];
    }
    usort($rows, static function (array $a, array $b): int {
      return ($a['recorded_at_unix'] <=> $b['recorded_at_unix']) ?: strcmp($a['ownership_state'], $b['ownership_state']);
    });
    return $rows;
  }

  /**
   * @return list<array<string, string>>
   */
  public function projectResponsibilityContinuitySummary(array $merged, int $requestTime): array {
    $ownership = $this->assignmentGovernanceManager->projectOwnershipStateFromRollup($merged);
    return [[
      'summary' => sprintf(
        'Responsibility continuity anchor %d: projected_ownership=%s; historical assignments remain auditable without customer identifiers.',
        $requestTime,
        $ownership
      ),
    ]];
  }

}
