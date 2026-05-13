<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\myeventlane_tickets\Entity\RedemptionLog;
use Drupal\myeventlane_tickets\Entity\Ticket;

/**
 * Canonical operational session orchestration for ticket-backed entitlements.
 *
 * Machine-only payloads: no translated strings, no UI labels, no rendering.
 * Timing windows remain owned by TimedEntryPolicyManager; this layer composes
 * session, progression, multi-use, sequencing, and grouped redemption semantics.
 */
final class SessionEntitlementPolicyManager {

  public function __construct(
    private readonly EntitlementCapabilityRegistry $entitlementCapabilityRegistry,
    private readonly TicketCapabilityManager $ticketCapabilityManager,
  ) {}

  /**
   * Builds the normalized operational session payload for policy consumers.
   *
   * @param array<string, mixed> $timed_entry_policy
   *   Output shape from TimedEntryPolicyManager::evaluate().
   *
   * @return array<string, mixed>
   *   Canonical session + redemption + progression + scanner (session slice).
   */
  public function buildNormalizedPayload(
    Ticket $ticket,
    int $now,
    ?int $parsed_qr_expires_at,
    array $timed_entry_policy,
  ): array {
    unset($now, $parsed_qr_expires_at);

    $type = $this->ticketCapabilityManager->getEntitlementType($ticket);
    $map = $this->entitlementCapabilityRegistry->getCapabilityMap($type);
    $meta = $this->readTicketMetadataMap($ticket);
    $session_meta = $this->extractSessionContainer($meta);

    $session_key = $this->nullableString($session_meta['session_key'] ?? NULL);
    $timed_session_key = $this->nullableString($timed_entry_policy['session']['session_key'] ?? NULL);
    if ($session_key === NULL) {
      $session_key = $timed_session_key;
    }

    $session_type = $this->nullableString($session_meta['session_type'] ?? NULL);
    $bundle_key = $this->nullableString($session_meta['bundle_key'] ?? NULL);
    $zone_key = $this->nullableString($session_meta['zone_key'] ?? NULL);
    if ($zone_key === NULL) {
      $zone_key = $this->nullableString($timed_entry_policy['session']['capacity_window'] ?? NULL);
    }

    $registry_multi_use = (bool) $map['multi_use'];
    $max_uses = $ticket->getRedemptionLimit();
    $remaining_uses = $this->ticketCapabilityManager->getRemainingRedemptions($ticket);
    $count = $ticket->getRedemptionCount();

    $sequence_required = !empty($session_meta['sequence_required']);
    $required_prior = isset($session_meta['required_prior_redemptions']) && is_numeric($session_meta['required_prior_redemptions'])
      ? max(0, (int) $session_meta['required_prior_redemptions'])
      : 0;

    $reentry_allowed = $this->resolveReentryAllowed($map, $session_meta, $type);

    $multi_use = $registry_multi_use || $max_uses > 1;

    $requires_previous = $sequence_required && $count < $required_prior;
    $is_exhausted = $this->resolveExhausted($map, $remaining_uses);

    $current_state = $this->resolveProgressionState(
      $session_meta,
      $requires_previous,
      $is_exhausted,
      $sequence_required,
    );

    $next_ops = $this->resolveNextAllowedOperations($map, $is_exhausted, $requires_previous);

    $session_scanner = $this->resolveSessionScannerSlice($requires_previous, $is_exhausted);

    return [
      'session' => [
        'session_key' => $session_key,
        'session_type' => $session_type,
        'bundle_key' => $bundle_key,
        'zone_key' => $zone_key,
      ],
      'redemption' => [
        'multi_use' => $multi_use,
        'remaining_uses' => $remaining_uses,
        'max_uses' => $max_uses,
        'reentry_allowed' => $reentry_allowed,
        'sequence_required' => $sequence_required,
      ],
      'progression' => [
        'current_state' => $current_state,
        'next_allowed_operations' => $next_ops,
        'is_exhausted' => $is_exhausted,
        'requires_previous_redemption' => $requires_previous,
      ],
      'scanner' => $session_scanner,
    ];
  }

