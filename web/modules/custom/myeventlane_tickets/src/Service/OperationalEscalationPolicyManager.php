<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Canonical escalation and severity normalization for operational governance.
 *
 * This manager is **not** ticket truth, scanner authority, or continuity authority.
 * It normalizes declared governance tokens, maps severity to escalation tiers,
 * and exposes SLA acknowledgement semantics for staff projections only.
 */
final class OperationalEscalationPolicyManager {

  /**
   * Canonical escalation levels (ordered least to most acute).
   *
   * @var list<string>
   */
  public const ESCALATION_LEVELS = [
    'none',
    'review',
    'elevated',
    'urgent',
    'executive',
  ];

  /**
   * Canonical severity levels (ordered least to most acute).
   *
   * @var list<string>
   */
  public const SEVERITY_LEVELS = [
    'low',
    'warning',
    'moderate',
    'elevated',
    'severe',
    'critical',
  ];

  /**
   * Rank map for max-severity selection.
   *
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
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Normalizes a declared escalation level string.
   */
  public function normalizeEscalation(?string $raw): string {
    $token = strtolower(trim((string) $raw));
    if ($token === '') {
      return 'none';
    }
    if (!in_array($token, self::ESCALATION_LEVELS, TRUE)) {
      $this->logger->warning('OperationalEscalationPolicyManager: unknown escalation token @token normalized to none.', [
        '@token' => $token,
        'token' => $token,
      ]);
      return 'none';
    }
    return $token;
  }

  /**
   * Normalizes a declared severity string.
   */
  public function normalizeSeverity(?string $raw): string {
    $token = strtolower(trim((string) $raw));
    if ($token === '') {
      return 'low';
    }
    if (!in_array($token, self::SEVERITY_LEVELS, TRUE)) {
      $this->logger->warning('OperationalEscalationPolicyManager: unknown severity token @token normalized to low.', [
        '@token' => $token,
        'token' => $token,
      ]);
      return 'low';
    }
    return $token;
  }

  /**
   * Stable human-facing descriptor for an escalation level (machine strings).
   *
   * @return array<string, string>
   */
  public function describeEscalation(string $level): array {
    $normalized = $this->normalizeEscalation($level);
    $labels = [
      'none' => 'No formal escalation path selected.',
      'review' => 'Operational review visibility; coordinate within standard staffing.',
      'elevated' => 'Elevated coordination; prioritize venue operations response.',
      'urgent' => 'Urgent coordination; leadership visibility expected.',
      'executive' => 'Executive visibility; minimal interpretation; maximum coordination clarity.',
    ];
    return [
      'escalation_level' => $normalized,
      'escalation_descriptor' => $labels[$normalized] ?? $labels['none'],
    ];
  }

  /**
   * Severity routing semantics (which escalation tier severity maps into).
   *
   * @return array<string, string>
   */
  public function describeSeverityRouting(string $severity): array {
    $normalized = $this->normalizeSeverity($severity);
    $target = $this->mapSeverityToEscalation($normalized);
    return [
      'severity' => $normalized,
      'routes_to_escalation' => $target,
      'routing_descriptor' => sprintf('Governed default routes severity %s to escalation %s.', $normalized, $target),
    ];
  }

  /**
   * Maps canonical severity to canonical escalation tier.
   */
  public function mapSeverityToEscalation(string $severity): string {
    $s = $this->normalizeSeverity($severity);
    return match ($s) {
      'low' => 'none',
      'warning' => 'review',
      'moderate' => 'elevated',
      'elevated' => 'elevated',
      'severe' => 'urgent',
      'critical' => 'executive',
      default => 'none',
    };
  }

  /**
   * SLA semantics: acknowledgement window in seconds for an escalation tier.
   *
   * @return array<string, mixed>
   */
  public function slaSemanticsForEscalation(string $escalation): array {
    $level = $this->normalizeEscalation($escalation);
    $seconds = match ($level) {
      'none' => 86400,
      'review' => 7200,
      'elevated' => 3600,
      'urgent' => 1800,
      'executive' => 900,
      default => 86400,
    };
    return [
      'escalation_level' => $level,
      'acknowledgement_seconds' => $seconds,
      'sla_descriptor' => sprintf(
        'Acknowledgement expectation: %d seconds from observation anchor for escalation %s.',
        $seconds,
        $level
      ),
    ];
  }

  /**
   * Projects governed severity from merged operational rollup keys only.
   *
   * Uses aggregated inspector machine strings already merged by the workspace
   * builder — not per-ticket scanner evaluation and not entitlement mutation.
   *
   * @param array<string, mixed> $merged
   */
  public function projectSeverityFromOperationalRollup(array $merged): string {
    if ($merged === []) {
      return 'low';
    }
    $candidates = ['low'];
    $issuance = is_array($merged['issuance'] ?? NULL) ? $merged['issuance'] : [];
    $worst = (string) ($issuance['worst_quantity_alignment_status'] ?? '');
    if ($worst === 'invalid') {
      $candidates[] = 'severe';
    }
    elseif (in_array($worst, ['missing', 'pending'], TRUE)) {
      $candidates[] = 'moderate';
    }

    $recovery = is_array($merged['recovery'] ?? NULL) ? $merged['recovery'] : [];
    if (!empty($recovery['recovery_mismatch_observed'])) {
      $candidates[] = 'elevated';
    }

    $artifacts = is_array($merged['artifacts'] ?? NULL) ? $merged['artifacts'] : [];
    foreach (['canonical_pdf_readiness', 'qr_payload_operational', 'attachment_continuity'] as $key) {
      $v = (string) ($artifacts[$key] ?? '');
      if ($v === 'missing') {
        $candidates[] = 'warning';
      }
      elseif ($v === 'mixed') {
        $candidates[] = 'moderate';
      }
    }

    $guest = is_array($merged['guest_continuity'] ?? NULL) ? $merged['guest_continuity'] : [];
    foreach ($guest['continuity_statuses_observed'] ?? [] as $st) {
      if ((string) $st === 'invalid') {
        $candidates[] = 'moderate';
      }
    }

    return $this->maxSeverityToken($candidates);
  }

  /**
   * @param list<string> $tokens
   */
  private function maxSeverityToken(array $tokens): string {
    $best = 'low';
    $bestRank = -1;
    foreach ($tokens as $t) {
      $n = $this->normalizeSeverity($t);
      $r = self::SEVERITY_RANK[$n] ?? 0;
      if ($r > $bestRank) {
        $bestRank = $r;
        $best = $n;
      }
    }
    return $best;
  }

}
