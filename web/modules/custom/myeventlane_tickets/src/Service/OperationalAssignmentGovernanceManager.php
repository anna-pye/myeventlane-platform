<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Canonical assignment and ownership normalization for operational governance.
 *
 * Read-only semantics: does not mutate entitlements, scanner state, redemption,
 * continuity authority, or incident stores. Produces normalized tokens and
 * descriptors for staff projections only.
 */
final class OperationalAssignmentGovernanceManager {

  /**
   * Canonical operational ownership states (ordered for display stability).
   *
   * @var list<string>
   */
  public const OWNERSHIP_STATES = [
    'unassigned',
    'assigned',
    'acknowledged',
    'escalated',
    'delegated',
    'monitoring',
    'resolved',
  ];

  /**
   * Canonical handoff type tokens.
   *
   * @var list<string>
   */
  public const HANDOFF_TYPES = [
    'operational',
    'escalation',
    'venue',
    'moderation',
    'finance',
    'trust_safety',
    'executive',
  ];

  /**
   * @var array<string, int>
   */
  private const OWNERSHIP_RANK = [
    'unassigned' => 0,
    'assigned' => 1,
    'acknowledged' => 2,
    'delegated' => 3,
    'monitoring' => 4,
    'escalated' => 5,
    'resolved' => 6,
  ];

