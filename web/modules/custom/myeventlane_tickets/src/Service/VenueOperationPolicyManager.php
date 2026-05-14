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
    private readonly ZoneAccessPolicyManager $zoneAccessPolicyManager,
    private readonly DeviceOperationIdentityManager $deviceOperationIdentityManager,
    private readonly OperationalContinuityPolicyManager $operationalContinuityPolicyManager,
    private readonly OccupancyPolicyManager $occupancyPolicyManager,
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
      'offline_eligible' => $this->operationalContinuityPolicyManager->evaluateOfflineEligibilityScaffold($map, $mode),
      'requires_online_validation' => FALSE,
      'conflict_policy' => $this->conflictPolicy($type, $map),
      'multi_use' => (bool) $map['multi_use'],
      'replay_protected' => TRUE,
      'gate_family' => $this->gateFamily($type, $map),
      'idempotent_validate_family' => $this->idempotentValidateFamily($map),
      'timed_entry' => $timed,
      'session_entitlement' => $session,
      'zone_topology' => $this->zoneAccessPolicyManager->buildTopologyDescriptor($ticket, $timed, $session),
      'zone_conflict_policy' => $this->normalizeZoneConflictPolicy($ticket),
      'occupancy' => $this->occupancyPolicyManager->buildOccupancyDescriptor($ticket, $mode),
    ];
  }

  /**
   * Maps an existing scanner/API result token to a normalized operation label.
   */
  public function normalizeScannerResultToken(string $scanner_result_token): string {
    return $this->operationalContinuityPolicyManager->normalizeScannerResultToken($scanner_result_token);
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
   * Composes timed entry, session entitlement, and zone topology gates.
   *
   * @return array<string, mixed>
   *   Keys: allow (bool), result_token (string), message (string), policy (array).
   */
  public function evaluateZoneAccessForScan(
    Ticket $ticket,
    int $now,
    ?int $parsed_qr_expires_at,
    ?string $requested_zone_id = NULL,
  ): array {
    $composition = $this->evaluateTimedEntryForScan($ticket, $now, $parsed_qr_expires_at);
    if (!$composition['allow']) {
      return $composition;
    }

    $timed = $composition['policy']['timed_entry'] ?? [];
    $session = $composition['policy']['session_entitlement'] ?? [];
    $requested = $requested_zone_id !== NULL && trim($requested_zone_id) !== '' ? trim($requested_zone_id) : NULL;

    $zone = $this->zoneAccessPolicyManager->evaluateZoneAccessForComposition(
      $ticket,
      $requested,
      is_array($timed) ? $timed : [],
      is_array($session) ? $session : [],
    );

    if (!$zone['allow']) {
      return [
        'allow' => FALSE,
        'result_token' => $zone['result_token'],
        'message' => $zone['message'],
        'policy' => [
          'timed_entry' => $composition['policy']['timed_entry'],
          'session_entitlement' => $composition['policy']['session_entitlement'],
          'zone_access' => $zone['policy'],
        ],
      ];
    }

    return [
      'allow' => TRUE,
      'result_token' => '',
      'message' => '',
      'policy' => [
        'timed_entry' => $composition['policy']['timed_entry'],
        'session_entitlement' => $composition['policy']['session_entitlement'],
        'zone_access' => $zone['policy'],
      ],
    ];
  }

  /**
   * Orchestrates operational identity metadata with the composed scan gate.
   *
   * Delegates timing, session, and zone decisions to existing managers; does
   * not implement parallel gate logic.
   *
   * @param array<string, mixed> $raw_operational_context
   *   Merged operational device context (see DeviceOperationIdentityManager).
   *
   * @return array<string, mixed>
   *   Keys: scan_gate, checkpoint_descriptor, operational_identity.
   */
  public function evaluateOperationalIdentity(
    Ticket $ticket,
    array $raw_operational_context,
    int $now,
    ?int $parsed_qr_expires_at,
    ?string $requested_zone_id = NULL,
  ): array {
    $normalized = $this->deviceOperationIdentityManager->normalizeOperationalIdentity($raw_operational_context, '');
    $zone_from_identity = ($normalized['zone_id'] ?? '') !== '' ? (string) $normalized['zone_id'] : NULL;
    $effective_zone = $requested_zone_id !== NULL && trim($requested_zone_id) !== '' ? trim($requested_zone_id) : NULL;
    $effective_zone ??= $zone_from_identity;
    $scan = $this->evaluateZoneAccessForScan($ticket, $now, $parsed_qr_expires_at, $effective_zone);
    if (!empty($scan['allow'])) {
      $occ = $this->occupancyPolicyManager->evaluateOccupancyForScan(
        $ticket,
        $now,
        $parsed_qr_expires_at,
        $raw_operational_context,
        $effective_zone,
        is_array($scan['policy'] ?? NULL) ? $scan['policy'] : [],
      );
      $policy = is_array($scan['policy'] ?? NULL) ? $scan['policy'] : [];
      $policy['occupancy'] = $occ['policy'];
      if (empty($occ['allow'])) {
        $scan = [
          'allow' => FALSE,
          'result_token' => (string) $occ['result_token'],
          'message' => (string) $occ['message'],
          'policy' => $policy,
        ];
      }
      else {
        $scan['policy'] = $policy;
      }
    }
    $policy_snapshot = is_array($scan['policy'] ?? NULL) ? $scan['policy'] : [];
    $checkpoint = $this->buildOperationalCheckpointDescriptor(
      $ticket,
      $normalized,
      $now,
      $parsed_qr_expires_at,
      $effective_zone,
      $policy_snapshot,
    );
    $descriptors = $this->deviceOperationIdentityManager->buildOperationalIdentityDescriptors(
      $ticket,
      $normalized,
      $policy_snapshot,
    );
    $trust = $this->normalizeDeviceTrustPolicy((string) ($normalized['trust_level'] ?? ''));

    return [
      'scan_gate' => $scan,
      'checkpoint_descriptor' => $checkpoint,
      'operational_identity' => $descriptors + ['trust_policy' => $trust],
    ];
  }

  /**
   * Orchestrates operational identity with canonical continuity metadata.
   *
   * @param array<string, mixed> $raw_operational_context
   *
   * @return array<string, mixed>
   *   Keys: scan_gate, checkpoint_descriptor, operational_identity, operational_continuity.
   */
  public function evaluateOperationalContinuity(
    Ticket $ticket,
    array $raw_operational_context,
    int $now,
    ?int $parsed_qr_expires_at,
    ?string $requested_zone_id = NULL,
  ): array {
    $bundle = $this->evaluateOperationalIdentity(
      $ticket,
      $raw_operational_context,
      $now,
      $parsed_qr_expires_at,
      $requested_zone_id,
    );
    $normalized_identity = is_array($bundle['operational_identity']['normalized'] ?? NULL)
      ? $bundle['operational_identity']['normalized']
      : [];
    $ledger_mode = !empty($normalized_identity['offline_mode']) ? 'offline' : 'online';
    $continuity = $this->operationalContinuityPolicyManager->buildMachineSafeContinuityPayload(
      $ticket,
      $raw_operational_context,
      $normalized_identity,
      is_array($bundle['scan_gate']['policy'] ?? NULL) ? $bundle['scan_gate']['policy'] : [],
      $ledger_mode,
      $parsed_qr_expires_at !== NULL && $parsed_qr_expires_at > 0 ? $parsed_qr_expires_at : NULL,
    );

    return $bundle + [
      'operational_continuity' => $continuity,
    ];
  }

  /**
   * Normalizes raw continuity / reconciliation material for callers.
   *
   * @param array<string, mixed> $raw
   *
   * @return array<string, mixed>
   */
  public function normalizeReconciliationPolicy(array $raw): array {
    return $this->operationalContinuityPolicyManager->normalizeReconciliationMetadata($raw);
  }

  /**
   * Normalizes occupancy metadata blobs (machine-stable tokens only).
   *
   * @param array<string, mixed> $raw
   *
   * @return array<string, mixed>
   */
  public function normalizeOccupancyPolicies(array $raw): array {
    return $this->occupancyPolicyManager->normalizeOccupancyPolicies($raw);
  }

  /**
   * Evaluates occupancy + directional composition after timing/session/zone gates.
   *
   * @param array<string, mixed> $raw_operational_context
   * @param array<string, mixed> $scan_gate_policy
   *
   * @return array<string, mixed>
   */
  public function evaluateOccupancyPolicies(
    Ticket $ticket,
    int $now,
    ?int $parsed_qr_expires_at,
    array $raw_operational_context,
    ?string $effective_zone_id,
    array $scan_gate_policy,
  ): array {
    return $this->occupancyPolicyManager->evaluateOccupancyForScan(
      $ticket,
      $now,
      $parsed_qr_expires_at,
      $raw_operational_context,
      $effective_zone_id,
      $scan_gate_policy,
    );
  }

  /**
   * Deterministic occupancy descriptor (metadata composition only).
   *
   * @param array<string, mixed>|null $scan_gate_policy
   *
   * @return array<string, mixed>
   */
  public function buildOccupancyDescriptor(Ticket $ticket, string $mode = 'online', ?array $scan_gate_policy = NULL): array {
    return $this->occupancyPolicyManager->buildOccupancyDescriptor($ticket, $mode, $scan_gate_policy);
  }

  /**
   * Builds a continuity descriptor (timed + session + zone + reconciliation).
   *
   * @return array<string, mixed>
   */
  public function buildContinuityDescriptor(Ticket $ticket, string $mode = 'online'): array {
    return $this->operationalContinuityPolicyManager->buildContinuityDescriptor($ticket, $mode);
  }

  /**
   * Normalizes device trust labels for policy orchestration (metadata-only).
   *
   * @return array<string, mixed>
   */
  public function normalizeDeviceTrustPolicy(string $trust_level_raw): array {
    $canonical = $this->deviceOperationIdentityManager->classifyTrustLevel($trust_level_raw);
    return [
      'canonical_trust' => $canonical,
      'policy_token' => match ($canonical) {
        'elevated' => 'trust_elevated',
        'limited' => 'trust_limited',
        'standard' => 'trust_standard',
        default => 'trust_unknown',
      },
    ];
  }

  /**
   * Machine-only checkpoint + gate composition for audits and observability.
   *
   * @param array<string, mixed> $normalized_identity
   *   Output of DeviceOperationIdentityManager::normalizeOperationalIdentity().
   * @param array<string, mixed> $scan_gate_policy_snapshot
   *   The `policy` array from evaluateZoneAccessForScan().
   *
   * @return array<string, mixed>
   */
  public function buildOperationalCheckpointDescriptor(
    Ticket $ticket,
    array $normalized_identity,
    int $now,
    ?int $parsed_qr_expires_at,
    ?string $effective_zone_id,
    array $scan_gate_policy_snapshot,
  ): array {
    $timed = is_array($scan_gate_policy_snapshot['timed_entry'] ?? NULL)
      ? $scan_gate_policy_snapshot['timed_entry']
      : $this->timedEntryPolicyManager->evaluate($ticket, $now, $parsed_qr_expires_at);
    $session = is_array($scan_gate_policy_snapshot['session_entitlement'] ?? NULL)
      ? $scan_gate_policy_snapshot['session_entitlement']
      : $this->sessionEntitlementPolicyManager->buildNormalizedPayload($ticket, $now, $parsed_qr_expires_at, $timed);
    $zone_slice = is_array($scan_gate_policy_snapshot['zone_access'] ?? NULL)
      ? $scan_gate_policy_snapshot['zone_access']
      : [];
    $topology_id = '';
    if ($zone_slice !== []) {
      $topology_id = (string) (($zone_slice['topology'] ?? [])['topology_id'] ?? $zone_slice['topology_id'] ?? '');
    }

    return [
      'checkpoint_id' => (string) ($normalized_identity['checkpoint_id'] ?? ''),
      'gate_id' => (string) ($normalized_identity['gate_id'] ?? ''),
      'device_id' => (string) ($normalized_identity['device_id'] ?? ''),
      'effective_zone_request' => $effective_zone_id !== NULL && $effective_zone_id !== '' ? $effective_zone_id : '',
      'scan_mode' => (string) ($normalized_identity['scan_mode'] ?? ''),
      'reconciliation_group' => (string) ($normalized_identity['reconciliation_group'] ?? ''),
      'offline_mode' => (bool) ($normalized_identity['offline_mode'] ?? FALSE),
      'timed_scanner' => is_array($timed['scanner'] ?? NULL) ? $timed['scanner'] : [],
      'session_scanner' => is_array($session['scanner'] ?? NULL) ? $session['scanner'] : [],
      'zone_scanner' => is_array($zone_slice['scanner'] ?? NULL) ? $zone_slice['scanner'] : [],
      'topology_id' => $topology_id,
      'operator_attribution_present' => (($normalized_identity['operator_id'] ?? '') !== '' || ($normalized_identity['operator_role'] ?? '') !== ''),
    ];
  }

  /**
   * Machine-only zone topology descriptor (inward composition).
   *
   * @return array<string, mixed>
   */
  public function buildZoneTopologyDescriptor(Ticket $ticket): array {
    $now = $this->time->getCurrentTime();
    $timed = $this->timedEntryPolicyManager->evaluate($ticket, $now, NULL);
    $session = $this->sessionEntitlementPolicyManager->buildNormalizedPayload($ticket, $now, NULL, $timed);
    return $this->zoneAccessPolicyManager->buildTopologyDescriptor($ticket, $timed, $session);
  }

  /**
   * Normalizes zone-layer conflict policy for integrity scaffolding.
   */
  public function normalizeZoneConflictPolicy(Ticket $ticket): string {
    return $this->zoneAccessPolicyManager->describeConflictPolicyToken($ticket);
  }

  /**
   * Interprets a denied scan for structured conflict metadata (machine-only).
   *
   * @return array<string, mixed>
   */
  public function interpretDenial(?Ticket $ticket, string $scanner_result_token): array {
    return $this->operationalContinuityPolicyManager->interpretScannerDenial($ticket, $scanner_result_token);
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
      'offline_eligible' => $this->operationalContinuityPolicyManager->evaluateOfflineEligibilityScaffold($map, 'online'),
      'requires_online_validation' => FALSE,
      'replay_protected' => TRUE,
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
