<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Site\Settings;
use Drupal\myeventlane_tickets\Entity\RedemptionLog;
use Drupal\myeventlane_tickets\Entity\Ticket;

/**
 * Canonical venue operation execution policy for ticket-backed entitlements.
 *
 * Centralizes gate semantics, offline scaffolding metadata, replay-oriented
 * fingerprints, and scanner-result normalization. Callers must still enforce
 * entity mutations through existing scanner services; this class is policy
 * and metadata only (no saves, no QR changes, no entitlement bypass).
 */
final class VenueOperationPolicyManager {

  /**
   * Normalized gate / outcome vocabulary aligned with scanner + registry usage.
   *
   * Reserved tokens include outcome-style types derived from existing scanner
   * result strings; gate execution continues to use RedemptionLog action tokens.
   */
  public const OPERATION_ADMIT = 'admit';

  public const OPERATION_REDEEM = 'redeem';

  public const OPERATION_COLLECT = 'collect';

  public const OPERATION_VALIDATE = 'validate';

  public const OPERATION_VERIFY = 'verify';

  /**
   * Idempotent validate-family semantics (parking-style) — registry still uses
   * RedemptionLog::ACTION_VALIDATE; this token is used only in diagnostics.
   */
  public const OPERATION_REVALIDATE = 'revalidate';

  public const OPERATION_EXPIRED = 'expired';

  public const OPERATION_DUPLICATE = 'duplicate';

  public const OPERATION_ALREADY_USED = 'already_used';

  public const OPERATION_DENY = 'deny';

  public function __construct(
    private readonly EntitlementCapabilityRegistry $entitlementCapabilityRegistry,
    private readonly TicketCapabilityManager $ticketCapabilityManager,
    private readonly TimeInterface $time,
    private readonly TimedEntryPolicyManager $timedEntryPolicyManager,
    private readonly SessionEntitlementPolicyManager $sessionEntitlementPolicyManager,
  ) {}

  /**
   * Lists canonical machine operation tokens supported by this phase.
   *
   * @return list<string>
   */
  public function canonicalOperationVocabulary(): array {
    return [
      self::OPERATION_ADMIT,
      self::OPERATION_REDEEM,
      self::OPERATION_COLLECT,
      self::OPERATION_VALIDATE,
      self::OPERATION_VERIFY,
      self::OPERATION_REVALIDATE,
      self::OPERATION_EXPIRED,
      self::OPERATION_DUPLICATE,
      self::OPERATION_ALREADY_USED,
      self::OPERATION_DENY,
    ];
  }

  /**
   * Resolves the RedemptionLog / registry gate action for a ticket row.
   */
  public function resolveGateAction(Ticket $ticket): string {
    $type = $this->ticketCapabilityManager->getEntitlementType($ticket);
    return $this->entitlementCapabilityRegistry->getScannerOperationAction($type);
  }

  /**
   * Machine-only gate semantics for one normalized entitlement type.
   *
   * @return array<string, mixed>
   */
  public function describeEntitlementGateSemantics(string $normalized_entitlement_type): array {
    $type = $this->entitlementCapabilityRegistry->normalizeEntitlementType($normalized_entitlement_type);
    $map = $this->entitlementCapabilityRegistry->getCapabilityMap($type);
    $gate_action = (string) $map['scanner_mode'];
    $family = $this->gateFamily($type, $map);

    return [
      'entitlement_type' => $type,
      'gate_action' => $gate_action,
      'gate_family' => $family,
      'idempotent_validate_family' => $this->idempotentValidateFamily($map),
      'repeat_scan_semantic' => $this->idempotentValidateFamily($map) ? self::OPERATION_REVALIDATE : self::OPERATION_VALIDATE,
      'multi_use' => (bool) $map['multi_use'],
      'redeemable' => (bool) $map['redeemable'],
      'supports_collection' => (bool) $map['supports_collection'],
    ];
  }