  /**
   * @param array<string, mixed> $map
   * @param array<string, mixed> $session_meta
   */
  private function resolveReentryAllowed(array $map, array $session_meta, string $type): bool {
    if (array_key_exists('reentry_allowed', $session_meta)) {
      return (bool) $session_meta['reentry_allowed'];
    }
    if ($map['scanner_mode'] === RedemptionLog::ACTION_VALIDATE && !(bool) $map['redeemable'] && !(bool) $map['multi_use']) {
      return TRUE;
    }
    if ($map['scanner_mode'] === RedemptionLog::ACTION_VERIFY && !(bool) $map['redeemable']) {
      return TRUE;
    }
    if ($this->entitlementCapabilityRegistry->isAdmissionEntitlementType($type)) {
      return FALSE;
    }
    return (bool) $map['multi_use'];
  }

  /**
   * @param array<string, mixed> $map
   */
  private function resolveExhausted(array $map, int $remaining_uses): bool {
    if ((bool) $map['redeemable'] && $remaining_uses < 1) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * @param array<string, mixed> $session_meta
   */
  private function resolveProgressionState(
    array $session_meta,
    bool $requires_previous,
    bool $is_exhausted,
    bool $sequence_required,
  ): string {
    if (isset($session_meta['progression_state']) && is_string($session_meta['progression_state']) && $session_meta['progression_state'] !== '') {
      return (string) $session_meta['progression_state'];
    }
    if ($requires_previous) {
      return 'sequencing_blocked';
    }
    if ($is_exhausted) {
      return 'exhausted';
    }
    if ($sequence_required) {
      return 'sequencing_ready';
    }
    if ($this->containerHasAnySessionKey($session_meta)) {
      return 'session_bound';
    }
    return 'legacy_neutral';
  }

  /**
   * @param array<string, mixed> $session_meta
   */
  private function containerHasAnySessionKey(array $session_meta): bool {
    foreach (['session_key', 'session_type', 'bundle_key', 'zone_key'] as $k) {
      if (!empty($session_meta[$k]) && is_scalar($session_meta[$k]) && trim((string) $session_meta[$k]) !== '') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @param array<string, mixed> $map
   *
   * @return list<string>
   */
  private function resolveNextAllowedOperations(array $map, bool $is_exhausted, bool $requires_previous): array {
    if ($is_exhausted || $requires_previous) {
      return [];
    }
    $action = (string) $map['scanner_mode'];
    return $action !== '' ? [$action] : [];
  }

  /**
   * @return array{allowed_now: bool, state: string, reason: string}
   */
  private function resolveSessionScannerSlice(bool $requires_previous, bool $is_exhausted): array {
    if ($requires_previous) {
      return [
        'allowed_now' => FALSE,
        'state' => 'sequencing_blocked',
        'reason' => 'sequence_previous_missing',
      ];
    }
    if ($is_exhausted) {
      return [
        'allowed_now' => FALSE,
        'state' => 'exhausted',
        'reason' => 'multi_use_exhausted',
      ];
    }
    return [
      'allowed_now' => TRUE,
      'state' => 'allowed',
      'reason' => 'session_clear',
    ];
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

  /**
   * @param array<string, mixed> $metadata
   *
   * @return array<string, mixed>
   */
  private function extractSessionContainer(array $metadata): array {
    foreach (['mel_operational_session', 'operational_session'] as $key) {
      if (!empty($metadata[$key]) && is_array($metadata[$key])) {
        return $metadata[$key];
      }
    }
    return [];
  }

  private function nullableString(mixed $value): ?string {
    if ($value === NULL || !is_scalar($value)) {
      return NULL;
    }
    $s = trim((string) $value);
    return $s === '' ? NULL : $s;
  }

}
