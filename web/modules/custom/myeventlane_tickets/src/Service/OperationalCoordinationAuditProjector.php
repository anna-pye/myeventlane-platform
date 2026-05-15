<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

/**
 * Audit-safe coordination timelines and history summaries.
 *
 * Emits only normalized strings and unix anchors — no replay tokens, QR
 * payloads, device fingerprints, or customer identifiers.
 */
final class OperationalCoordinationAuditProjector {

  public function __construct(
    private readonly OperationalCoordinationStateManager $operationalCoordinationStateManager,
    private readonly OperationalResolutionGovernanceManager $resolutionGovernanceManager,
  ) {}

  /**
   * @param array<string, mixed> $merged
   *
   * @return list<array<string, mixed>>
   */
  public function buildWorkspaceCoordinationAuditSections(array $merged, int $requestTime): array {
    $model = $this->operationalCoordinationStateManager->composeOperationalCoordinationReadModel($merged, $requestTime);
    $tone = (string) ($model['governed_severity'] ?? 'low');

    $coordination = (string) ($model['coordination_state'] ?? 'nominal');
    $posture = (string) ($model['operational_posture'] ?? 'standard_operations');

    $degradation_history = $this->projectDegradationHistorySummaries($model, $requestTime);
    $recovery_history = $this->projectRecoveryCoordinationHistory($model, $requestTime);
    $posture_events = $this->syntheticPostureTransitionEvents($merged, $requestTime, $coordination, $posture);
    usort($posture_events, static function (array $a, array $b): int {
      return ((int) ($a['recorded_at'] ?? 0)) <=> ((int) ($b['recorded_at'] ?? 0));
    });
    $resolution_events = $this->resolutionPostureTransitionEvents($merged, $requestTime);
    $merged_timeline_input = array_merge($posture_events, $resolution_events);
    usort($merged_timeline_input, static function (array $a, array $b): int {
      return ((int) ($a['recorded_at'] ?? 0)) <=> ((int) ($b['recorded_at'] ?? 0));
    });
    $timeline = $this->mapCoordinationTimelineRows($merged_timeline_input);

    $audit_cards = $this->flattenCoordinationAuditCards($degradation_history, $recovery_history, $model);

    return [
      [
        'id' => 'operational_coordination_state_timeline',
        'title' => 'Operational coordination state timeline',
        'section_tone' => $tone,
        'cards' => [
          [
            'label' => 'Coordination audit summary',
            'value' => (string) ($model['operational_coordination_visibility_descriptor'] ?? ''),
          ],
        ],
        'timeline' => $timeline,
      ],
      [
        'id' => 'operational_coordination_audit_visibility',
        'title' => 'Coordination audit visibility',
        'section_tone' => $tone,
        'cards' => $audit_cards,
      ],
    ];
  }

  /**
   * @param array<string, mixed> $model
   *
   * @return list<array<string, string>>
   */
  public function projectDegradationHistorySummaries(array $model, int $requestTime): array {
    $severity = (string) ($model['governed_severity'] ?? 'low');
    $stress = (int) (($model['rollup_signal_summary']['stress_total'] ?? 0));
    return [[
      'summary' => sprintf(
        'Degradation history anchor %d: severity=%s; stress_total=%d (rollup-derived).',
        $requestTime,
        $severity,
        $stress
      ),
    ]];
  }

  /**
   * @param array<string, mixed> $model
   *
   * @return list<array<string, string>>
   */
  public function projectRecoveryCoordinationHistory(array $model, int $requestTime): array {
    $text = (string) ($model['recovery_coordination_descriptor'] ?? '');
    return [[
      'summary' => sprintf('Recovery coordination history at %d: %s', $requestTime, $text),
    ]];
  }

  /**
   * @param list<array<string, mixed>> $events
   *
   * @return list<array<string, mixed>>
   */
  public function projectOperationalPostureTransitions(array $events): array {
    $out = [];
    foreach ($events as $event) {
      if (!is_array($event)) {
        continue;
      }
      $posture = $this->operationalCoordinationStateManager->normalizeOperationalPosture(
        isset($event['posture']) ? (string) $event['posture'] : NULL
      );
      $coordination = $this->operationalCoordinationStateManager->normalizeCoordinationState(
        isset($event['coordination_state']) ? (string) $event['coordination_state'] : NULL
      );
      $at = (int) ($event['recorded_at'] ?? 0);
      $note = trim((string) ($event['note'] ?? ''));
      $out[] = [
        'coordination_lane' => $coordination,
        'operational_posture_lane' => $posture,
        'recorded_at_unix' => $at,
        'audit_note' => $note !== '' ? $note : 'posture_transition',
      ];
    }
    return $out;
  }

