<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Canonical operational coordination read-model (governance visibility only).
 *
 * Composes coordination semantics exclusively from merged operational rollups
 * and existing escalation / resolution governance projections. Does not
 * evaluate scanner execution, mutate entitlements, or drive orchestration.
 */
final class OperationalCoordinationStateManager {

  /**
   * Canonical coordination states (ordered for documentation only).
   *
   * @var list<string>
   */
  public const COORDINATION_STATES = [
    'nominal',
    'elevated',
    'degraded',
    'constrained',
    'recovery',
    'reconciliation',
    'saturated',
    'restricted',
  ];

  /**
   * Canonical operational postures.
   *
   * @var list<string>
   */
  public const OPERATIONAL_POSTURES = [
    'standard_operations',
    'heightened_monitoring',
    'incident_response',
    'recovery_operations',
    'reconciliation_operations',
    'restricted_operations',
  ];

  /**
   * Canonical venue confidence states.
   *
   * @var list<string>
   */
  public const CONFIDENCE_STATES = [
    'stable',
    'caution',
    'uncertain',
    'degraded',
    'critical',
  ];

  /**
   * Incident saturation lane tokens (read-model only).
   *
   * @var list<string>
   */
  public const INCIDENT_SATURATION_STATES = [
    'clear',
    'rising',
    'elevated',
    'saturated',
  ];

  /**
   * @var array<string, int>
   */
  private const SEVERITY_RANK = [
    'low' => 0,
    'warning' => 1,
    'moderate' => 2,
    'elevated' => 3,
    'severe' => 4,
    'critical' => 5,
  ];