  /**
   * Static policy descriptor for a concrete ticket row (no UI strings).
   *
   * @return array<string, mixed>
   */
  public function buildOperationDescriptor(Ticket $ticket, string $mode = 'online'): array {
    $type = $this->ticketCapabilityManager->getEntitlementType($ticket);
    $map = $this->entitlementCapabilityRegistry->getCapabilityMap($type);
    $gate_action = (string) $map['scanner_mode'];

    $now = $this->time->getCurrentTime();
    $timed = $this->timedEntryPolicyManager->evaluate($ticket, $now, NULL);
    $session = $this->sessionEntitlementPolicyManager->buildNormalizedPayload($ticket, $now, NULL, $timed);

    return [
      'operation_type' => $gate_action,
      'entitlement_type' => $type,
      'offline_eligible' => $this->offlineEligible($map, $mode),
      'requires_online_validation' => FALSE,
      'conflict_policy' => $this->conflictPolicy($type, $map),
      'multi_use' => (bool) $map['multi_use'],
      'replay_protected' => TRUE,
      'gate_family' => $this->gateFamily($type, $map),
      'idempotent_validate_family' => $this->idempotentValidateFamily($map),
      'timed_entry' => $timed,
      'session_entitlement' => $session,
    ];
  }

  /**
   * Maps an existing scanner/API result token to a normalized operation label.
   */
  public function normalizeScannerResultToken(string $scanner_result_token): string {
    return match ($scanner_result_token) {
      'admitted' => self::OPERATION_ADMIT,
      'redeemed' => self::OPERATION_REDEEM,
      'collected' => self::OPERATION_COLLECT,
      'validated' => self::OPERATION_VALIDATE,
      'verified' => self::OPERATION_VERIFY,
      'expired' => self::OPERATION_EXPIRED,
      'already_checked_in', 'redemption_limit_reached' => self::OPERATION_ALREADY_USED,
      'duplicate', 'duplicate_operation' => self::OPERATION_DUPLICATE,
      default => self::OPERATION_DENY,
    };
  }

  /**
   * Composes timed entry and session entitlement gates for the scanner path.
   *
   * @param int|null $parsed_qr_expires_at
   *   Structured QR `exp` cap when present; null otherwise.
   *
   * @return array<string, mixed>
   *   Keys: allow (bool), result_token (string), message (string), policy (array).
   */
  public function evaluateTimedEntryForScan(Ticket $ticket, int $now, ?int $parsed_qr_expires_at): array {
    $timed = $this->timedEntryPolicyManager->evaluate($ticket, $now, $parsed_qr_expires_at);
    $session = $this->sessionEntitlementPolicyManager->buildNormalizedPayload($ticket, $now, $parsed_qr_expires_at, $timed);

    if (!is_array($timed['scanner'] ?? NULL)) {
      $timed['scanner'] = [];
    }
    if (!is_array($session['scanner'] ?? NULL)) {
      $session['scanner'] = [];
    }

    $policy = [
      'timed_entry' => $timed,
      'session_entitlement' => $session,
    ];

    // Fail closed: missing or malformed scanner slices must not admit.
    $timing_ok = (bool) ($timed['scanner']['allowed_now'] ?? FALSE);
    $session_ok = (bool) ($session['scanner']['allowed_now'] ?? FALSE);
    if ($timing_ok && $session_ok) {
      return [
        'allow' => TRUE,
        'result_token' => '',
        'message' => '',
        'policy' => $policy,
      ];
    }

    if (!$timing_ok) {
      return $this->mapTimingScannerDenial($timed) + ['policy' => $policy];
    }

    return $this->mapSessionScannerDenial($session) + ['policy' => $policy];
  }

  /**
   * Interprets a denied scan for structured conflict metadata (machine-only).
   *
   * @return array<string, mixed>
   */
  public function interpretDenial(?Ticket $ticket, string $scanner_result_token): array {
    $normalized = $this->normalizeScannerResultToken($scanner_result_token);
    $gate_action = $ticket instanceof Ticket ? $this->resolveGateAction($ticket) : '';
    $type = $ticket instanceof Ticket ? $this->ticketCapabilityManager->getEntitlementType($ticket) : '';

    return [
      'normalized_operation' => $normalized,
      'scanner_result' => $scanner_result_token,
      'gate_action' => $gate_action,
      'entitlement_type' => $type,
      'conflict' => match ($scanner_result_token) {
        'already_checked_in' => 'admission_replay',
        'redemption_limit_reached' => 'redemption_exhausted',
        'expired' => 'entitlement_expired',
        'wrong_event', 'invalid' => 'scope_or_integrity',
        'void', 'refunded' => 'entitlement_revoked',
        default => 'operational_deny',
      },
    ];
  }

