<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\myeventlane_tickets\Entity\Ticket;

/**
 * Canonical operational zone / access topology policy for ticket metadata.
 *
 * Normalizes `mel_operational_zones` / `operational_zones` containers only.
 * Composes with timed-entry and session snapshots passed inward from venue
 * orchestration — it does not duplicate clock math, sequencing math, or
 * entitlement capability maps.
 */
final class ZoneAccessPolicyManager {

  public function __construct(
    private readonly EntitlementCapabilityRegistry $entitlementCapabilityRegistry,
    private readonly TicketCapabilityManager $ticketCapabilityManager,
    private readonly TimedEntryPolicyManager $timedEntryPolicyManager,
    private readonly SessionEntitlementPolicyManager $sessionEntitlementPolicyManager,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Normalizes zone metadata from ticket metadata_json (machine-only).
   *
   * @return array<string, mixed>
   */
  public function normalizeOperationalZonesMetadata(Ticket $ticket): array {
    return $this->normalizeZonesFromTicket($ticket);
  }

  /**
   * Builds a compact topology descriptor for audits and read-only surfaces.
   *
   * @param array<string, mixed> $timed_entry_policy
   *   Shape from TimedEntryPolicyManager::evaluate().
   * @param array<string, mixed> $session_entitlement_policy
   *   Shape from SessionEntitlementPolicyManager::buildNormalizedPayload().
   *
   * @return array<string, mixed>
   */
  public function buildTopologyDescriptor(
    Ticket $ticket,
    array $timed_entry_policy,
    array $session_entitlement_policy,
  ): array {
    $zones = $this->normalizeZonesFromTicket($ticket);
    $type = $this->ticketCapabilityManager->getEntitlementType($ticket);

    return [
      'topology_id' => $this->topologyFingerprint($ticket, $zones),
      'entitlement_type' => $type,
      'gate_family' => $this->registryGateFamilyToken($type),
      'zones' => $zones['zones'],
      'allowed_zones' => $zones['allowed_zones'],
      'denied_zones' => $zones['denied_zones'],
      'progression_order' => $zones['progression_order'],
      'gate_groups' => $zones['gate_groups'],
      'topology_hints' => $zones['topology_hints'],
      'reentry' => [
        'zone_default' => $zones['reentry_allowed_default'],
        'by_zone' => $zones['reentry_by_zone'],
        'session_reentry_allowed' => (bool) ($session_entitlement_policy['redemption']['reentry_allowed'] ?? FALSE),
      ],
      'progression' => [
        'session_state' => (string) ($session_entitlement_policy['progression']['current_state'] ?? ''),
        'session_zone_key' => $session_entitlement_policy['session']['zone_key'] ?? NULL,
        'timed_session_key' => $timed_entry_policy['session']['session_key'] ?? NULL,
        'timed_capacity_window' => $timed_entry_policy['session']['capacity_window'] ?? NULL,
      ],
      'has_active_restrictions' => (bool) ($zones['has_active_restrictions'] ?? FALSE),
    ];
  }

  /**
   * Evaluates zone legality for a scan using pre-composed timing/session slices.
   *
   * When $requested_zone_id is null or empty, zone rules that require a gate
   * identifier remain neutral (allow) so existing callers stay compatible.
   *
   * @param array<string, mixed> $timed_entry_policy
   * @param array<string, mixed> $session_entitlement_policy
   *
   * @return array<string, mixed>
   *   Keys: allow, result_token, message, policy (zone_access payload).
   */
  public function evaluateZoneAccessForComposition(
    Ticket $ticket,
    ?string $requested_zone_id,
    array $timed_entry_policy,
    array $session_entitlement_policy,
  ): array {
    $zones = $this->normalizeZonesFromTicket($ticket);
    $topology = $this->buildTopologyDescriptor($ticket, $timed_entry_policy, $session_entitlement_policy);

    $policy = [
      'normalized' => $zones,
      'topology' => $topology,
      'scanner' => [
        'allowed_now' => TRUE,
        'state' => 'allowed',
        'reason' => 'zone_clear',
      ],
      'coexistence' => [
        'timed_session_key' => $timed_entry_policy['session']['session_key'] ?? NULL,
        'timed_capacity_window' => $timed_entry_policy['session']['capacity_window'] ?? NULL,
        'session_zone_key' => $session_entitlement_policy['session']['zone_key'] ?? NULL,
        'session_progression_state' => $session_entitlement_policy['progression']['current_state'] ?? NULL,
      ],
    ];

    if (empty($zones['has_active_restrictions'])) {
      $policy['scanner'] = [
        'allowed_now' => TRUE,
        'state' => 'legacy_neutral',
        'reason' => 'no_zone_metadata',
      ];
      return [
        'allow' => TRUE,
        'result_token' => '',
        'message' => '',
        'policy' => $policy,
      ];
    }

    $requested = $this->normalizeZoneId($requested_zone_id);
    if ($requested === NULL) {
      $policy['scanner'] = [
        'allowed_now' => TRUE,
        'state' => 'neutral_no_gate',
        'reason' => 'gate_zone_unspecified',
      ];
      return [
        'allow' => TRUE,
        'result_token' => '',
        'message' => '',
        'policy' => $policy,
      ];
    }

    if ($this->isDeniedZone($zones, $requested)) {
      $policy['scanner'] = $this->scannerDenySlice('zone_denied', FALSE);
      return [
        'allow' => FALSE,
        'result_token' => 'invalid',
        'message' => 'Ticket is not valid for this gate.',
        'policy' => $policy + ['denial' => $this->buildScannerSafeDenialSemantics('zone_denied', $requested, $zones)],
      ];
    }

    $allowed = $zones['allowed_zones'];
    if ($allowed !== [] && !in_array($requested, $allowed, TRUE)) {
      $policy['scanner'] = $this->scannerDenySlice('zone_not_allowed', FALSE);
      return [
        'allow' => FALSE,
        'result_token' => 'invalid',
        'message' => 'Ticket is not valid for this gate.',
        'policy' => $policy + ['denial' => $this->buildScannerSafeDenialSemantics('zone_not_allowed', $requested, $zones)],
      ];
    }

    $progression = $zones['progression_order'];
    if ($progression !== []) {
      $prog_result = $this->evaluateProgression($ticket, $zones, $requested);
      if (!$prog_result['allow']) {
        $policy['scanner'] = $this->scannerDenySlice((string) $prog_result['reason'], FALSE);
        return [
          'allow' => FALSE,
          'result_token' => (string) $prog_result['result_token'],
          'message' => (string) $prog_result['message'],
          'policy' => $policy + ['denial' => $this->buildScannerSafeDenialSemantics((string) $prog_result['reason'], $requested, $zones)],
        ];
      }
    }

    $policy['scanner'] = [
      'allowed_now' => TRUE,
      'state' => 'allowed',
      'reason' => 'zone_clear',
    ];

    return [
      'allow' => TRUE,
      'result_token' => '',
      'message' => '',
      'policy' => $policy,
    ];
  }

  /**
   * Scanner-safe denial semantics (no replay material, no HMAC).
   *
   * @return array<string, mixed>
   */
  public function buildScannerSafeDenialSemantics(string $reason, string $requested_zone_id, array $normalized_zones): array {
    return [
      'reason' => $reason,
      'requested_zone' => $requested_zone_id,
      'allowed_zones' => $normalized_zones['allowed_zones'],
      'denied_zones' => $normalized_zones['denied_zones'],
      'progression_order' => $normalized_zones['progression_order'],
    ];
  }

  /**
   * Machine-only conflict codes for observability (read-only callers).
   *
   * When $requested_zone_id is set, evaluates that gate against policy.
   * Structural issues (for example overlapping allow/deny lists) are always
   * included when present.
   *
   * @return list<string>
   */
  public function detectZoneTopologyConflicts(Ticket $ticket, ?string $requested_zone_id): array {
    $conflicts = $this->detectStructuralZoneConflicts($ticket);
    $requested = $this->normalizeZoneId($requested_zone_id);
    if ($requested === NULL) {
      return array_values(array_unique($conflicts));
    }
    $now = $this->time->getCurrentTime();
    $timed = $this->timedEntryPolicyManager->evaluate($ticket, $now, NULL);
    $session = $this->sessionEntitlementPolicyManager->buildNormalizedPayload($ticket, $now, NULL, $timed);
    $eval = $this->evaluateZoneAccessForComposition($ticket, $requested, $timed, $session);
    if (!$eval['allow']) {
      $conflicts[] = (string) ($eval['policy']['scanner']['reason'] ?? 'zone_operational_deny');
    }
    return array_values(array_unique($conflicts));
  }

  /**
   * Detects metadata inconsistencies without requiring a gate identifier.
   *
   * @return list<string>
   */
  public function detectStructuralZoneConflicts(Ticket $ticket): array {
    $zones = $this->normalizeZonesFromTicket($ticket);
    $conflicts = [];
    foreach ($zones['allowed_zones'] as $z) {
      if (in_array($z, $zones['denied_zones'], TRUE)) {
        $conflicts[] = 'zone_allowed_denied_overlap';
        break;
      }
    }
    return $conflicts;
  }

  /**
   * Read-only inspection summary for diagnostics (no replay material).
   *
   * @return array<string, mixed>
   */
  public function summarizeZoneInspection(Ticket $ticket): array {
    $now = $this->time->getCurrentTime();
    $timed = $this->timedEntryPolicyManager->evaluate($ticket, $now, NULL);
    $session = $this->sessionEntitlementPolicyManager->buildNormalizedPayload($ticket, $now, NULL, $timed);
    $topology = $this->buildTopologyDescriptor($ticket, $timed, $session);
    $norm = $this->normalizeOperationalZonesMetadata($ticket);
    $progression_order = $norm['progression_order'] ?? [];
    $progression_summary = $progression_order !== [] ? 'enforced' : 'none';
    $reentry_semantics = ($topology['reentry']['zone_default'] === TRUE) ? 'zone_default_true'
      : (($topology['reentry']['zone_default'] === FALSE) ? 'zone_default_false' : 'zone_default_inherit');
    if (($topology['reentry']['session_reentry_allowed'] ?? FALSE) === TRUE) {
      $reentry_semantics .= '|session_reentry_true';
    }

    return [
      'conflict_policy' => $this->describeConflictPolicyToken($ticket),
      'topology' => $topology,
      'structural_conflicts' => $this->detectStructuralZoneConflicts($ticket),
      'progression_summary' => $progression_summary,
      'reentry_semantics' => $reentry_semantics,
      'gate_policy' => [
        'allowed_zone_count' => count($norm['allowed_zones'] ?? []),
        'denied_zone_count' => count($norm['denied_zones'] ?? []),
        'gate_group_count' => count($norm['gate_groups'] ?? []),
      ],
    ];
  }

  /**
   * Describes how zone conflicts normalize for venue integrity scaffolding.
   */
  public function describeConflictPolicyToken(Ticket $ticket): string {
    $zones = $this->normalizeZonesFromTicket($ticket);
    if (empty($zones['has_active_restrictions'])) {
      return 'zone_unrestricted';
    }
    if ($zones['denied_zones'] !== []) {
      return 'zone_explicit_denials';
    }
    if ($zones['allowed_zones'] !== []) {
      return 'zone_allowed_list';
    }
    if ($zones['progression_order'] !== []) {
      return 'zone_progression_enforced';
    }
    return 'zone_topology_present';
  }

  /**
   * @return array<string, mixed>
   */
  private function evaluateProgression(Ticket $ticket, array $zones, string $requested): array {
    $order = $zones['progression_order'];
    $count = max(0, $ticket->getRedemptionCount());
    $n = count($order);
    if ($n < 1) {
      return ['allow' => TRUE, 'result_token' => '', 'message' => '', 'reason' => ''];
    }

    if ($count < $n) {
      $expected = (string) $order[$count];
      if (strcasecmp($expected, $requested) !== 0) {
        return [
          'allow' => FALSE,
          'result_token' => 'invalid',
          'message' => 'Operational sequence not satisfied.',
          'reason' => 'zone_progression_mismatch',
        ];
      }
      return ['allow' => TRUE, 'result_token' => '', 'message' => '', 'reason' => ''];
    }

    $tail = (string) $order[$n - 1];
    $tail_reentry = $this->zoneReentryAllowed($zones, $tail);
    if ($tail_reentry && strcasecmp($tail, $requested) === 0) {
      return ['allow' => TRUE, 'result_token' => '', 'message' => '', 'reason' => ''];
    }
    if (!$tail_reentry && strcasecmp($tail, $requested) === 0) {
      return [
        'allow' => FALSE,
        'result_token' => 'redemption_limit_reached',
        'message' => 'Redemption limit has already been reached.',
        'reason' => 'zone_progression_exhausted',
      ];
    }

    return [
      'allow' => FALSE,
      'result_token' => 'invalid',
      'message' => 'Operational sequence not satisfied.',
      'reason' => 'zone_progression_terminal_mismatch',
    ];
  }

  /**
   * @return array{allowed_now: bool, state: string, reason: string}
   */
  private function scannerDenySlice(string $reason, bool $allowed_now): array {
    return [
      'allowed_now' => $allowed_now,
      'state' => 'denied',
      'reason' => $reason,
    ];
  }

  /**
   * @param array<string, mixed> $zones
   */
  private function isDeniedZone(array $zones, string $requested): bool {
    foreach ($zones['denied_zones'] as $denied) {
      if (strcasecmp((string) $denied, $requested) === 0) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @param array<string, mixed> $zones
   */
  private function zoneReentryAllowed(array $zones, string $zone_id): bool {
    $by = $zones['reentry_by_zone'];
    foreach ($by as $z => $allowed) {
      if (strcasecmp((string) $z, $zone_id) === 0) {
        return (bool) $allowed;
      }
    }
    if ($zones['reentry_allowed_default'] !== NULL) {
      return (bool) $zones['reentry_allowed_default'];
    }
    return FALSE;
  }

  /**
   * @return array<string, mixed>
   */
  private function normalizeZonesFromTicket(Ticket $ticket): array {
    $meta = $this->readTicketMetadataMap($ticket);
    $container = $this->extractZonesContainer($meta);
    $source_key = $this->zonesSourceKey($meta);

    $zones_list = $this->normalizeZonesList($container);
    $allowed = $this->normalizeZoneIdList($container['allowed_zones'] ?? ($container['allowed'] ?? []));
    $denied = $this->normalizeZoneIdList($container['denied_zones'] ?? ($container['denied'] ?? []));
    $progression = $this->normalizeZoneIdList($container['progression_order'] ?? ($container['progression'] ?? []));
    $gate_groups = $this->normalizeGateGroups($container['gate_groups'] ?? ($container['gate_grouping'] ?? []));
    $hints = $this->normalizeHints($container['topology_hints'] ?? ($container['hints'] ?? []));

    $reentry_default = NULL;
    if (array_key_exists('reentry_allowed', $container)) {
      $reentry_default = (bool) $container['reentry_allowed'];
    }
    elseif (array_key_exists('re_entry_allowed', $container)) {
      $reentry_default = (bool) $container['re_entry_allowed'];
    }

    $reentry_by_zone = [];
    $raw_by = $container['reentry_by_zone'] ?? $container['reentry_zones'] ?? [];
    if (is_array($raw_by)) {
      foreach ($raw_by as $z => $flag) {
        $id = $this->normalizeZoneId((string) $z);
        if ($id !== NULL) {
          $reentry_by_zone[$id] = (bool) $flag;
        }
      }
    }

    $has_restrictions = $allowed !== []
      || $denied !== []
      || $progression !== []
      || $gate_groups !== []
      || $hints !== []
      || $zones_list !== [];

    return [
      'source' => $source_key,
      'zones' => $zones_list,
      'allowed_zones' => $allowed,
      'denied_zones' => $denied,
      'progression_order' => $progression,
      'gate_groups' => $gate_groups,
      'topology_hints' => $hints,
      'reentry_allowed_default' => $reentry_default,
      'reentry_by_zone' => $reentry_by_zone,
      'has_active_restrictions' => $has_restrictions,
    ];
  }

  /**
   * @param array<string, mixed> $zones
   */
  private function topologyFingerprint(Ticket $ticket, array $zones): string {
    $material = json_encode([
      'ticket' => (int) $ticket->id(),
      'allowed' => $zones['allowed_zones'],
      'denied' => $zones['denied_zones'],
      'progression' => $zones['progression_order'],
      'gates' => $zones['gate_groups'],
    ]) ?: '';

    return 'zt_' . substr(hash('sha256', $material), 0, 16);
  }

  private function registryGateFamilyToken(string $type): string {
    $map = $this->entitlementCapabilityRegistry->getCapabilityMap($type);
    return (string) ($map['scanner_mode'] ?? '');
  }

  /**
   * @param array<string, mixed> $meta
   *
   * @return array<string, mixed>
   */
  private function extractZonesContainer(array $meta): array {
    foreach (['mel_operational_zones', 'operational_zones'] as $key) {
      if (!empty($meta[$key]) && is_array($meta[$key])) {
        return $meta[$key];
      }
    }
    return [];
  }

  /**
   * @param array<string, mixed> $meta
   */
  private function zonesSourceKey(array $meta): ?string {
    foreach (['mel_operational_zones', 'operational_zones'] as $key) {
      if (!empty($meta[$key]) && is_array($meta[$key])) {
        return $key;
      }
    }
    return NULL;
  }

  /**
   * @param array<string, mixed> $container
   *
   * @return list<array{id: string, label: string|null}>
   */
  private function normalizeZonesList(array $container): array {
    $out = [];
    $zones = $container['zones'] ?? $container['zone_ids'] ?? NULL;
    if (is_array($zones)) {
      if ($this->isListArray($zones)) {
        foreach ($zones as $item) {
          if (is_string($item) || is_int($item)) {
            $id = $this->normalizeZoneId((string) $item);
            if ($id !== NULL) {
              $out[] = ['id' => $id, 'label' => NULL];
            }
          }
          elseif (is_array($item)) {
            $id = $this->normalizeZoneId((string) ($item['id'] ?? $item['zone_id'] ?? ''));
            $label = isset($item['label']) && is_scalar($item['label']) ? (string) $item['label'] : NULL;
            if ($id !== NULL) {
              $out[] = ['id' => $id, 'label' => $label !== '' ? $label : NULL];
            }
          }
        }
      }
      else {
        foreach ($zones as $id => $label) {
          $nid = $this->normalizeZoneId((string) $id);
          if ($nid === NULL) {
            continue;
          }
          $out[] = [
            'id' => $nid,
            'label' => is_scalar($label) ? (string) $label : NULL,
          ];
        }
      }
    }
    return $out;
  }

  /**
   * @return list<string>
   */
  private function normalizeZoneIdList(mixed $value): array {
    if (!is_array($value)) {
      return [];
    }
    $out = [];
    foreach ($value as $item) {
      if (is_scalar($item)) {
        $id = $this->normalizeZoneId((string) $item);
        if ($id !== NULL) {
          $out[] = $id;
        }
      }
    }
    return array_values(array_unique($out));
  }

  /**
   * @return array<string, mixed>
   */
  private function normalizeGateGroups(mixed $value): array {
    if (!is_array($value)) {
      return [];
    }
    $out = [];
    foreach ($value as $gate => $zones) {
      if (!is_string($gate) && !is_int($gate)) {
        continue;
      }
      $gate_id = $this->normalizeZoneId((string) $gate);
      if ($gate_id === NULL) {
        continue;
      }
      if (is_string($zones) || is_int($zones)) {
        $z = $this->normalizeZoneId((string) $zones);
        if ($z !== NULL) {
          $out[$gate_id] = $z;
        }
      }
      elseif (is_array($zones) && $this->isListArray($zones)) {
        $mapped = $this->normalizeZoneIdList($zones);
        if ($mapped !== []) {
          $out[$gate_id] = $mapped;
        }
      }
    }
    return $out;
  }

  /**
   * @return list<string>
   */
  private function normalizeHints(mixed $value): array {
    if (!is_array($value)) {
      return [];
    }
    $out = [];
    foreach ($value as $hint) {
      if (is_scalar($hint)) {
        $h = trim((string) $hint);
        if ($h !== '') {
          $out[] = $h;
        }
      }
    }
    return array_values(array_unique($out));
  }

  private function normalizeZoneId(?string $value): ?string {
    if ($value === NULL) {
      return NULL;
    }
    $t = trim($value);
    if ($t === '') {
      return NULL;
    }
    return strtolower($t);
  }

  /**
   * @param array<int|string, mixed> $arr
   */
  private function isListArray(array $arr): bool {
    $i = 0;
    foreach ($arr as $k => $_) {
      if ($k !== $i) {
        return FALSE;
      }
      $i++;
    }
    return TRUE;
  }

  /**
   * @return array<string, mixed>
   */
  private function readTicketMetadataMap(Ticket $ticket): array {
    if (!$ticket->hasField('metadata_json') || $ticket->get('metadata_json')->isEmpty()) {
      return [];
    }
    $value = $ticket->get('metadata_json')->first()?->getValue() ?? [];
    if (isset($value['value']) && is_array($value['value'])) {
      return $value['value'];
    }
    return is_array($value) ? $value : [];
  }

}
