<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\myeventlane_tickets\Entity\Ticket;

/**
 * Normalizes scanner / gate / checkpoint operational identity metadata.
 *
 * Devices are operational descriptors only; trust semantics are interpreted by
 * {@see VenueOperationPolicyManager}. This manager does not authenticate devices
 * or mint QR payloads.
 */
final class DeviceOperationIdentityManager {

  /**
   * Supported metadata_json container keys (canonical first).
   */
  public const METADATA_DEVICE_KEYS = [
    'mel_operational_device',
    'operational_device',
  ];

  /**
   * Supported scalar fields inside operational device blobs.
   */
  private const DEVICE_FIELD_KEYS = [
    'device_id',
    'gate_id',
    'checkpoint_id',
    'operator_id',
    'operator_role',
    'trust_level',
    'venue_id',
    'zone_id',
    'scan_mode',
    'offline_mode',
    'reconciliation_group',
  ];

  public function __construct(
    private readonly TimedEntryPolicyManager $timedEntryPolicyManager,
    private readonly SessionEntitlementPolicyManager $sessionEntitlementPolicyManager,
    private readonly ZoneAccessPolicyManager $zoneAccessPolicyManager,
    private readonly EntitlementCapabilityRegistry $entitlementCapabilityRegistry,
    private readonly TicketCapabilityManager $ticketCapabilityManager,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Merges layered operational device sources; later layers override earlier.
   *
   * @param array<string, mixed> ...$layers
   *   Raw context arrays (may include nested mel_operational_device blobs).
   *
   * @return array<string, mixed>
   *   Merged raw map (still un-normalized).
   */
  public function mergeOperationalContextLayers(array ...$layers): array {
    $merged = [];
    foreach ($layers as $layer) {
      if ($layer === []) {
        continue;
      }
      $blob = $this->extractNestedDeviceBlob($layer);
      $merged = array_merge($merged, $blob);
      foreach (self::DEVICE_FIELD_KEYS as $key) {
        if (!array_key_exists($key, $layer)) {
          continue;
        }
        $value = $layer[$key];
        if ($value === NULL || $value === '') {
          continue;
        }
        $merged[$key] = $value;
      }
    }
    return $merged;
  }

  /**
   * Merges ticket metadata_json operational device material with extra layers.
   *
   * @param array<string, mixed> ...$layers
   *
   * @return array<string, mixed>
   */
  public function mergeOperationalContextFromTicket(Ticket $ticket, array ...$layers): array {
    $base = $this->readTicketMetadataMap($ticket);
    return $this->mergeOperationalContextLayers($base, ...$layers);
  }

  /**
   * Produces the canonical normalized operational identity fields.
   *
   * @param array<string, mixed> $raw_context
   *   Merged raw context (see mergeOperationalContextLayers()).
   * @param string $fallback_device_id
   *   Legacy device string from scanner callers when metadata omits device_id.
   *
   * @return array<string, mixed>
   */
  public function normalizeOperationalIdentity(array $raw_context, string $fallback_device_id = ''): array {
    $blob = $this->mergeOperationalContextLayers($raw_context);
    $out = [];
    foreach (self::DEVICE_FIELD_KEYS as $key) {
      $out[$key] = match ($key) {
        'offline_mode' => $this->normalizeOfflineMode($blob[$key] ?? NULL),
        default => $this->normalizeScalarToken($blob[$key] ?? '', $key === 'device_id' ? 128 : 256),
      };
    }
    if ($out['device_id'] === '' && $fallback_device_id !== '') {
      $out['device_id'] = $this->normalizeScalarToken($fallback_device_id, 128);
    }
    if ($out['device_id'] === '') {
      $out['device_id'] = 'unknown-device';
    }
    return $out;
  }

  /**
   * Classifies a raw trust_level string into a canonical policy token.
   */
  public function classifyTrustLevel(string $raw): string {
    $t = strtolower(trim($raw));
    return match (TRUE) {
      $t === '' => 'unknown',
      in_array($t, ['trusted', 'privileged', 'elevated', 'high', 'staff'], TRUE) => 'elevated',
      in_array($t, ['limited', 'low', 'kiosk', 'restricted'], TRUE) => 'limited',
      in_array($t, ['standard', 'normal', 'default', 'medium'], TRUE) => 'standard',
      default => 'unknown',
    };
  }

  /**
   * Replay-safe fingerprint for duplicate / reconciliation correlation (staff).
   *
   * @param array<string, mixed> $normalized
   *   Output of normalizeOperationalIdentity().
   */
  public function buildReplaySafeDeviceFingerprint(array $normalized): string {
    $material = implode('|', [
      (string) ($normalized['device_id'] ?? ''),
      (string) ($normalized['gate_id'] ?? ''),
      (string) ($normalized['checkpoint_id'] ?? ''),
      (string) ($normalized['reconciliation_group'] ?? ''),
      (string) ($normalized['venue_id'] ?? ''),
    ]);
    return hash('sha256', $material);
  }

  /**
   * Customer- and log-summary-safe operational identity (no replay, no operator).
   *
   * @param array<string, mixed> $normalized
   *
   * @return array<string, mixed>
   */
  public function buildPublicOperationalIdentitySummary(array $normalized): array {
    $trust = $this->classifyTrustLevel((string) ($normalized['trust_level'] ?? ''));
    return [
      'trust_category' => $this->mapTrustToPublicCategory($trust),
      'scan_mode' => $this->normalizeScanModePublic((string) ($normalized['scan_mode'] ?? '')),
      'offline_capable' => (bool) ($normalized['offline_mode'] ?? FALSE),
      'checkpoint' => $this->sanitizePublicCheckpointLabel((string) ($normalized['checkpoint_id'] ?? '')),
    ];
  }

  /**
   * Staff-side integrity payload including operator attribution and fingerprint.
   *
   * @param array<string, mixed> $normalized
   * @param array<string, mixed> $policy_snapshot
   *   Timed + session + zone composition from VenueOperationPolicyManager scan gate.
   *
   * @return array<string, mixed>
   */
  public function buildStaffIntegrityIdentityPayload(
    Ticket $ticket,
    array $normalized,
    array $policy_snapshot,
    string $device_fingerprint,
  ): array {
    $timed = is_array($policy_snapshot['timed_entry'] ?? NULL) ? $policy_snapshot['timed_entry'] : [];
    $session = is_array($policy_snapshot['session_entitlement'] ?? NULL) ? $policy_snapshot['session_entitlement'] : [];
    $zone = is_array($policy_snapshot['zone_access'] ?? NULL) ? $policy_snapshot['zone_access'] : [];
    if ($timed === [] || $session === []) {
      $now = $this->time->getCurrentTime();
      if ($timed === []) {
        $timed = $this->timedEntryPolicyManager->evaluate($ticket, $now, NULL);
      }
      if ($session === []) {
        $session = $this->sessionEntitlementPolicyManager->buildNormalizedPayload($ticket, $now, NULL, $timed);
      }
    }
    $topology_id = '';
    if ($zone !== []) {
      $topology_id = (string) (($zone['topology'] ?? [])['topology_id'] ?? $zone['topology_id'] ?? '');
    }
    if ($topology_id === '' && ($timed !== [] && $session !== [])) {
      $topology = $this->zoneAccessPolicyManager->buildTopologyDescriptor($ticket, $timed, $session);
      $topology_id = (string) ($topology['topology_id'] ?? '');
    }
    $type = $this->ticketCapabilityManager->getEntitlementType($ticket);

    return [
      'device_fingerprint' => $device_fingerprint,
      'operator_id' => $this->normalizeScalarToken((string) ($normalized['operator_id'] ?? ''), 128),
      'operator_role' => $this->normalizeScalarToken((string) ($normalized['operator_role'] ?? ''), 128),
      'gate_id' => $this->normalizeScalarToken((string) ($normalized['gate_id'] ?? ''), 256),
      'checkpoint_id' => $this->normalizeScalarToken((string) ($normalized['checkpoint_id'] ?? ''), 256),
      'reconciliation_group' => $this->normalizeScalarToken((string) ($normalized['reconciliation_group'] ?? ''), 256),
      'zone_id' => $this->normalizeScalarToken((string) ($normalized['zone_id'] ?? ''), 256),
      'venue_id' => $this->normalizeScalarToken((string) ($normalized['venue_id'] ?? ''), 64),
      'entitlement_type' => $type,
      'entitlement_capability_ref' => (string) ($this->entitlementCapabilityRegistry->getCapabilityMap($type)['scanner_mode'] ?? ''),
      'policy_composition_ref' => [
        'timed_scanner_state' => (string) ($timed['scanner']['state'] ?? ''),
        'session_scanner_allowed' => (bool) ($session['scanner']['allowed_now'] ?? FALSE),
        'topology_id' => $topology_id,
      ],
    ];
  }

  /**
   * Full descriptor bundle for audit and orchestration callers.
   *
   * @param array<string, mixed> $normalized
   * @param array<string, mixed> $policy_snapshot
   *
   * @return array<string, mixed>
   */
  public function buildOperationalIdentityDescriptors(
    Ticket $ticket,
    array $normalized,
    array $policy_snapshot,
  ): array {
    $fingerprint = $this->buildReplaySafeDeviceFingerprint($normalized);
    return [
      'normalized' => $normalized,
      'public_summary' => $this->buildPublicOperationalIdentitySummary($normalized),
      'staff_integrity' => $this->buildStaffIntegrityIdentityPayload($ticket, $normalized, $policy_snapshot, $fingerprint),
    ];
  }

  /**
   * Customer-visible slice for universal ticket models (no topology / operator leakage).
   *
   * @return array<string, mixed>
   */
  public function buildCustomerVisibleOperationalIdentityFromTicket(Ticket $ticket): array {
    $map = $this->readTicketMetadataMap($ticket);
    if ($map === []) {
      return [];
    }
    $raw = $this->mergeOperationalContextLayers($map);
    if ($raw === []) {
      return [];
    }
    $normalized = $this->normalizeOperationalIdentity($raw, '');
    $public = $this->buildPublicOperationalIdentitySummary($normalized);
    $out = [];
    foreach (['trust_category', 'scan_mode', 'offline_capable', 'checkpoint'] as $k) {
      if (($public[$k] ?? '') !== '' && $public[$k] !== NULL) {
        $out[$k] = $public[$k];
      }
    }
    if ($out === []) {
      return [];
    }
    $out['operational_identity_version'] = 1;
    return $out;
  }

  /**
   * @param array<string, mixed> $layer
   *
   * @return array<string, mixed>
   */
  private function extractNestedDeviceBlob(array $layer): array {
    $blob = [];
    foreach (self::METADATA_DEVICE_KEYS as $key) {
      if (!empty($layer[$key]) && is_array($layer[$key])) {
        $blob = array_merge($blob, $layer[$key]);
      }
    }
    return $blob;
  }

  private function normalizeScalarToken(string $value, int $max_length): string {
    $normalized = preg_replace('/[^a-zA-Z0-9\-_.:@]/', '', trim($value)) ?? '';
    if ($normalized === '') {
      return '';
    }
    return substr($normalized, 0, $max_length);
  }

  private function normalizeOfflineMode(mixed $value): bool {
    if (is_bool($value)) {
      return $value;
    }
    $s = strtolower(trim((string) $value));
    return in_array($s, ['1', 'true', 'yes', 'offline'], TRUE);
  }

  private function normalizeScanModePublic(string $mode): string {
    $m = strtolower(trim($mode));
    return match ($m) {
      'admit', 'redeem', 'collect', 'validate', 'verify' => $m,
      default => $m !== '' ? 'unspecified' : '',
    };
  }

  private function mapTrustToPublicCategory(string $canonical): string {
    return match ($canonical) {
      'elevated' => 'verified_partner',
      'limited' => 'restricted',
      'standard' => 'standard',
      default => 'default',
    };
  }

  private function sanitizePublicCheckpointLabel(string $checkpoint): string {
    $token = preg_replace('/[^a-zA-Z0-9\-_]/', '', $checkpoint) ?? '';
    return $token !== '' ? substr($token, 0, 64) : '';
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
