<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Operational resolution and suppression governance (non-authoritative).
 *
 * Coordinates normalization of resolution lifecycle tokens, suppression rules,
 * acknowledgement timing projections, and declarative transition rules.
 * Does not mutate tickets, redemption logs, or incident persistence.
 */
final class OperationalResolutionGovernanceManager {

  /**
   * Canonical resolution states.
   *
   * @var list<string>
   */
  public const RESOLUTION_STATES = [
    'unresolved',
    'acknowledged',
    'under_review',
    'monitoring',
    'resolved',
    'suppressed',
  ];

  public function __construct(
    private readonly OperationalEscalationPolicyManager $escalationPolicyManager,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Normalizes a resolution state token.
   */
  public function normalizeResolutionState(?string $raw): string {
    $token = strtolower(trim((string) $raw));
    if ($token === '') {
      return 'unresolved';
    }
    if (!in_array($token, self::RESOLUTION_STATES, TRUE)) {
      $this->logger->warning('OperationalResolutionGovernanceManager: unknown resolution state @token normalized to unresolved.', [
        '@token' => $token,
        'token' => $token,
      ]);
      return 'unresolved';
    }
    return $token;
  }

  /**
   * Validates suppression intent without persisting or mutating incidents.
   *
   * Suppression MUST require an explicit non-empty reason string.
   *
   * @param array<string, mixed> $input
   *
   * @return array<string, mixed>
   */
  public function validateSuppression(array $input): array {
    $reason = isset($input['reason']) ? trim((string) $input['reason']) : '';
    if ($reason === '') {
      return [
        'valid' => FALSE,
        'errors' => ['suppression_requires_explicit_reason'],
      ];
    }
    return [
      'valid' => TRUE,
      'errors' => [],
    ];
  }

  /**
   * Declarative lifecycle closure: whether a transition is permitted.
   */
  public function lifecycleTransitionAllowed(string $from, string $to): bool {
    $a = $this->normalizeResolutionState($from);
    $b = $this->normalizeResolutionState($to);
    if ($a === $b) {
      return TRUE;
    }
    $matrix = [
      'unresolved' => ['acknowledged', 'under_review', 'monitoring'],
      'acknowledged' => ['under_review', 'monitoring', 'resolved', 'suppressed'],
      'under_review' => ['monitoring', 'resolved', 'suppressed', 'acknowledged'],
      'monitoring' => ['resolved', 'suppressed', 'under_review'],
      'resolved' => [],
      'suppressed' => ['monitoring', 'under_review'],
    ];
    return in_array($b, $matrix[$a] ?? [], TRUE);
  }

  /**
   * Projects acknowledgement deadline from severity and anchor time.
   *
   * @return array<string, mixed>
   */
  public function projectAcknowledgementTiming(string $severity, int $openedRequestTime): array {
    $normalized = $this->escalationPolicyManager->normalizeSeverity($severity);
    $escalation = $this->escalationPolicyManager->mapSeverityToEscalation($normalized);
    $sla = $this->escalationPolicyManager->slaSemanticsForEscalation($escalation);
    $seconds = (int) ($sla['acknowledgement_seconds'] ?? 0);
    $deadline = $openedRequestTime + $seconds;
    return [
      'severity' => $normalized,
      'escalation_level' => $escalation,
      'acknowledgement_deadline_unix' => $deadline,
      'acknowledgement_seconds' => $seconds,
      'sla_label' => (string) ($sla['sla_descriptor'] ?? ''),
    ];
  }

  /**
   * Projects an observational resolution state from merged rollup keys only.
   *
   * @param array<string, mixed> $merged
   */
  public function projectResolutionStateFromRollup(array $merged): string {
    if ($merged === []) {
      return 'unresolved';
    }
    $recovery = is_array($merged['recovery'] ?? NULL) ? $merged['recovery'] : [];
    if (!empty($recovery['recovery_mismatch_observed'])) {
      return 'under_review';
    }
    $issuance = is_array($merged['issuance'] ?? NULL) ? $merged['issuance'] : [];
    $worst = (string) ($issuance['worst_quantity_alignment_status'] ?? '');
    if (in_array($worst, ['invalid', 'missing'], TRUE)) {
      return 'acknowledged';
    }
    $guest = is_array($merged['guest_continuity'] ?? NULL) ? $merged['guest_continuity'] : [];
    foreach ($guest['continuity_statuses_observed'] ?? [] as $st) {
      if ((string) $st === 'invalid') {
        return 'monitoring';
      }
    }
    return 'unresolved';
  }

  /**
   * Builds a read-only audit timeline from normalized governance events.
   *
   * Each event must contain: state (string), recorded_at (int unix), note (string).
   *
   * @param list<array<string, mixed>> $events
   *
   * @return list<array<string, mixed>>
   */
  public function projectResolutionAuditTimeline(array $events): array {
    $out = [];
    foreach ($events as $event) {
      if (!is_array($event)) {
        continue;
      }
      $state = $this->normalizeResolutionState(isset($event['state']) ? (string) $event['state'] : NULL);
      $at = (int) ($event['recorded_at'] ?? 0);
      $note = trim((string) ($event['note'] ?? ''));
      $out[] = [
        'resolution_state' => $state,
        'recorded_at_unix' => $at,
        'audit_note' => $note !== '' ? $note : 'governance_event',
      ];
    }
    return $out;
  }

}
