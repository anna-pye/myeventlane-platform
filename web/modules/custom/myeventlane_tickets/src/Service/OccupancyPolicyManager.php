<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\myeventlane_tickets\Entity\Ticket;

/**
 * Canonical metadata-only occupancy, anti-passback, and directional scan policy.
 *
 * Composes timing, session, zone topology, continuity, and device identity
 * context without minting alternate entitlement truth, scanner result tokens,
 * or local occupancy ledgers. Ticket rows and redemption logs remain
 * authoritative for fulfilment state.
 */
final class OccupancyPolicyManager {

  /**
   * Supported metadata_json container keys (canonical first).
   */
  public const METADATA_OCCUPANCY_KEYS = [
    'mel_operational_occupancy',
    'operational_occupancy',
  ];

  /**
   * @var list<string>
   */
  private const OCCUPANCY_SCALAR_KEYS = [
    'occupancy_mode',
    'anti_passback_mode',
    'reentry_policy',
    'directional_mode',
    'entry_zone',
    'exit_zone',
    'occupancy_scope',
    'occupancy_group',
    'balancing_strategy',
  ];

  public function __construct(
    private readonly OperationalContinuityPolicyManager $operationalContinuityPolicyManager,
    private readonly DeviceOperationIdentityManager $deviceOperationIdentityManager,
    private readonly ZoneAccessPolicyManager $zoneAccessPolicyManager,
    private readonly SessionEntitlementPolicyManager $sessionEntitlementPolicyManager,
    private readonly TimedEntryPolicyManager $timedEntryPolicyManager,
    private readonly EntitlementCapabilityRegistry $entitlementCapabilityRegistry,
    private readonly TicketCapabilityManager $ticketCapabilityManager,
    private readonly TimeInterface $time,
  ) {}

  /**
   * TRUE when ticket metadata declares an occupancy container or scalars.
   */
  public function ticketDeclaresOccupancyMetadata(Ticket $ticket): bool {
    return $this->mergeOccupancyFromTicket($ticket) !== [];
  }

  /**
   * Merges occupancy blobs from layered sources; later layers override earlier.
   *
   * @param array<string, mixed> ...$layers
   *
   * @return array<string, mixed>
   */
  public function mergeOccupancyLayers(array ...$layers): array {
    $merged = [];
    foreach ($layers as $layer) {
      if ($layer === []) {
        continue;
      }
      $blob = $this->extractOccupancyBlobFromLayer($layer);
      $merged = array_merge($merged, $blob);
    }
    return $merged;
  }

  /**
   * Merges ticket occupancy metadata with optional extra layers.
   *
   * @param array<string, mixed> ...$layers
   *
   * @return array<string, mixed>
   */
  public function mergeOccupancyFromTicket(Ticket $ticket, array ...$layers): array {
    $base = $this->readTicketOccupancyRaw($ticket);
    return $this->mergeOccupancyLayers($base, ...$layers);
  }

  /**
   * Normalizes occupancy + anti-passback + directional + balancing metadata.
   *
   * @param array<string, mixed> $raw
   *   Raw map (may include nested mel_operational_occupancy blobs).
   *
   * @return array<string, mixed>
   */
  public function normalizeOccupancyPolicies(array $raw): array {
    $merged = $this->mergeOccupancyLayers($raw);
    $window = $this->normalizeOccupancyWindow($merged['occupancy_window'] ?? NULL);
    $cap = $this->normalizeOccupancyCap($merged['occupancy_cap'] ?? NULL);

    return [
      'occupancy_mode' => $this->normalizeOccupancyMode((string) ($merged['occupancy_mode'] ?? '')),
      'anti_passback_mode' => $this->normalizeAntiPassbackMode((string) ($merged['anti_passback_mode'] ?? '')),
      'reentry_policy' => $this->normalizeReentryPolicy((string) ($merged['reentry_policy'] ?? '')),
      'directional_mode' => $this->normalizeDirectionalMode((string) ($merged['directional_mode'] ?? '')),
      'entry_zone' => $this->normalizeZoneToken($merged['entry_zone'] ?? ''),
      'exit_zone' => $this->normalizeZoneToken($merged['exit_zone'] ?? ''),
      'occupancy_scope' => $this->normalizeOccupancyScope((string) ($merged['occupancy_scope'] ?? '')),
      'occupancy_group' => $this->normalizeTokenScalar((string) ($merged['occupancy_group'] ?? ''), 128),
      'balancing_strategy' => $this->normalizeBalancingStrategy((string) ($merged['balancing_strategy'] ?? '')),
      'occupancy_cap' => $cap,
      'occupancy_window' => $window,
    ];
  }