  public function __construct(
    private readonly OperationalEscalationPolicyManager $escalationPolicyManager,
    private readonly OperationalResolutionGovernanceManager $resolutionGovernanceManager,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Normalizes an external coordination token (invalid → nominal, logged).
   */
  public function normalizeCoordinationState(?string $raw): string {
    $token = strtolower(trim((string) $raw));
    if ($token === '') {
      return 'nominal';
    }
    if (!in_array($token, self::COORDINATION_STATES, TRUE)) {
      $this->logger->warning('OperationalCoordinationStateManager: unknown coordination token @token normalized to nominal.', [
        '@token' => $token,
        'token' => $token,
      ]);
      return 'nominal';
    }
    return $token;
  }

  /**
   * Normalizes an operational posture token (invalid → standard_operations).
   */
  public function normalizeOperationalPosture(?string $raw): string {
    $token = strtolower(trim((string) $raw));
    if ($token === '') {
      return 'standard_operations';
    }
    if (!in_array($token, self::OPERATIONAL_POSTURES, TRUE)) {
      $this->logger->warning('OperationalCoordinationStateManager: unknown posture token @token normalized to standard_operations.', [
        '@token' => $token,
        'token' => $token,
      ]);
      return 'standard_operations';
    }
    return $token;
  }

  /**
   * Normalizes a confidence lane token (invalid → stable).
   */
  public function normalizeConfidenceState(?string $raw): string {
    $token = strtolower(trim((string) $raw));
    if ($token === '') {
      return 'stable';
    }
    if (!in_array($token, self::CONFIDENCE_STATES, TRUE)) {
      $this->logger->warning('OperationalCoordinationStateManager: unknown confidence token @token normalized to stable.', [
        '@token' => $token,
        'token' => $token,
      ]);
      return 'stable';
    }
    return $token;
  }

  /**
   * Normalizes incident saturation token (invalid → clear).
   */
  public function normalizeIncidentSaturation(?string $raw): string {
    $token = strtolower(trim((string) $raw));
    if ($token === '') {
      return 'clear';
    }
    if (!in_array($token, self::INCIDENT_SATURATION_STATES, TRUE)) {
      $this->logger->warning('OperationalCoordinationStateManager: unknown saturation token @token normalized to clear.', [
        '@token' => $token,
        'token' => $token,
      ]);
      return 'clear';
    }
    return $token;
  }

  /**
   * Composes the canonical coordination read-model for workspace projections.
   *
   * @param array<string, mixed> $merged
   *
   * @return array<string, mixed>
   */
  public function composeOperationalCoordinationReadModel(array $merged, int $requestTime): array {
    $governed_severity = $this->escalationPolicyManager->projectSeverityFromOperationalRollup($merged);
    $resolution = $this->resolutionGovernanceManager->projectResolutionStateFromRollup($merged);
    $signals = $this->collectRollupSignals($merged);

    $coordination = $this->deriveCoordinationState($merged, $governed_severity, $signals);
    $coordination = $this->normalizeCoordinationState($coordination);

    $posture = $this->normalizeOperationalPosture(
      $this->deriveOperationalPosture($coordination, $resolution, $governed_severity)
    );
    $confidence = $this->normalizeConfidenceState(
      $this->deriveConfidenceFromSeverity($governed_severity)
    );
    $saturation = $this->normalizeIncidentSaturation(
      $this->deriveIncidentSaturation($signals, $governed_severity)
    );

    return [
      'request_anchor_unix' => $requestTime,
      'governed_severity' => $governed_severity,
      'projected_resolution_state' => $resolution,
      'coordination_state' => $coordination,
      'operational_posture' => $posture,
      'venue_confidence_state' => $confidence,
      'incident_saturation_state' => $saturation,
      'venue_operational_readiness_descriptor' => $this->projectVenueReadinessDescriptor($merged, $signals, $coordination),
      'operational_degradation_descriptor' => $this->projectDegradationDescriptor($governed_severity, $signals),
      'recovery_coordination_descriptor' => $this->projectRecoveryCoordinationDescriptor($merged),
      'reconciliation_readiness_descriptor' => $this->projectReconciliationReadinessDescriptor($merged),
      'operational_coordination_visibility_descriptor' => $this->projectCoordinationVisibilityDescriptor(
        $coordination,
        $posture,
        $confidence,
        $saturation
      ),
      'rollup_signal_summary' => $signals,
    ];
  }

  /**
   * @param array<string, mixed> $merged
   *
   * @return array<string, mixed>
   */
  private function collectRollupSignals(array $merged): array {
    if ($merged === []) {
      return [
        'stress_total' => 0,
        'ap_mode_count' => 0,
        'distinct_continuity_modes' => 0,
        'distinct_scanner_states' => 0,
      ];
    }

    $issuance = is_array($merged['issuance'] ?? NULL) ? $merged['issuance'] : [];
    $recovery = is_array($merged['recovery'] ?? NULL) ? $merged['recovery'] : [];
    $artifacts = is_array($merged['artifacts'] ?? NULL) ? $merged['artifacts'] : [];
    $guest = is_array($merged['guest_continuity'] ?? NULL) ? $merged['guest_continuity'] : [];

    $stress = 0;
    $worst = (string) ($issuance['worst_quantity_alignment_status'] ?? '');
    if (in_array($worst, ['invalid', 'missing', 'pending'], TRUE)) {
      $stress++;
    }
    if (!empty($recovery['recovery_mismatch_observed'])) {
      $stress++;
    }
    foreach ($recovery['completion_states_observed'] ?? [] as $state) {
      $s = strtolower((string) $state);
      if ($s !== '' && !in_array($s, ['complete', 'unknown', 'n/a'], TRUE)) {
        $stress++;
        break;
      }
    }
    foreach (['canonical_pdf_readiness', 'qr_payload_operational', 'attachment_continuity'] as $key) {
      $v = (string) ($artifacts[$key] ?? '');
      if (in_array($v, ['missing', 'mixed', 'pending'], TRUE)) {
        $stress++;
      }
    }
    foreach ($guest['continuity_statuses_observed'] ?? [] as $st) {
      if ((string) $st === 'invalid') {
        $stress++;
        break;
      }
    }

    $ap_modes = [];
    $occ = is_array($artifacts['occupancy_policy'] ?? NULL) ? $artifacts['occupancy_policy'] : [];
    foreach ($occ as $row) {
      if (!is_array($row)) {
        continue;
      }
      $m = strtolower(trim((string) (($row['occupancy_summary']['anti_passback_mode'] ?? '') ?: '')));
      if ($m !== '') {
        $ap_modes[$m] = TRUE;
      }
    }

    $continuity_modes = [];
    $cont = is_array($artifacts['operational_continuity'] ?? NULL) ? $artifacts['operational_continuity'] : [];
    foreach ($cont as $row) {
      if (!is_array($row)) {
        continue;
      }
      $mode = strtolower(trim((string) (($row['continuity_summary']['continuity_mode'] ?? '') ?: '')));
      if ($mode !== '') {
        $continuity_modes[$mode] = TRUE;
      }
    }

    $scanner_states = [];
    foreach (['timed_entry_policy', 'session_entitlement_policy'] as $domain) {
      $block = is_array($artifacts[$domain] ?? NULL) ? $artifacts[$domain] : [];
      foreach ($block as $row) {
        if (!is_array($row)) {
          continue;
        }
        $pol = is_array($row['policy'] ?? NULL) ? $row['policy'] : [];
        $scan = is_array($pol['scanner'] ?? NULL) ? $pol['scanner'] : [];
        $st = strtolower(trim((string) (($scan['state'] ?? '') ?: '')));
        if ($st !== '') {
          $scanner_states[$st] = TRUE;
        }
      }
    }

    return [
      'stress_total' => $stress,
      'ap_mode_count' => count($ap_modes),
      'distinct_continuity_modes' => count($continuity_modes),
      'distinct_scanner_states' => count($scanner_states),
    ];
  }

  /**
   * @param array<string, mixed> $signals
   */
  private function deriveCoordinationState(array $merged, string $governed_severity, array $signals): string {
    if ($merged === []) {
      return 'nominal';
    }

    $issuance = is_array($merged['issuance'] ?? NULL) ? $merged['issuance'] : [];
    $worst = (string) ($issuance['worst_quantity_alignment_status'] ?? '');
    $recovery = is_array($merged['recovery'] ?? NULL) ? $merged['recovery'] : [];
    $artifacts = is_array($merged['artifacts'] ?? NULL) ? $merged['artifacts'] : [];

    $sevRank = self::SEVERITY_RANK[$this->escalationPolicyManager->normalizeSeverity($governed_severity)] ?? 0;

    if ($sevRank >= self::SEVERITY_RANK['critical'] && $worst === 'invalid') {
      return 'restricted';
    }

    if ($sevRank >= self::SEVERITY_RANK['critical'] || (int) ($signals['stress_total'] ?? 0) >= 5) {
      return 'saturated';
    }

    if (!empty($recovery['recovery_mismatch_observed']) || $this->recoveryCompletionNeedsCoordination($recovery)) {
      return 'recovery';
    }

    $qr = (string) ($artifacts['qr_payload_operational'] ?? '');
    if (in_array($qr, ['missing', 'mixed', 'pending'], TRUE)) {
      return 'reconciliation';
    }

    if ((int) ($signals['ap_mode_count'] ?? 0) >= 2
      || (int) ($signals['distinct_continuity_modes'] ?? 0) >= 2
      || (int) ($signals['distinct_scanner_states'] ?? 0) >= 3) {
      return 'constrained';
    }

    if ($sevRank >= self::SEVERITY_RANK['moderate'] || (int) ($signals['stress_total'] ?? 0) >= 2) {
      return 'degraded';
    }

    if ($sevRank >= self::SEVERITY_RANK['warning'] || (int) ($signals['stress_total'] ?? 0) >= 1) {
      return 'elevated';
    }

    return 'nominal';
  }

  /**
   * @param array<string, mixed> $recovery
   */
  private function recoveryCompletionNeedsCoordination(array $recovery): bool {
    foreach ($recovery['completion_states_observed'] ?? [] as $state) {
      $s = strtolower((string) $state);
      if ($s !== '' && !in_array($s, ['complete', 'unknown', 'n/a'], TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @param array<string, mixed> $signals
   */
  private function deriveIncidentSaturation(array $signals, string $governed_severity): string {
    $sevRank = self::SEVERITY_RANK[$this->escalationPolicyManager->normalizeSeverity($governed_severity)] ?? 0;
    $stress = (int) ($signals['stress_total'] ?? 0);
    if ($sevRank >= self::SEVERITY_RANK['critical'] || $stress >= 5) {
      return 'saturated';
    }
    if ($sevRank >= self::SEVERITY_RANK['elevated'] || $stress >= 3) {
      return 'elevated';
    }
    if ($sevRank >= self::SEVERITY_RANK['warning'] || $stress >= 1) {
      return 'rising';
    }
    return 'clear';
  }

  private function deriveConfidenceFromSeverity(string $governed_severity): string {
    $s = $this->escalationPolicyManager->normalizeSeverity($governed_severity);
    return match ($s) {
      'low' => 'stable',
      'warning' => 'caution',
      'moderate' => 'uncertain',
      'elevated' => 'degraded',
      'severe', 'critical' => 'critical',
      default => 'stable',
    };
  }

  private function deriveOperationalPosture(string $coordination, string $resolution, string $severity): string {
    $c = $this->normalizeCoordinationState($coordination);
    if ($c === 'restricted') {
      return 'restricted_operations';
    }
    if ($c === 'reconciliation') {
      return 'reconciliation_operations';
    }
    if ($c === 'recovery') {
      return 'recovery_operations';
    }
    if (in_array($c, ['degraded', 'constrained', 'saturated'], TRUE)) {
      return 'incident_response';
    }
    if ($c === 'elevated') {
      return 'heightened_monitoring';
    }
    $r = $this->resolutionGovernanceManager->normalizeResolutionState($resolution);
    if (!in_array($r, ['unresolved'], TRUE)) {
      return 'heightened_monitoring';
    }
    $sev = $this->escalationPolicyManager->normalizeSeverity($severity);
    if ($sev !== 'low') {
      return 'heightened_monitoring';
    }
    return 'standard_operations';
  }

  /**
   * @param array<string, mixed> $merged
   * @param array<string, mixed> $signals
   */
  private function projectVenueReadinessDescriptor(array $merged, array $signals, string $coordination): string {
    if ($merged === []) {
      return 'No sampled operational material; readiness observation is global-catalog only.';
    }
    return sprintf(
      'Readiness projection: coordination=%s; rollup_stress=%d; distinct_scanner_states=%d.',
      $coordination,
      (int) ($signals['stress_total'] ?? 0),
      (int) ($signals['distinct_scanner_states'] ?? 0)
    );
  }

  /**
   * @param array<string, mixed> $signals
   */
  private function projectDegradationDescriptor(string $governed_severity, array $signals): string {
    return sprintf(
      'Degradation projection: governed_severity=%s; observed_stress_signals=%d (rollup-only).',
      $this->escalationPolicyManager->normalizeSeverity($governed_severity),
      (int) ($signals['stress_total'] ?? 0)
    );
  }

  /**
   * @param array<string, mixed> $merged
   */
  private function projectRecoveryCoordinationDescriptor(array $merged): string {
    if ($merged === []) {
      return 'Recovery coordination: no sampled tickets in this window.';
    }
    $recovery = is_array($merged['recovery'] ?? NULL) ? $merged['recovery'] : [];
    $states = implode(', ', $recovery['completion_states_observed'] ?? []);
    $mismatch = !empty($recovery['recovery_mismatch_observed']) ? 'yes' : 'no';
    return sprintf(
      'Recovery coordination: completion_states=[%s]; mismatch_observed=%s.',
      $states,
      $mismatch
    );
  }

  /**
   * @param array<string, mixed> $merged
   */
  private function projectReconciliationReadinessDescriptor(array $merged): string {
    if ($merged === []) {
      return 'Reconciliation readiness: not applicable without sampled operational material.';
    }
    $artifacts = is_array($merged['artifacts'] ?? NULL) ? $merged['artifacts'] : [];
    $qr = (string) ($artifacts['qr_payload_operational'] ?? '');
    $attach = (string) ($artifacts['attachment_continuity'] ?? '');
    return sprintf(
      'Reconciliation readiness: qr_operational_readiness=%s; attachment_continuity=%s.',
      $qr !== '' ? $qr : 'n/a',
      $attach !== '' ? $attach : 'n/a'
    );
  }

  private function projectCoordinationVisibilityDescriptor(
    string $coordination,
    string $posture,
    string $confidence,
    string $saturation,
  ): string {
    return sprintf(
      'Coordination visibility: state=%s; posture=%s; confidence=%s; incident_saturation=%s.',
      $coordination,
      $posture,
      $confidence,
      $saturation
    );
  }

}