  /**
   * Deterministic fingerprint for duplicate-operation detection scaffolding.
   *
   * Callers compare consecutive attempts in future sync layers; this phase
   * only materializes the fingerprint in staff-side audit metadata.
   */
  public function duplicateOperationFingerprint(
    int $ticket_id,
    string $gate_action,
    int $redemption_count_snapshot,
    string $normalized_device_id,
    string $payload_sha256,
  ): string {
    $canonical = implode('|', [
      (string) $ticket_id,
      $gate_action,
      (string) $redemption_count_snapshot,
      $normalized_device_id,
      $payload_sha256,
    ]);

    return hash('sha256', $canonical);
  }

  /**
   * Builds staff-side integrity metadata for redemption audit rows.
   *
   * @param string $mode
   *   Scan transport context: 'online' or 'offline' (feeds offline eligibility).
   *
   * @return array<string, mixed>
   */
  public function buildIntegrityEnvelope(
    Ticket $ticket,
    string $gate_action,
    bool $ok,
    string $scanner_result_token,
    string $normalized_device_id,
    int $operation_unix,
    int $redemption_count_before,
    int $redemption_count_after,
    string $payload_sha256,
    string $mode = 'online',
  ): array {
    $type = $this->ticketCapabilityManager->getEntitlementType($ticket);
    $map = $this->entitlementCapabilityRegistry->getCapabilityMap($type);
    $fingerprint = $this->duplicateOperationFingerprint(
      (int) $ticket->id(),
      $gate_action,
      $redemption_count_before,
      $normalized_device_id,
      $payload_sha256,
    );

    $operation_id = $this->buildOperationId(
      (int) $ticket->id(),
      $gate_action,
      $operation_unix,
      $redemption_count_after,
      $fingerprint,
    );

    $replay_token = $this->buildReplayToken($operation_id, $gate_action, (string) $ticket->uuid());
    $timed = $this->timedEntryPolicyManager->evaluate($ticket, $operation_unix, NULL);

    return [
      'operation_id' => $operation_id,
      'operation_timestamp' => $operation_unix,
      'operation_fingerprint' => $fingerprint,
      'replay_token' => $replay_token,
      'normalized_result' => $this->normalizeScannerResultToken($scanner_result_token),
      'ok' => $ok,
      'scanner_result' => $scanner_result_token,
      'gate_action' => $gate_action,
      'entitlement_type' => $type,
      'redemption_count_before' => $redemption_count_before,
      'redemption_count_after' => $redemption_count_after,
      'multi_use' => (bool) $map['multi_use'],
      'conflict_policy' => $this->conflictPolicy($type, $map),
      'offline_eligible' => $this->offlineEligible($map, $mode),
      'requires_online_validation' => FALSE,
      'replay_protected' => TRUE,
      'timed_entry_scanner_state' => (string) ($timed['scanner']['state'] ?? ''),
      'timed_entry_reason' => (string) ($timed['scanner']['reason'] ?? ''),
    ];
  }

  /**
   * TRUE when a second attempt with the same pre-mutation fingerprint is a replay.
   */
  public function isProbableDuplicateReplay(
    string $fingerprint_a,
    string $fingerprint_b,
  ): bool {
    return $fingerprint_a !== '' && strcasecmp($fingerprint_a, $fingerprint_b) === 0;
  }

  /**
   * @param array<string, mixed> $map
   */
  private function offlineEligible(array $map, string $mode): bool {
    if (!in_array($mode, ['online', 'offline'], TRUE)) {
      return FALSE;
    }
    // Current spine resolves state from persisted ticket rows and QR integrity
    // only; no live Commerce calls on the scan path — safe offline scaffold.
    return TRUE;
  }