  /**
   * Customer-visible occupancy hints (no topology ids, caps, or internals).
   *
   * @return array<string, string>
   */
  public function buildCustomerSafeOccupancySummary(Ticket $ticket): array {
    if (!$this->ticketDeclaresOccupancyMetadata($ticket)) {
      return [];
    }
    $norm = $this->normalizeOccupancyPolicies($this->mergeOccupancyFromTicket($ticket));
    if ($this->isCustomerOccupancyBaseline($norm)) {
      return [];
    }

    return [
      'occupancy_mode' => (string) $norm['occupancy_mode'],
      'reentry_policy' => (string) $norm['reentry_policy'],
      'directional_mode' => (string) $norm['directional_mode'],
    ];
  }

  /**
   * Staff-side occupancy diagnostics (machine-oriented, not customer-facing).
   *
   * @param array<string, mixed> $merged_operational_context
   *
   * @return array<string, mixed>
   */
  public function buildStaffOccupancyDiagnostics(Ticket $ticket, array $merged_operational_context = []): array {
    $norm = $this->normalizeOccupancyPolicies(
      $this->mergeOccupancyFromTicket($ticket, $merged_operational_context),
    );
    $now_ts = $this->time->getCurrentTime();
    $timed = $this->timedEntryPolicyManager->evaluate($ticket, $now_ts, NULL);
    $session = $this->sessionEntitlementPolicyManager->buildNormalizedPayload($ticket, $now_ts, NULL, $timed);
    $topology = $this->zoneAccessPolicyManager->buildTopologyDescriptor($ticket, $timed, $session);
    $zone_summary = $this->zoneAccessPolicyManager->summarizeZoneInspection($ticket);
    $merged_cont = $this->operationalContinuityPolicyManager->mergeContinuityFromTicket($ticket, $merged_operational_context);
    $reconciliation = $this->operationalContinuityPolicyManager->normalizeReconciliationMetadata($merged_cont);
    $continuity_desc = $this->operationalContinuityPolicyManager->buildContinuityDescriptor($ticket, 'online');
    $continuity_desc['reconciliation'] = $reconciliation;
    $topology_id = (string) ($topology['topology_id'] ?? '');
    $fingerprints = $this->operationalContinuityPolicyManager->buildDeterministicReconciliationFingerprints(
      (int) $ticket->id(),
      $reconciliation,
      $topology_id,
      '',
    );

    return [
      'normalized_occupancy' => $norm,
      'anti_passback_descriptor' => $this->buildAntiPassbackDescriptor($norm, $continuity_desc),
      'directional_scan_descriptor' => [
        'directional_mode' => $norm['directional_mode'],
        'entry_zone' => $norm['entry_zone'],
        'exit_zone' => $norm['exit_zone'],
      ],
      'balancing_descriptor' => $this->buildBalancingDescriptor($norm),
      'occupancy_continuity_summary' => [
        'continuity_descriptor_token' => (string) ($fingerprints['continuity_descriptor_token'] ?? ''),
        'continuity_mode' => (string) ($reconciliation['continuity_mode'] ?? ''),
        'sync_hint' => (string) ($reconciliation['sync_hint'] ?? 'none'),
      ],
      'topology_plus_occupancy_composition' => [
        'topology_id' => $topology_id,
        'zone_reentry_semantics' => (string) ($zone_summary['reentry_semantics'] ?? ''),
        'occupancy_scope' => (string) $norm['occupancy_scope'],
        'occupancy_group' => (string) $norm['occupancy_group'],
      ],
      'timing_plus_occupancy_composition' => [
        'timed_scanner_state' => (string) (($timed['scanner'] ?? [])['state'] ?? ''),
        'timed_allowed_now' => (bool) (($timed['scanner'] ?? [])['allowed_now'] ?? FALSE),
        'occupancy_mode' => (string) $norm['occupancy_mode'],
      ],
      'session_plus_occupancy_composition' => [
        'session_scanner_allowed' => (bool) (($session['scanner'] ?? [])['allowed_now'] ?? FALSE),
        'session_reentry_allowed' => (bool) (($session['redemption'] ?? [])['reentry_allowed'] ?? FALSE),
        'reentry_policy' => (string) $norm['reentry_policy'],
      ],
    ];
  }