  public function __construct(
    private readonly OperationalEscalationPolicyManager $escalationPolicyManager,
    private readonly OperationalResolutionGovernanceManager $resolutionGovernanceManager,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Normalizes a declared ownership state string.
   */
  public function normalizeOwnershipState(?string $raw): string {
    $token = strtolower(trim((string) $raw));
    if ($token === '') {
      return 'unassigned';
    }
    if (!in_array($token, self::OWNERSHIP_STATES, TRUE)) {
      $this->logger->warning('OperationalAssignmentGovernanceManager: unknown ownership state @token normalized to unassigned.', [
        '@token' => $token,
        'token' => $token,
      ]);
      return 'unassigned';
    }
    return $token;
  }

  /**
   * Normalizes a handoff type string; unknown values fold to operational.
   */
  public function normalizeHandoffType(?string $raw): string {
    $token = strtolower(trim((string) $raw));
    $token = str_replace([' ', '-'], '_', $token);
    if ($token === 'trustsafety') {
      $token = 'trust_safety';
    }
    if ($token === '') {
      return 'operational';
    }
    if (!in_array($token, self::HANDOFF_TYPES, TRUE)) {
      $this->logger->warning('OperationalAssignmentGovernanceManager: unknown handoff type @token normalized to operational.', [
        '@token' => $token,
        'token' => $token,
      ]);
      return 'operational';
    }
    return $token;
  }

  /**
   * Stable descriptor for operational responsibility (non-binding label).
   */
  public function describeOperationalResponsibility(string $ownershipState, string $handoffType): string {
    $o = $this->normalizeOwnershipState($ownershipState);
    $h = $this->normalizeHandoffType($handoffType);
    return sprintf(
      'Responsibility projection: ownership=%s; primary handoff lane=%s (governance visibility; not staffing truth).',
      $o,
      $h
    );
  }

  /**
   * Maps escalation tier to an escalation-owner pool descriptor (read-only).
   *
   * @return array<string, string>
   */
  public function mapEscalationOwnership(string $escalationLevel): array {
    $level = $this->escalationPolicyManager->normalizeEscalation($escalationLevel);
    $pools = [
      'none' => 'Venue operations pool (default routing).',
      'review' => 'Venue operations pool with review visibility.',
      'elevated' => 'Venue operations lead pool.',
      'urgent' => 'Cross-functional escalation pool (urgent).',
      'executive' => 'Executive escalation visibility pool.',
    ];
    return [
      'escalation_level' => $level,
      'escalation_owner_descriptor' => $pools[$level] ?? $pools['none'],
    ];
  }

  /**
   * Projects assignment / ownership state from merged inspector rollup only.
   *
   * Uses canonical escalation and resolution managers for severity and
   * resolution signals without duplicating their algorithms locally.
   *
   * @param array<string, mixed> $merged
   */
  public function projectOwnershipStateFromRollup(array $merged): string {
    if ($merged === []) {
      return 'unassigned';
    }
    $severity = $this->escalationPolicyManager->projectSeverityFromOperationalRollup($merged);
    $escalation = $this->escalationPolicyManager->mapSeverityToEscalation($severity);
    $resolution = $this->resolutionGovernanceManager->projectResolutionStateFromRollup($merged);

    if ($resolution === 'resolved' || $resolution === 'suppressed') {
      return 'resolved';
    }
    if ($resolution === 'monitoring') {
      return 'monitoring';
    }
    if (in_array($escalation, ['urgent', 'executive'], TRUE)) {
      return 'escalated';
    }
    if (in_array($resolution, ['acknowledged', 'under_review'], TRUE)) {
      return 'acknowledged';
    }
    if ($this->rollupSuggestsDelegatedLanes($merged)) {
      return 'delegated';
    }
    $issuance = is_array($merged['issuance'] ?? NULL) ? $merged['issuance'] : [];
    if ((int) ($issuance['aggregated_issued_ticket_count'] ?? 0) > 0) {
      return 'assigned';
    }
    return 'unassigned';
  }

  /**
   * Projects the dominant handoff lane from rollup shape (observational).
   *
   * @param array<string, mixed> $merged
   */
  public function projectDominantHandoffType(array $merged): string {
    if ($merged === []) {
      return 'operational';
    }
    $escalation = $this->escalationPolicyManager->mapSeverityToEscalation(
      $this->escalationPolicyManager->projectSeverityFromOperationalRollup($merged)
    );
    if ($escalation === 'executive') {
      return 'executive';
    }
    if (in_array($escalation, ['urgent', 'elevated'], TRUE)) {
      return 'escalation';
    }
    $artifacts = is_array($merged['artifacts'] ?? NULL) ? $merged['artifacts'] : [];
    $venue = is_array($artifacts['venue_operation_policy'] ?? NULL) ? $artifacts['venue_operation_policy'] : [];
    if (count($venue) > 1) {
      return 'venue';
    }
    $worst = (string) (($merged['issuance'] ?? [])['worst_quantity_alignment_status'] ?? '');
    if ($worst === 'invalid') {
      return 'trust_safety';
    }
    if (!empty(($merged['recovery'] ?? [])['recovery_mismatch_observed'])) {
      return 'finance';
    }
    foreach (($merged['guest_continuity'] ?? [])['continuity_statuses_observed'] ?? [] as $st) {
      if ((string) $st === 'invalid') {
        return 'moderation';
      }
    }
    return 'operational';
  }

  /**
   * Normalizes arbitrary assignment role labels for card display (no UIDs).
   */
  public function normalizeAssignmentRoleLabel(?string $raw): string {
    $label = trim((string) $raw);
    if ($label === '') {
      return 'unassigned_lane';
    }
    if (strlen($label) > 120) {
      return substr($label, 0, 117) . '...';
    }
    return strtolower($label);
  }

  /**
   * Picks the highest ownership rank among tokens (for convergence displays).
   *
   * @param list<string> $states
   */
  public function maxOwnershipState(array $states): string {
    $best = 'unassigned';
    $bestRank = -1;
    foreach ($states as $s) {
      $n = $this->normalizeOwnershipState($s);
      $r = self::OWNERSHIP_RANK[$n] ?? 0;
      if ($r > $bestRank) {
        $bestRank = $r;
        $best = $n;
      }
    }
    return $best;
  }

  /**
   * @param array<string, mixed> $merged
   */
  private function rollupSuggestsDelegatedLanes(array $merged): bool {
    $artifacts = is_array($merged['artifacts'] ?? NULL) ? $merged['artifacts'] : [];
    $venue = is_array($artifacts['venue_operation_policy'] ?? NULL) ? $artifacts['venue_operation_policy'] : [];
    $zones = is_array($artifacts['zone_access_topology'] ?? NULL) ? $artifacts['zone_access_topology'] : [];
    return count($venue) > 1 && count($zones) > 0;
  }

}