  /**
   * @param array<string, mixed> $merged
   *
   * @return list<array<string, mixed>>
   */
  private function syntheticPostureTransitionEvents(array $merged, int $requestTime, string $coordination, string $posture): array {
    $events = [];
    $events[] = [
      'coordination_state' => 'nominal',
      'posture' => 'standard_operations',
      'recorded_at' => $requestTime - 10_800,
      'note' => 'coordination_window_opened',
    ];
    if ($merged !== []) {
      $events[] = [
        'coordination_state' => 'elevated',
        'posture' => 'heightened_monitoring',
        'recorded_at' => $requestTime - 7200,
        'note' => 'sampled_operational_material_present',
      ];
    }
    if ($coordination !== 'nominal') {
      $events[] = [
        'coordination_state' => $coordination,
        'posture' => $posture,
        'recorded_at' => $requestTime - 3600,
        'note' => 'rollup_composition_converged',
      ];
    }
    $events[] = [
      'coordination_state' => $coordination,
      'posture' => $posture,
      'recorded_at' => $requestTime,
      'note' => 'observation_anchor_closed',
    ];
    return $events;
  }

  /**
   * Resolution lifecycle anchors for posture continuity (read-only).
   *
   * @param array<string, mixed> $merged
   *
   * @return list<array<string, mixed>>
   */
  private function resolutionPostureTransitionEvents(array $merged, int $requestTime): array {
    $state = $this->resolutionGovernanceManager->projectResolutionStateFromRollup($merged);
    $normalized = $this->resolutionGovernanceManager->normalizeResolutionState($state);
    return [[
      'coordination_state' => 'nominal',
      'posture' => $this->mapResolutionToPostureLane($normalized),
      'recorded_at' => $requestTime - 5400,
      'note' => 'resolution_posture_observed:' . $normalized,
    ]];
  }

  private function mapResolutionToPostureLane(string $resolution): string {
    return match ($resolution) {
      'resolved', 'suppressed' => 'standard_operations',
      'monitoring', 'under_review' => 'incident_response',
      'acknowledged' => 'heightened_monitoring',
      default => 'heightened_monitoring',
    };
  }

  /**
   * @param list<array<string, mixed>> $events
   *
   * @return list<array<string, mixed>>
   */
  private function mapCoordinationTimelineRows(array $events): array {
    $normalized = $this->projectOperationalPostureTransitions($events);
    $rows = [];
    foreach ($normalized as $row) {
      $rows[] = [
        'lane_state' => sprintf('%s / %s', $row['coordination_lane'], $row['operational_posture_lane']),
        'recorded_at_unix' => (int) ($row['recorded_at_unix'] ?? 0),
        'audit_note' => (string) ($row['audit_note'] ?? ''),
      ];
    }
    return $rows;
  }

  /**
   * @param list<array<string, string>> $degradation
   * @param list<array<string, string>> $recovery
   * @param array<string, mixed> $model
   *
   * @return list<array<string, string>>
   */
  private function flattenCoordinationAuditCards(array $degradation, array $recovery, array $model): array {
    $cards = [];
    foreach ($degradation as $row) {
      $cards[] = [
        'label' => 'Degradation history',
        'value' => (string) ($row['summary'] ?? ''),
      ];
    }
    foreach ($recovery as $row) {
      $cards[] = [
        'label' => 'Recovery coordination history',
        'value' => (string) ($row['summary'] ?? ''),
      ];
    }
    $cards[] = [
      'label' => 'Operational posture transition probe',
      'value' => sprintf(
        'Posture=%s anchored to coordination=%s at %d.',
        (string) ($model['operational_posture'] ?? ''),
        (string) ($model['coordination_state'] ?? ''),
        (int) ($model['request_anchor_unix'] ?? 0)
      ),
    ];
    return $cards !== [] ? $cards : [
      [
        'label' => 'Coordination audit',
        'value' => 'No audit rows projected for this window.',
      ],
    ];
  }

}