  /**
   * Deterministic occupancy descriptor for orchestration and observability.
   *
   * @param array<string, mixed>|null $policy_snapshot
   *   Optional timed/session/zone composition from an evaluated scan gate.
   *
   * @return array<string, mixed>
   */
  public function buildOccupancyDescriptor(Ticket $ticket, string $mode = 'online', ?array $policy_snapshot = NULL): array {
    $policy_snapshot = $policy_snapshot ?? [];
    $norm = $this->normalizeOccupancyPolicies($this->mergeOccupancyFromTicket($ticket));
    $now = $this->time->getCurrentTime();
    $timed = is_array($policy_snapshot['timed_entry'] ?? NULL)
      ? $policy_snapshot['timed_entry']
      : $this->timedEntryPolicyManager->evaluate($ticket, $now, NULL);
    $session = is_array($policy_snapshot['session_entitlement'] ?? NULL)
      ? $policy_snapshot['session_entitlement']
      : $this->sessionEntitlementPolicyManager->buildNormalizedPayload($ticket, $now, NULL, $timed);
    $zone_access = is_array($policy_snapshot['zone_access'] ?? NULL) ? $policy_snapshot['zone_access'] : [];
    $topology = is_array($zone_access['topology'] ?? NULL)
      ? $zone_access['topology']
      : $this->zoneAccessPolicyManager->buildTopologyDescriptor($ticket, $timed, $session);
    $continuity = $this->operationalContinuityPolicyManager->buildContinuityDescriptor($ticket, $mode);
    $type = $this->ticketCapabilityManager->getEntitlementType($ticket);
    $map = $this->entitlementCapabilityRegistry->getCapabilityMap($type);

    return [
      'version' => 1,
      'normalized' => $norm,
      'entitlement_type' => $type,
      'scanner_mode_ref' => (string) ($map['scanner_mode'] ?? ''),
      'multi_use' => (bool) ($map['multi_use'] ?? FALSE),
      'topology_ref' => [
        'topology_id' => (string) ($topology['topology_id'] ?? ''),
      ],
      'timed_entry_ref' => [
        'state' => (string) (($timed['scanner'] ?? [])['state'] ?? ''),
        'allowed_now' => (bool) (($timed['scanner'] ?? [])['allowed_now'] ?? FALSE),
      ],
      'session_entitlement_ref' => [
        'allowed_now' => (bool) (($session['scanner'] ?? [])['allowed_now'] ?? FALSE),
        'reentry_allowed' => (bool) (($session['redemption'] ?? [])['reentry_allowed'] ?? FALSE),
      ],
      'balancing_descriptor' => $this->buildBalancingDescriptor($norm),
      'anti_passback_descriptor' => $this->buildAntiPassbackDescriptor($norm, $continuity),
      'continuity_projection' => [
        'offline_eligible' => (bool) ($continuity['offline_eligible'] ?? FALSE),
        'continuity_mode' => (string) (($continuity['reconciliation'] ?? [])['continuity_mode'] ?? ''),
      ],
    ];
  }