  /**
   * @param array<string, mixed> $map
   */
  private function conflictPolicy(string $type, array $map): string {
    if ($this->entitlementCapabilityRegistry->isAdmissionEntitlementType($type)) {
      return 'reject_duplicate';
    }
    if ($map['scanner_mode'] === RedemptionLog::ACTION_VALIDATE && !$map['redeemable']) {
      return 'allow_idempotent_rescan';
    }
    // Verify-only entitlements (e.g. VIP): scanner does not mutate state; repeats
    // are successful re-verifications, not duplicate-operation rejects.
    if ($map['scanner_mode'] === RedemptionLog::ACTION_VERIFY && !$map['redeemable']) {
      return 'allow_idempotent_rescan';
    }
    if ((bool) $map['multi_use']) {
      return 'allow_multi_use_until_limit';
    }
    if ((bool) $map['supports_collection']) {
      return 'single_fulfilment_mutate';
    }
    return 'reject_duplicate';
  }

  /**
   * @param array<string, mixed> $map
   */
  private function gateFamily(string $type, array $map): string {
    if ($this->entitlementCapabilityRegistry->isAdmissionEntitlementType($type)) {
      return 'admission';
    }
    return match ($map['scanner_mode']) {
      RedemptionLog::ACTION_COLLECT => 'merch_collection',
      RedemptionLog::ACTION_VALIDATE => 'parking_validation',
      RedemptionLog::ACTION_REDEEM => 'consumption_redemption',
      RedemptionLog::ACTION_VERIFY => 'vip_verification',
      default => 'admission',
    };
  }

  /**
   * @param array<string, mixed> $map
   */
  private function idempotentValidateFamily(array $map): bool {
    return $map['scanner_mode'] === RedemptionLog::ACTION_VALIDATE
      && !(bool) $map['redeemable']
      && !(bool) $map['multi_use'];
  }

  private function buildOperationId(
    int $ticket_id,
    string $gate_action,
    int $operation_unix,
    int $redemption_count_after,
    string $fingerprint,
  ): string {
    $material = implode('|', [
      (string) $ticket_id,
      $gate_action,
      (string) $operation_unix,
      (string) $redemption_count_after,
      $fingerprint,
    ]);

    return 'op_' . substr(hash('sha256', $material), 0, 40);
  }

  private function buildReplayToken(string $operation_id, string $gate_action, string $ticket_uuid): string {
    $salt = Settings::getHashSalt();
    return hash_hmac('sha256', $operation_id . '|' . $gate_action . '|' . $ticket_uuid, $salt);
  }

  /**
   * @param array<string, mixed> $timed
   *
   * @return array{allow: bool, result_token: string, message: string}
   */
  private function mapTimingScannerDenial(array $timed): array {
    $state = (string) ($timed['scanner']['state'] ?? 'expired');
    if ($state === 'not_started') {
      return [
        'allow' => FALSE,
        'result_token' => 'invalid',
        'message' => 'Entry is not open yet.',
      ];
    }
    if ($state === 'early') {
      return [
        'allow' => FALSE,
        'result_token' => 'invalid',
        'message' => 'Entry is not valid yet.',
      ];
    }

    return [
      'allow' => FALSE,
      'result_token' => 'expired',
      'message' => 'Entitlement has expired.',
    ];
  }

  /**
   * @param array<string, mixed> $session
   *
   * @return array{allow: bool, result_token: string, message: string}
   */
  private function mapSessionScannerDenial(array $session): array {
    $reason = (string) ($session['scanner']['reason'] ?? '');
    if ($reason === 'sequence_previous_missing') {
      return [
        'allow' => FALSE,
        'result_token' => 'invalid',
        'message' => 'Operational sequence not satisfied.',
      ];
    }

    return [
      'allow' => FALSE,
      'result_token' => 'redemption_limit_reached',
      'message' => 'Redemption limit has already been reached.',
    ];
  }

}
