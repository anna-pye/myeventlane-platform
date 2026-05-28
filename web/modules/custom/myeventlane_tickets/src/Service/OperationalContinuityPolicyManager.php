<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\myeventlane_tickets\Entity\Ticket;

/**
 * Canonical operational continuity policy for offline scaffolding metadata.
 *
 * Normalizes reconciliation and continuity blobs only. Commerce entities and
 * entitlement registry remain authoritative; this layer must not mint
 * alternate entitlement truth or scanner result tokens.
 */
final class OperationalContinuityPolicyManager {

  /**
   * Supported metadata_json container keys (canonical first).
   */
  public const METADATA_CONTINUITY_KEYS = [
    'mel_operational_continuity',
    'operational_continuity',
  ];

  /**
   * @var list<string>
   */
  private const CONTINUITY_SCALAR_KEYS = [
    'reconciliation_group',
    'continuity_epoch',
    'offline_sequence',
    'replay_window',
    'reconciliation_strategy',
    'conflict_strategy',
    'sync_hint',
    'continuity_mode',
    'recovery_scope',
  ];

  public function __construct(
    private readonly DeviceOperationIdentityManager $deviceOperationIdentityManager,
    private readonly ZoneAccessPolicyManager $zoneAccessPolicyManager,
    private readonly SessionEntitlementPolicyManager $sessionEntitlementPolicyManager,
    private readonly TimedEntryPolicyManager $timedEntryPolicyManager,
    private readonly EntitlementCapabilityRegistry $entitlementCapabilityRegistry,
    private readonly TicketCapabilityManager $ticketCapabilityManager,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Merges continuity blobs from layered sources (ticket metadata, request).
   *
   * @param array<string, mixed> ...$layers
   *
   * @return array<string, mixed>
   */
  public function mergeContinuityLayers(array ...$layers): array {
    $merged = [];
    foreach ($layers as $layer) {
      if ($layer === []) {
        continue;
      }
      $blob = $this->extractContinuityBlobFromLayer($layer);
      $merged = array_merge($merged, $blob);
    }
    return $merged;
  }

  /**
   * Merges ticket metadata continuity material with optional extra layers.
   *
   * @param array<string, mixed> ...$layers
   *
   * @return array<string, mixed>
   */
  public function mergeContinuityFromTicket(Ticket $ticket, array ...$layers): array {
    $base = $this->readTicketContinuityRaw($ticket);
    return $this->mergeContinuityLayers($base, ...$layers);
  }

  /**
   * Normalizes reconciliation / continuity scalars to machine-stable tokens.
   *
   * @param array<string, mixed> $raw
   *   Raw map (may include nested mel_operational_continuity blobs).
   *
   * @return array<string, mixed>
   */
  public function normalizeReconciliationMetadata(array $raw): array {
    $merged = $this->mergeContinuityLayers($raw);
    return [
      'reconciliation_group' => $this->normalizeToken((string) ($merged['reconciliation_group'] ?? ''), 256),
      'continuity_epoch' => $this->normalizeNonNegativeInt($merged['continuity_epoch'] ?? 0),
      'offline_sequence' => $this->normalizeNonNegativeInt($merged['offline_sequence'] ?? 0),
      'replay_window' => $this->normalizeReplayWindow($merged['replay_window'] ?? NULL),
      'reconciliation_strategy' => $this->normalizeReconciliationStrategy((string) ($merged['reconciliation_strategy'] ?? '')),
      'conflict_strategy' => $this->normalizeConflictStrategy((string) ($merged['conflict_strategy'] ?? '')),
      'sync_hint' => $this->normalizeSyncHint((string) ($merged['sync_hint'] ?? '')),
      'continuity_mode' => $this->normalizeContinuityMode((string) ($merged['continuity_mode'] ?? '')),
      'recovery_scope' => $this->normalizeRecoveryScope((string) ($merged['recovery_scope'] ?? '')),
    ];
  }

  /**
   * Offline-oriented continuity semantics derived from normalized reconciliation.
   *
   * @param array<string, mixed> $normalized_reconciliation
   *   Output of normalizeReconciliationMetadata().
   *
   * @return array<string, mixed>
   */
  public function normalizeOfflineContinuitySemantics(array $normalized_reconciliation): array {
    $mode = (string) ($normalized_reconciliation['continuity_mode'] ?? '');
    return [
      'offline_sequence' => (int) ($normalized_reconciliation['offline_sequence'] ?? 0),
      'continuity_mode' => $mode,
      'sync_hint' => (string) ($normalized_reconciliation['sync_hint'] ?? 'none'),
      'offline_continuity_class' => match ($mode) {
        'offline_staging', 'offline_replay' => 'deferred_commit',
        default => 'immediate',
      },
    ];
  }

  /**
   * Replay-oriented continuity semantics aligned with composed scan policy.
   *
   * @param array<string, mixed> $policy_snapshot
   *   Timed + session (+ optional zone) composition from venue scan evaluation.
   *
   * @return array<string, mixed>
   */
  public function normalizeReplayContinuitySemantics(
    Ticket $ticket,
    int $now,
    ?int $parsed_qr_expires_at,
    array $policy_snapshot,
  ): array {
    $timed = is_array($policy_snapshot['timed_entry'] ?? NULL)
      ? $policy_snapshot['timed_entry']
      : $this->timedEntryPolicyManager->evaluate($ticket, $now, $parsed_qr_expires_at);
    $session = is_array($policy_snapshot['session_entitlement'] ?? NULL)
      ? $policy_snapshot['session_entitlement']
      : $this->sessionEntitlementPolicyManager->buildNormalizedPayload($ticket, $now, $parsed_qr_expires_at, $timed);

    $replay_window_seconds = NULL;
    if (is_int($parsed_qr_expires_at) && $parsed_qr_expires_at > $now) {
      $replay_window_seconds = $parsed_qr_expires_at - $now;
    }

    return [
      'replay_window_seconds_hint' => $replay_window_seconds,
      'policy_replay_alignment' => [
        'timed_entry_state' => (string) ($timed['scanner']['state'] ?? ''),
        'timed_entry_allowed_now' => (bool) ($timed['scanner']['allowed_now'] ?? FALSE),
        'session_allowed_now' => (bool) ($session['scanner']['allowed_now'] ?? FALSE),
        'session_scanner_state' => (string) ($session['scanner']['state'] ?? ''),
      ],
    ];
  }

  /**
   * Normalizes scanner/API result tokens to venue operation vocabulary.
   */
  public function normalizeScannerResultToken(string $scanner_result_token): string {
    return match ($scanner_result_token) {
      'admitted' => VenueOperationPolicyManager::OPERATION_ADMIT,
      'redeemed' => VenueOperationPolicyManager::OPERATION_REDEEM,
      'collected' => VenueOperationPolicyManager::OPERATION_COLLECT,
      'validated' => VenueOperationPolicyManager::OPERATION_VALIDATE,
      'verified' => VenueOperationPolicyManager::OPERATION_VERIFY,
      'expired' => VenueOperationPolicyManager::OPERATION_EXPIRED,
      'already_checked_in', 'redemption_limit_reached' => VenueOperationPolicyManager::OPERATION_ALREADY_USED,
      'duplicate', 'duplicate_operation' => VenueOperationPolicyManager::OPERATION_DUPLICATE,
      default => VenueOperationPolicyManager::OPERATION_DENY,
    };
  }

  /**
   * Structured conflict descriptor for a scanner result (machine-only).
   *
   * @return array<string, mixed>
   */
  public function normalizeConflictDescriptor(?Ticket $ticket, string $scanner_result_token): array {
    return $this->interpretScannerDenial($ticket, $scanner_result_token);
  }

  /**
   * Interprets a scanner result as a structured conflict descriptor.
   *
   * @return array<string, mixed>
   */
  public function interpretScannerDenial(?Ticket $ticket, string $scanner_result_token): array {
    $normalized = $this->normalizeScannerResultToken($scanner_result_token);
    $type = $ticket instanceof Ticket ? $this->ticketCapabilityManager->getEntitlementType($ticket) : '';
    $gate_action = $ticket instanceof Ticket
      ? $this->entitlementCapabilityRegistry->getScannerOperationAction($type)
      : '';

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
   * Deterministic fingerprint for reconciliation / continuity correlation (staff).
   *
   * @param array<string, mixed> $normalized_reconciliation
   *   Output of normalizeReconciliationMetadata().
   *
   * @return array<string, string>
   *   Keys: reconciliation_fingerprint (sha256), continuity_descriptor_token.
   */
  public function buildDeterministicReconciliationFingerprints(
    int $ticket_id,
    array $normalized_reconciliation,
    string $topology_id,
    string $reconciliation_group_from_identity,
  ): array {
    $group = (string) ($normalized_reconciliation['reconciliation_group'] ?? '');
    if ($group === '' && $reconciliation_group_from_identity !== '') {
      $group = $reconciliation_group_from_identity;
    }
    $material = implode('|', [
      (string) $ticket_id,
      (string) ($normalized_reconciliation['continuity_epoch'] ?? 0),
      (string) ($normalized_reconciliation['offline_sequence'] ?? 0),
      (string) ($normalized_reconciliation['replay_window'] ?? 0),
      (string) ($normalized_reconciliation['reconciliation_strategy'] ?? ''),
      (string) ($normalized_reconciliation['conflict_strategy'] ?? ''),
      (string) ($normalized_reconciliation['sync_hint'] ?? ''),
      (string) ($normalized_reconciliation['continuity_mode'] ?? ''),
      (string) ($normalized_reconciliation['recovery_scope'] ?? ''),
      $group,
      $topology_id,
    ]);
    $fingerprint = hash('sha256', $material);
    return [
      'reconciliation_fingerprint' => $fingerprint,
      'continuity_descriptor_token' => 'mel_cont_' . substr($fingerprint, 0, 16),
    ];
  }

  /**
   * Recovery-oriented continuity policy labels (metadata-only).
   *
   * @param array<string, mixed> $normalized_reconciliation
   *
   * @return array<string, mixed>
   */
  public function buildRecoveryContinuityPolicy(array $normalized_reconciliation): array {
    $scope = (string) ($normalized_reconciliation['recovery_scope'] ?? 'ticket_row');
    return [
      'recovery_scope' => $scope,
      'sync_hint' => (string) ($normalized_reconciliation['sync_hint'] ?? 'none'),
      'reconciliation_strategy' => (string) ($normalized_reconciliation['reconciliation_strategy'] ?? 'prefer_authoritative_ledger'),
      'conflict_strategy' => (string) ($normalized_reconciliation['conflict_strategy'] ?? 'defer_to_venue_policy'),
      'recovery_continuity_class' => match ($scope) {
        'event_session' => 'session_scope_recovery',
        'reconciliation_group' => 'group_scope_recovery',
        default => 'ticket_row_recovery',
      },
    ];
  }

  /**
   * Machine-safe continuity payload for staff-side audit metadata.
   *
   * @param array<string, mixed> $merged_operational_context
   *   Raw operational context (may include continuity container keys).
   * @param array<string, mixed> $normalized_identity
   *   Normalized device identity map.
   * @param array<string, mixed> $policy_snapshot
   *   Output of scan gate `policy` array.
   *
   * @return array<string, mixed>
   */
  public function buildMachineSafeContinuityPayload(
    Ticket $ticket,
    array $merged_operational_context,
    array $normalized_identity,
    array $policy_snapshot,
    string $ledger_mode,
    ?int $parsed_qr_expires_at = NULL,
  ): array {
    $merged_continuity = $this->mergeContinuityFromTicket($ticket, $merged_operational_context);
    $reconciliation = $this->normalizeReconciliationMetadata($merged_continuity);
    $offline = $this->normalizeOfflineContinuitySemantics($reconciliation);
    $replay = $this->normalizeReplayContinuitySemantics(
      $ticket,
      $this->time->getCurrentTime(),
      $parsed_qr_expires_at !== NULL && $parsed_qr_expires_at > 0 ? $parsed_qr_expires_at : NULL,
      $policy_snapshot,
    );
    $zone_slice = is_array($policy_snapshot['zone_access'] ?? NULL) ? $policy_snapshot['zone_access'] : [];
    $topology_id = '';
    if ($zone_slice !== []) {
      $topology_id = (string) (($zone_slice['topology'] ?? [])['topology_id'] ?? $zone_slice['topology_id'] ?? '');
    }
    if ($topology_id === '') {
      $timed = is_array($policy_snapshot['timed_entry'] ?? NULL)
        ? $policy_snapshot['timed_entry']
        : $this->timedEntryPolicyManager->evaluate($ticket, $this->time->getCurrentTime(), NULL);
      $session = is_array($policy_snapshot['session_entitlement'] ?? NULL)
        ? $policy_snapshot['session_entitlement']
        : $this->sessionEntitlementPolicyManager->buildNormalizedPayload($ticket, $this->time->getCurrentTime(), NULL, $timed);
      $topology_id = (string) ($this->zoneAccessPolicyManager->buildTopologyDescriptor($ticket, $timed, $session)['topology_id'] ?? '');
    }

    $fingerprints = $this->buildDeterministicReconciliationFingerprints(
      (int) $ticket->id(),
      $reconciliation,
      $topology_id,
      (string) ($normalized_identity['reconciliation_group'] ?? ''),
    );

    $type = $this->ticketCapabilityManager->getEntitlementType($ticket);
    $map = $this->entitlementCapabilityRegistry->getCapabilityMap($type);

    return [
      'version' => 1,
      'ledger_mode' => $ledger_mode,
      'device_continuity_fingerprint' => $this->deviceOperationIdentityManager->buildReplaySafeDeviceFingerprint($normalized_identity),
      'reconciliation' => $reconciliation,
      'offline_continuity' => $offline,
      'replay_continuity' => $replay,
      'recovery_policy' => $this->buildRecoveryContinuityPolicy($reconciliation),
      'topology_continuity_ref' => $topology_id,
      'zone_conflict_policy_token' => $this->zoneAccessPolicyManager->describeConflictPolicyToken($ticket),
      'entitlement_type' => $type,
      'entitlement_capability_ref' => (string) ($map['scanner_mode'] ?? ''),
      'replay_diagnostics' => [
        'replay_alignment' => $replay['policy_replay_alignment'] ?? [],
        'offline_sequence' => $reconciliation['offline_sequence'],
        'continuity_epoch' => $reconciliation['continuity_epoch'],
      ],
    ] + $fingerprints;
  }

  /**
   * Static continuity descriptor for observability and orchestration callers.
   *
   * @return array<string, mixed>
   */
  public function buildContinuityDescriptor(Ticket $ticket, string $mode = 'online'): array {
    $now = $this->time->getCurrentTime();
    $timed = $this->timedEntryPolicyManager->evaluate($ticket, $now, NULL);
    $session = $this->sessionEntitlementPolicyManager->buildNormalizedPayload($ticket, $now, NULL, $timed);
    $topology = $this->zoneAccessPolicyManager->buildTopologyDescriptor($ticket, $timed, $session);
    $merged = $this->mergeContinuityFromTicket($ticket);
    $reconciliation = $this->normalizeReconciliationMetadata($merged);
    $type = $this->ticketCapabilityManager->getEntitlementType($ticket);
    $map = $this->entitlementCapabilityRegistry->getCapabilityMap($type);

    return [
      'reconciliation' => $reconciliation,
      'offline_eligible' => $this->evaluateOfflineEligibilityScaffold($map, $mode),
      'timed_entry' => $timed,
      'session_entitlement' => $session,
      'zone_topology' => $topology,
      'replay_continuity' => $this->normalizeReplayContinuitySemantics($ticket, $now, NULL, [
        'timed_entry' => $timed,
        'session_entitlement' => $session,
      ]),
      'recovery_policy' => $this->buildRecoveryContinuityPolicy($reconciliation),
    ];
  }

  /**
   * Customer-visible continuity hints (no replay tokens, fingerprints, or topology).
   *
   * @return array<string, mixed>
   */
  public function buildCustomerSafeContinuityProjection(Ticket $ticket): array {
    $desc = $this->buildContinuityDescriptor($ticket, 'online');
    $reconciliation = is_array($desc['reconciliation'] ?? NULL) ? $desc['reconciliation'] : [];
    $mode = (string) ($reconciliation['continuity_mode'] ?? '');
    if ($mode === '' || $mode === 'live_operations') {
      $mode = 'standard_operations';
    }
    return [
      'offline_capable' => (bool) ($desc['offline_eligible'] ?? FALSE),
      'continuity_mode' => $mode,
      'reconciliation_hint' => $this->mapSyncHintToPublicHint((string) ($reconciliation['sync_hint'] ?? 'none')),
    ];
  }

  /**
   * Mirrors venue offline eligibility scaffold (metadata-only, not a guarantee).
   *
   * @param array<string, mixed> $map
   */
  public function evaluateOfflineEligibilityScaffold(array $map, string $mode): bool {
    if (!in_array($mode, ['online', 'offline'], TRUE)) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * @param array<string, mixed> $layer
   *
   * @return array<string, mixed>
   */
  private function extractContinuityBlobFromLayer(array $layer): array {
    $blob = [];
    foreach (self::METADATA_CONTINUITY_KEYS as $key) {
      if (!empty($layer[$key]) && is_array($layer[$key])) {
        $blob = array_merge($blob, $layer[$key]);
      }
    }
    foreach (self::CONTINUITY_SCALAR_KEYS as $key) {
      if (!array_key_exists($key, $layer)) {
        continue;
      }
      $value = $layer[$key];
      if ($value === NULL || $value === '') {
        continue;
      }
      $blob[$key] = $value;
    }
    return $blob;
  }

  /**
   * @return array<string, mixed>
   */
  private function readTicketContinuityRaw(Ticket $ticket): array {
    if (!$ticket->hasField('metadata_json') || $ticket->get('metadata_json')->isEmpty()) {
      return [];
    }
    $value = $ticket->get('metadata_json')->first()?->getValue() ?? [];
    if (isset($value['value']) && is_array($value['value'])) {
      $map = $value['value'];
    }
    else {
      $map = is_array($value) ? $value : [];
    }
    return $this->extractContinuityBlobFromLayer($map);
  }

  private function normalizeToken(string $value, int $max_length): string {
    $normalized = preg_replace('/[^a-zA-Z0-9\-_.:@]/', '', trim($value)) ?? '';
    if ($normalized === '') {
      return '';
    }
    return substr($normalized, 0, $max_length);
  }

  private function normalizeNonNegativeInt(mixed $value): int {
    if (is_int($value)) {
      return max(0, $value);
    }
    if (is_string($value) && ctype_digit($value)) {
      return max(0, (int) $value);
    }
    if (is_numeric($value)) {
      return max(0, (int) $value);
    }
    return 0;
  }

  private function normalizeReplayWindow(mixed $value): int {
    if ($value === NULL || $value === '') {
      return 0;
    }
    if (is_int($value)) {
      return max(0, $value);
    }
    $s = strtolower(trim((string) $value));
    if ($s === 'open' || $s === 'unbounded') {
      return 86400 * 365;
    }
    if (ctype_digit($s)) {
      return max(0, (int) $s);
    }
    return 0;
  }

  private function normalizeReconciliationStrategy(string $raw): string {
    $t = strtolower(trim($raw));
    return match ($t) {
      'prefer_device', 'prefer_local_buffer' => 'prefer_device_buffer',
      'prefer_server', 'authoritative_server', 'prefer_authoritative_ledger' => 'prefer_authoritative_ledger',
      'operator_review', 'manual_review' => 'operator_review',
      'strict_first', 'strict' => 'strict_first',
      default => 'prefer_authoritative_ledger',
    };
  }

  private function normalizeConflictStrategy(string $raw): string {
    $t = strtolower(trim($raw));
    return match ($t) {
      'merge_counters', 'merge' => 'merge_counters',
      'defer', 'defer_to_scanner' => 'defer_to_venue_policy',
      'reject', 'reject_duplicate' => 'reject_duplicate',
      'allow_idempotent', 'idempotent' => 'allow_idempotent',
      default => 'defer_to_venue_policy',
    };
  }

  private function normalizeSyncHint(string $raw): string {
    $t = strtolower(trim($raw));
    return match ($t) {
      'batch', 'batch_upload', 'queued' => 'batch_upload',
      'timebox', 'timeboxed', 'timeboxed_replay' => 'timeboxed_replay',
      'none', '', '0' => 'none',
      default => 'none',
    };
  }

  private function normalizeContinuityMode(string $raw): string {
    $t = strtolower(trim($raw));
    return match ($t) {
      'offline', 'offline_staging' => 'offline_staging',
      'offline_replay', 'replay' => 'offline_replay',
      'recovery', 'recovery_replay' => 'recovery',
      'online', 'live' => 'live_operations',
      default => 'live_operations',
    };
  }

  private function normalizeRecoveryScope(string $raw): string {
    $t = strtolower(trim($raw));
    return match ($t) {
      'event', 'event_session' => 'event_session',
      'group', 'reconciliation_group' => 'reconciliation_group',
      'ticket', 'ticket_row', '' => 'ticket_row',
      default => 'ticket_row',
    };
  }

  private function mapSyncHintToPublicHint(string $sync_hint): string {
    return match ($sync_hint) {
      'batch_upload' => 'deferred_batch',
      'timeboxed_replay' => 'time_limited_replay',
      default => 'standard',
    };
  }

}