  /**
   * Evaluates directional occupancy policy for a composed scan gate.
   *
   * Does not replace entitlement, timing, session, or zone decisions; it only
   * applies metadata directional constraints when an effective zone is known.
   *
   * @param array<string, mixed> $raw_operational_context
   *   Merged operational context (ticket metadata + request), same as passed to
   *   DeviceOperationIdentityManager for identity normalization.
   * @param array<string, mixed> $scan_gate_policy
   *   Policy snapshot from VenueOperationPolicyManager scan evaluation.
   *
   * @return array<string, mixed>
   *   Keys: allow, result_token, message, policy.
   */
  public function evaluateOccupancyForScan(
    Ticket $ticket,
    int $now,
    ?int $parsed_qr_expires_at,
    array $raw_operational_context,
    ?string $effective_zone_id,
    array $scan_gate_policy,
  ): array {
    $norm = $this->normalizeOccupancyPolicies(
      $this->mergeOccupancyFromTicket($ticket, $raw_operational_context),
    );
    $replay = $this->operationalContinuityPolicyManager->normalizeReplayContinuitySemantics(
      $ticket,
      $now,
      $parsed_qr_expires_at !== NULL && $parsed_qr_expires_at > 0 ? $parsed_qr_expires_at : NULL,
      $scan_gate_policy,
    );
    $continuity_for_anti = $this->operationalContinuityPolicyManager->buildContinuityDescriptor($ticket, 'online');
    $continuity_for_anti['replay_continuity'] = $replay;
    $continuity_for_anti['reconciliation'] = $this->operationalContinuityPolicyManager->normalizeReconciliationMetadata(
      $this->operationalContinuityPolicyManager->mergeContinuityFromTicket($ticket, $raw_operational_context),
    );
    $timed = is_array($scan_gate_policy['timed_entry'] ?? NULL)
      ? $scan_gate_policy['timed_entry']
      : $this->timedEntryPolicyManager->evaluate($ticket, $now, $parsed_qr_expires_at);
    $session = is_array($scan_gate_policy['session_entitlement'] ?? NULL)
      ? $scan_gate_policy['session_entitlement']
      : $this->sessionEntitlementPolicyManager->buildNormalizedPayload($ticket, $now, $parsed_qr_expires_at, $timed);
    $zone_access = is_array($scan_gate_policy['zone_access'] ?? NULL) ? $scan_gate_policy['zone_access'] : [];
    $topology = is_array($zone_access['topology'] ?? NULL)
      ? $zone_access['topology']
      : $this->zoneAccessPolicyManager->buildTopologyDescriptor($ticket, $timed, $session);
    $zone_summary = $this->zoneAccessPolicyManager->summarizeZoneInspection($ticket);

    $effective = $effective_zone_id !== NULL && trim($effective_zone_id) !== '' ? trim($effective_zone_id) : '';
    $directional = $this->evaluateDirectionalSemantics($norm, $effective);
    $anti = $this->buildAntiPassbackDescriptor($norm, $continuity_for_anti);

    $policy = [
      'normalized' => $norm,
      'directional_scan_descriptor' => $directional['descriptor'] + [
        'effective_zone' => $effective,
      ],
      'anti_passback_descriptor' => $anti,
      'balancing_descriptor' => $this->buildBalancingDescriptor($norm),
      'occupancy_continuity_summary' => [
        'replay_alignment' => $replay['policy_replay_alignment'] ?? [],
        'topology_id' => (string) ($topology['topology_id'] ?? ''),
        'zone_reentry_semantics' => (string) ($zone_summary['reentry_semantics'] ?? ''),
      ],
      'timing_composition_ref' => [
        'timed_state' => (string) (($timed['scanner'] ?? [])['state'] ?? ''),
      ],
      'session_composition_ref' => [
        'session_allowed' => (bool) (($session['scanner'] ?? [])['allowed_now'] ?? FALSE),
      ],
    ];

    if (!$directional['allow']) {
      return [
        'allow' => FALSE,
        'result_token' => 'invalid',
        'message' => 'Directional scan policy not satisfied.',
        'policy' => $policy,
      ];
    }

    return [
      'allow' => TRUE,
      'result_token' => '',
      'message' => '',
      'policy' => $policy,
    ];
  }

  /**
   * @param array<string, mixed> $norm
   * @param array<string, mixed> $continuity_desc
   *
   * @return array<string, mixed>
   */
  private function buildAntiPassbackDescriptor(array $norm, array $continuity_desc): array {
    $replay = is_array($continuity_desc['replay_continuity'] ?? NULL) ? $continuity_desc['replay_continuity'] : [];
    $reconciliation = is_array($continuity_desc['reconciliation'] ?? NULL)
      ? $continuity_desc['reconciliation']
      : [];

    return [
      'mode' => (string) $norm['anti_passback_mode'],
      'replay_continuity_ref' => [
        'timed_entry_allowed_now' => (bool) (($replay['policy_replay_alignment'] ?? [])['timed_entry_allowed_now'] ?? FALSE),
        'session_allowed_now' => (bool) (($replay['policy_replay_alignment'] ?? [])['session_allowed_now'] ?? FALSE),
      ],
      'reconciliation_strategy' => (string) ($reconciliation['reconciliation_strategy'] ?? ''),
    ];
  }

