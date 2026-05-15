<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

/**
 * Canonical workflow tokens for operational coordination (not scanner truth).
 */
final class OperationalIncidentWorkflowNormalizer {

  public const LIFECYCLE_DETECTED = 'detected';

  public const LIFECYCLE_ACKNOWLEDGED = 'acknowledged';

  public const LIFECYCLE_INVESTIGATING = 'investigating';

  public const LIFECYCLE_MONITORING = 'monitoring';

  public const LIFECYCLE_RESOLVED = 'resolved';

  public const LIFECYCLE_SUPPRESSED = 'suppressed';

  public const ESCALATION_NONE = 'none';

  public const ESCALATION_REVIEW = 'review';

  public const ESCALATION_URGENT = 'urgent';

  public const ESCALATION_EXECUTIVE = 'executive';

  public const ACK_UNASSIGNED = 'unassigned';

  public const ACK_ASSIGNED = 'assigned';

  public const ACK_ACCEPTED = 'accepted';

  public const ACK_HANDED_OFF = 'handed_off';

  /**
   * @return list<string>
   */
  public function allowedLifecycleStates(): array {
    return [
      self::LIFECYCLE_DETECTED,
      self::LIFECYCLE_ACKNOWLEDGED,
      self::LIFECYCLE_INVESTIGATING,
      self::LIFECYCLE_MONITORING,
      self::LIFECYCLE_RESOLVED,
      self::LIFECYCLE_SUPPRESSED,
    ];
  }

  /**
   * @return list<string>
   */
  public function allowedEscalationStates(): array {
    return [
      self::ESCALATION_NONE,
      self::ESCALATION_REVIEW,
      self::ESCALATION_URGENT,
      self::ESCALATION_EXECUTIVE,
    ];
  }

  /**
   * @return list<string>
   */
  public function allowedAcknowledgementStates(): array {
    return [
      self::ACK_UNASSIGNED,
      self::ACK_ASSIGNED,
      self::ACK_ACCEPTED,
      self::ACK_HANDED_OFF,
    ];
  }

  public function normalizeLifecycleState(string $raw): string {
    $t = strtolower(trim($raw));
    return in_array($t, $this->allowedLifecycleStates(), TRUE) ? $t : self::LIFECYCLE_DETECTED;
  }

  public function normalizeEscalationState(string $raw): string {
    $t = strtolower(trim($raw));
    return in_array($t, $this->allowedEscalationStates(), TRUE) ? $t : self::ESCALATION_NONE;
  }

  public function normalizeAcknowledgementState(string $raw): string {
    $t = strtolower(trim($raw));
    return in_array($t, $this->allowedAcknowledgementStates(), TRUE) ? $t : self::ACK_UNASSIGNED;
  }

  public function isAllowedLifecycleTransition(string $from, string $to): bool {
    $from = $this->normalizeLifecycleState($from);
    $to = $this->normalizeLifecycleState($to);
    if ($from === $to) {
      return TRUE;
    }
    $map = [
      self::LIFECYCLE_DETECTED => [
        self::LIFECYCLE_ACKNOWLEDGED,
        self::LIFECYCLE_INVESTIGATING,
        self::LIFECYCLE_SUPPRESSED,
      ],
      self::LIFECYCLE_ACKNOWLEDGED => [
        self::LIFECYCLE_INVESTIGATING,
        self::LIFECYCLE_MONITORING,
        self::LIFECYCLE_RESOLVED,
        self::LIFECYCLE_SUPPRESSED,
      ],
      self::LIFECYCLE_INVESTIGATING => [
        self::LIFECYCLE_MONITORING,
        self::LIFECYCLE_RESOLVED,
        self::LIFECYCLE_SUPPRESSED,
      ],
      self::LIFECYCLE_MONITORING => [
        self::LIFECYCLE_RESOLVED,
        self::LIFECYCLE_SUPPRESSED,
      ],
      self::LIFECYCLE_RESOLVED => [],
      self::LIFECYCLE_SUPPRESSED => [],
    ];
    return in_array($to, $map[$from] ?? [], TRUE);
  }

  /**
   * True when lifecycle indicates operational noise / false-positive handling.
   */
  public function isSuppressedLifecycle(string $lifecycle): bool {
    return $this->normalizeLifecycleState($lifecycle) === self::LIFECYCLE_SUPPRESSED;
  }

}