  /**
   * @param array<string, mixed> $norm
   *
   * @return array<string, mixed>
   */
  private function buildBalancingDescriptor(array $norm): array {
    return [
      'balancing_strategy' => (string) $norm['balancing_strategy'],
      'occupancy_cap' => $norm['occupancy_cap'],
      'occupancy_window' => $norm['occupancy_window'],
      'occupancy_scope' => (string) $norm['occupancy_scope'],
      'occupancy_group' => (string) $norm['occupancy_group'],
    ];
  }

  /**
   * @param array<string, mixed> $norm
   *
   * @return array{allow: bool, descriptor: array<string, mixed>}
   */
  private function evaluateDirectionalSemantics(array $norm, string $effective_zone): array {
    $mode = (string) $norm['directional_mode'];
    $entry = (string) $norm['entry_zone'];
    $exit = (string) $norm['exit_zone'];
    $descriptor = [
      'directional_mode' => $mode,
      'entry_zone' => $entry,
      'exit_zone' => $exit,
    ];

    if ($mode === 'none' || ($entry === '' && $exit === '')) {
      return ['allow' => TRUE, 'descriptor' => $descriptor];
    }

    if ($effective_zone === '') {
      // Cannot evaluate directional binding without a zone request — fail open
      // on occupancy metadata alone; zone/topology gates still apply elsewhere.
      return ['allow' => TRUE, 'descriptor' => $descriptor + ['binding' => 'unbound']];
    }

    $match_entry = $entry !== '' && strcasecmp($entry, $effective_zone) === 0;
    $match_exit = $exit !== '' && strcasecmp($exit, $effective_zone) === 0;

    return match ($mode) {
      'entry' => [
        'allow' => $entry === '' || $match_entry,
        'descriptor' => $descriptor + ['binding' => $match_entry ? 'entry_match' : 'entry_mismatch'],
      ],
      'exit' => [
        'allow' => $exit === '' || $match_exit,
        'descriptor' => $descriptor + ['binding' => $match_exit ? 'exit_match' : 'exit_mismatch'],
      ],
      'bidirectional' => [
        'allow' => ($entry === '' && $exit === '')
          || $match_entry
          || $match_exit,
        'descriptor' => $descriptor + [
          'binding' => ($match_entry || $match_exit) ? 'directional_match' : 'directional_mismatch',
        ],
      ],
      default => ['allow' => TRUE, 'descriptor' => $descriptor + ['binding' => 'neutral']],
    };
  }

  /**
   * @param array<string, mixed> $norm
   */
  private function isCustomerOccupancyBaseline(array $norm): bool {
    return ($norm['occupancy_mode'] ?? '') === 'inherit_venue'
      && ($norm['anti_passback_mode'] ?? '') === 'none'
      && ($norm['reentry_policy'] ?? '') === 'inherit'
      && ($norm['directional_mode'] ?? '') === 'none'
      && ($norm['balancing_strategy'] ?? '') === 'none'
      && ($norm['occupancy_scope'] ?? '') === 'ticket'
      && ($norm['occupancy_group'] ?? '') === ''
      && ($norm['occupancy_cap'] ?? NULL) === NULL
      && (($norm['occupancy_window']['duration_seconds'] ?? 0) === 0);
  }

  /**
   * @param array<string, mixed> $layer
   *
   * @return array<string, mixed>
   */
  private function extractOccupancyBlobFromLayer(array $layer): array {
    $blob = [];
    foreach (self::METADATA_OCCUPANCY_KEYS as $key) {
      if (!empty($layer[$key]) && is_array($layer[$key])) {
        $blob = array_merge($blob, $layer[$key]);
      }
    }
    foreach (self::OCCUPANCY_SCALAR_KEYS as $key) {
      if (!array_key_exists($key, $layer)) {
        continue;
      }
      $value = $layer[$key];
      if ($value === NULL || $value === '') {
        continue;
      }
      $blob[$key] = $value;
    }
    if (array_key_exists('occupancy_cap', $layer)) {
      $blob['occupancy_cap'] = $layer['occupancy_cap'];
    }
    if (array_key_exists('occupancy_window', $layer)) {
      $blob['occupancy_window'] = $layer['occupancy_window'];
    }
    return $blob;
  }

  /**
   * @return array<string, mixed>
   */
  private function readTicketOccupancyRaw(Ticket $ticket): array {
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
    return $this->extractOccupancyBlobFromLayer($map);
  }

  private function normalizeOccupancyMode(string $raw): string {
    return match (strtolower(trim($raw))) {
      'live', 'live_estimate', 'estimate' => 'live_estimate',
      'ledger', 'authoritative_ledger_hint' => 'authoritative_ledger_hint',
      'policy', 'policy_only' => 'policy_only',
      default => 'inherit_venue',
    };
  }

  private function normalizeAntiPassbackMode(string $raw): string {
    return match (strtolower(trim($raw))) {
      'soft', 'lenient' => 'soft',
      'strict', 'hard' => 'strict',
      'session', 'session_bound' => 'session_bound',
      'zone', 'zone_bound' => 'zone_bound',
      'none', '', '0' => 'none',
      default => 'none',
    };
  }

  private function normalizeReentryPolicy(string $raw): string {
    return match (strtolower(trim($raw))) {
      'allow', 'allowed' => 'allow',
      'deny', 'disallowed' => 'deny',
      'session', 'session_governed' => 'session_governed',
      'zone', 'zone_governed' => 'zone_governed',
      default => 'inherit',
    };
  }

  private function normalizeDirectionalMode(string $raw): string {
    return match (strtolower(trim($raw))) {
      'entry', 'ingress', 'in' => 'entry',
      'exit', 'egress', 'out' => 'exit',
      'both', 'bidirectional', 'inout' => 'bidirectional',
      default => 'none',
    };
  }

  private function normalizeOccupancyScope(string $raw): string {
    return match (strtolower(trim($raw))) {
      'zone', 'zone_group' => 'zone_group',
      'venue', 'venue_session' => 'venue_session',
      'event' => 'event',
      default => 'ticket',
    };
  }

  private function normalizeBalancingStrategy(string $raw): string {
    return match (strtolower(trim($raw))) {
      'entry_exit', 'entry_exit_balance' => 'entry_exit_balance',
      'one_in_one_out', 'fifo_pair' => 'one_in_one_out',
      'implicit_session', 'session_implicit' => 'implicit_session',
      default => 'none',
    };
  }

  /**
   * @return array<string, int>
   */
  private function normalizeOccupancyWindow(mixed $raw): array {
    if (!is_array($raw)) {
      return ['start_offset' => 0, 'duration_seconds' => 0];
    }
    $start = $raw['start_offset'] ?? $raw['start'] ?? 0;
    $duration = $raw['duration_seconds'] ?? $raw['duration'] ?? $raw['window_seconds'] ?? 0;
    $start_i = is_numeric($start) ? max(0, (int) $start) : 0;
    $dur_i = is_numeric($duration) ? max(0, (int) $duration) : 0;
    return [
      'start_offset' => $start_i,
      'duration_seconds' => $dur_i,
    ];
  }

  private function normalizeOccupancyCap(mixed $raw): ?int {
    if ($raw === NULL || $raw === '') {
      return NULL;
    }
    if (is_numeric($raw)) {
      $v = (int) $raw;
      return $v > 0 ? $v : NULL;
    }
    return NULL;
  }

  private function normalizeZoneToken(mixed $raw): string {
    return $this->normalizeTokenScalar(is_string($raw) ? $raw : (string) $raw, 128);
  }

  private function normalizeTokenScalar(string $value, int $max_length): string {
    $normalized = preg_replace('/[^a-zA-Z0-9\-_.:@]/', '', trim($value)) ?? '';
    if ($normalized === '') {
      return '';
    }
    return substr($normalized, 0, $max_length);
  }

}
