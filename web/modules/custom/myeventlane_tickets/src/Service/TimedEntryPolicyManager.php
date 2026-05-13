<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\myeventlane_tickets\Entity\Ticket;

/**
 * Canonical operational timing policy for ticket-backed entitlements.
 *
 * Machine-only output: no translated strings, no UI labels. Callers map
 * scanner states to existing API result tokens where appropriate.
 *
 * Timing may be derived from event bounds, ticket collect_window,
 * metadata_json (see docs/timed-entry-capacity-convergence.md), optional QR
 * payload expiry, and ticket expires_at. When no window sources exist,
 * behaviour matches legacy unrestricted admission timing.
 */
final class TimedEntryPolicyManager {

  /**
   * Metadata keys (first match wins) for structured operational timing.
   */
  private const METADATA_TIMING_KEYS = [
    'mel_operational_timing',
    'operational_timing',
  ];

  public function __construct(
    private readonly TimeInterface $time,
    private readonly EntitlementCapabilityRegistry $entitlementCapabilityRegistry,
  ) {}

  /**
   * Evaluates timed entry, session/capacity window metadata, and scanner state.
   *
   * @param int|null $now_unix
   *   Evaluation instant; NULL uses request time.
   * @param int|null $parsed_qr_expires_at
   *   Optional structured QR payload exp (Unix); caps closing without changing
   *   QR signing contracts.
   *
   * @return array{
   *   entry_window: array{
   *     opens_at: int|null,
   *     closes_at: int|null,
   *     grace_seconds: int,
   *     late_entry_allowed: bool,
   *     early_entry_allowed: bool
   *   },
   *   session: array{session_key: string|null, capacity_window: string|null},
   *   scanner: array{allowed_now: bool, state: string, reason: string}
   * }
   */
  public function evaluate(Ticket $ticket, ?int $now_unix = NULL, ?int $parsed_qr_expires_at = NULL): array {
    $now = $now_unix ?? $this->time->getCurrentTime();
    $timing = $this->readTimingMetadata($ticket);
    $grace = max(0, (int) ($timing['grace_seconds'] ?? 0));
    $late_allowed = (bool) ($timing['late_entry_allowed'] ?? TRUE);
    $early_allowed = (bool) ($timing['early_entry_allowed'] ?? FALSE);
    $session_key = isset($timing['session_key']) && is_string($timing['session_key']) && $timing['session_key'] !== ''
      ? $timing['session_key']
      : NULL;
    $capacity_window = isset($timing['capacity_window']) && is_string($timing['capacity_window']) && $timing['capacity_window'] !== ''
      ? $timing['capacity_window']
      : NULL;

    [$opens, $closes] = $this->resolveEntryBounds($ticket, $timing);
    $opens = $this->normalizeBound($opens);
    $closes = $this->normalizeBound($closes);

    $ticket_expires = $this->ticketExpiresAtUnix($ticket);
    $closes = $this->applyUpperCap($closes, $ticket_expires);
    $closes = $this->applyUpperCap($closes, $parsed_qr_expires_at);

    if ($opens !== NULL && $closes !== NULL && $closes < $opens) {
      $closes = $opens;
    }

    $effective_close = $closes;
    if ($closes !== NULL && $late_allowed && $grace > 0) {
      $effective_close = $closes + $grace;
    }

    $scanner = $this->resolveScannerState($now, $opens, $closes, $effective_close, $early_allowed, $late_allowed);

    return [
      'entry_window' => [
        'opens_at' => $opens,
        'closes_at' => $closes,
        'grace_seconds' => $grace,
        'late_entry_allowed' => $late_allowed,
        'early_entry_allowed' => $early_allowed,
      ],
      'session' => [
        'session_key' => $session_key,
        'capacity_window' => $capacity_window,
      ],
      'scanner' => $scanner,
    ];
  }

  /**
   * Detects machine-only timing conflict codes for staff diagnostics.
   *
   * @return list<string>
   */
  public function detectTimingConflicts(Ticket $ticket): array {
    $conflicts = [];
    $timing = $this->readTimingMetadata($ticket);
    [$meta_open, $meta_close] = $this->boundsFromMetadataAbsolute($timing);
    [$cw_open, $cw_close] = $this->boundsFromCollectWindow($ticket);

    if ($meta_open !== NULL && $cw_open !== NULL && abs($meta_open - $cw_open) > 60) {
      $conflicts[] = 'metadata_opens_vs_collect_window';
    }
    if ($meta_close !== NULL && $cw_close !== NULL && abs($meta_close - $cw_close) > 60) {
      $conflicts[] = 'metadata_closes_vs_collect_window';
    }

    $event = $ticket->get('event_id')->entity;
    $event_start = $this->entityDatetimeUnix($event, 'field_event_start');
    $event_end = $this->entityDatetimeUnix($event, 'field_event_end');
    if ($meta_open !== NULL && $event_start !== NULL && $meta_open < $event_start - 86400) {
      $conflicts[] = 'metadata_opens_far_before_event_start';
    }
    if ($meta_close !== NULL && $event_end !== NULL && $meta_close > $event_end + 86400) {
      $conflicts[] = 'metadata_closes_far_after_event_end';
    }

    return $conflicts;
  }

  /**
   * @param array<string, mixed> $timing
   *
   * @return array{0: int|null, 1: int|null}
   */
  private function resolveEntryBounds(Ticket $ticket, array $timing): array {
    [$m_open, $m_close] = $this->boundsFromMetadataAbsolute($timing);
    [$cw_open, $cw_close] = $this->boundsFromCollectWindow($ticket);
    [$off_open, $off_close] = $this->boundsFromMetadataOffsets($ticket, $timing);

    $opens = $this->strictestOpen($m_open, $cw_open, $off_open);
    $closes = $this->strictestClose($m_close, $cw_close, $off_close);

    if ($opens === NULL && $closes === NULL) {
      [$fallback_open, $fallback_close] = $this->boundsFromAdmissionEventDefaults($ticket);
      $opens = $opens ?? $fallback_open;
      $closes = $closes ?? $fallback_close;
    }

    return [$opens, $closes];
  }

  /**
   * Admission tickets without explicit windows do not impose event-time gates
   * (legacy). Non-admission entitlements may still use collect_window/metadata.
   */
  private function boundsFromAdmissionEventDefaults(Ticket $ticket): array {
    $type = $this->entitlementCapabilityRegistry->normalizeEntitlementType($ticket->getEntitlementType());
    if ($this->entitlementCapabilityRegistry->isAdmissionEntitlementType($type)) {
      return [NULL, NULL];
    }
    return [NULL, NULL];
  }

  /**
   * @return array{0: int|null, 1: int|null}
   */
  private function boundsFromCollectWindow(Ticket $ticket): array {
    if (!$ticket->hasField('collect_window') || $ticket->get('collect_window')->isEmpty()) {
      return [NULL, NULL];
    }
    $item = $ticket->get('collect_window')->first();
    $start = trim((string) ($item?->get('value')->getValue() ?? ''));
    $end = trim((string) ($item?->get('end_value')->getValue() ?? ''));
    return [$this->parseUtcDatetime($start), $this->parseUtcDatetime($end)];
  }

  /**
   * @param array<string, mixed> $timing
   *
   * @return array{0: int|null, 1: int|null}
   */
  private function boundsFromMetadataAbsolute(array $timing): array {
    $open = isset($timing['entry_opens_at']) && is_numeric($timing['entry_opens_at'])
      ? (int) $timing['entry_opens_at']
      : NULL;
    $close = isset($timing['entry_closes_at']) && is_numeric($timing['entry_closes_at'])
      ? (int) $timing['entry_closes_at']
      : NULL;
    return [$open, $close];
  }

  /**
   * @param array<string, mixed> $timing
   *
   * @return array{0: int|null, 1: int|null}
   */
  private function boundsFromMetadataOffsets(Ticket $ticket, array $timing): array {
    $event = $ticket->get('event_id')->entity;
    $event_start = $this->entityDatetimeUnix($event, 'field_event_start');
    $event_end = $this->entityDatetimeUnix($event, 'field_event_end');
    $anchor = $event_start ?? $event_end;
    if ($anchor === NULL) {
      return [NULL, NULL];
    }
    $open_off = isset($timing['entry_open_offset_seconds']) && is_numeric($timing['entry_open_offset_seconds'])
      ? (int) $timing['entry_open_offset_seconds']
      : NULL;
    $close_off = isset($timing['entry_close_offset_seconds']) && is_numeric($timing['entry_close_offset_seconds'])
      ? (int) $timing['entry_close_offset_seconds']
      : NULL;
    $open = $open_off !== NULL ? $anchor + $open_off : NULL;
    $close_anchor = $event_end ?? $event_start;
    $close = $close_off !== NULL && $close_anchor !== NULL ? $close_anchor + $close_off : NULL;
    return [$open, $close];
  }

  /**
   * @return array<string, mixed>
   */
  private function readTimingMetadata(Ticket $ticket): array {
    if (!$ticket->hasField('metadata_json') || $ticket->get('metadata_json')->isEmpty()) {
      return [];
    }
    $value = $ticket->get('metadata_json')->first()?->getValue() ?? [];
    $map = [];
    if (isset($value['value']) && is_array($value['value'])) {
      $map = $value['value'];
    }
    elseif (is_array($value)) {
      $map = $value;
    }
    foreach (self::METADATA_TIMING_KEYS as $key) {
      if (isset($map[$key]) && is_array($map[$key])) {
        return $map[$key];
      }
    }
    return [];
  }

  private function ticketExpiresAtUnix(Ticket $ticket): ?int {
    if (!$ticket->hasField('expires_at') || $ticket->get('expires_at')->isEmpty()) {
      return NULL;
    }
    return $this->parseUtcDatetime((string) $ticket->get('expires_at')->value);
  }

  private function entityDatetimeUnix(?EntityInterface $entity, string $field): ?int {
    if (!$entity instanceof EntityInterface || !$entity->hasField($field) || $entity->get($field)->isEmpty()) {
      return NULL;
    }
    return $this->parseUtcDatetime((string) $entity->get($field)->value);
  }

  private function parseUtcDatetime(string $raw): ?int {
    $raw = trim($raw);
    if ($raw === '') {
      return NULL;
    }
    $ts = strtotime($raw . ' UTC');
    return $ts === FALSE ? NULL : $ts;
  }

  private function normalizeBound(?int $bound): ?int {
    return $bound === NULL || $bound < 1 ? NULL : $bound;
  }

  private function applyUpperCap(?int $closes, ?int $cap): ?int {
    if ($cap === NULL || $cap < 1) {
      return $closes;
    }
    if ($closes === NULL) {
      return $cap;
    }
    return min($closes, $cap);
  }

  /**
   * Latest opening instant among defined sources (strictest / intersection).
   *
   * @param int|null ...$candidates
   */
  private function strictestOpen(?int ...$candidates): ?int {
    $filtered = [];
    foreach ($candidates as $c) {
      if ($c !== NULL && $c > 0) {
        $filtered[] = $c;
      }
    }
    return $filtered === [] ? NULL : max($filtered);
  }

  /**
   * Earliest closing instant among defined sources (strictest / intersection).
   *
   * @param int|null ...$candidates
   */
  private function strictestClose(?int ...$candidates): ?int {
    $filtered = [];
    foreach ($candidates as $c) {
      if ($c !== NULL && $c > 0) {
        $filtered[] = $c;
      }
    }
    return $filtered === [] ? NULL : min($filtered);
  }

  /**
   * @return array{allowed_now: bool, state: string, reason: string}
   */
  private function resolveScannerState(
    int $now,
    ?int $opens,
    ?int $closes,
    ?int $effective_close,
    bool $early_allowed,
    bool $late_allowed,
  ): array {
    if ($opens === NULL && $closes === NULL) {
      return [
        'allowed_now' => TRUE,
        'state' => 'allowed',
        'reason' => 'legacy_unrestricted',
      ];
    }

    if ($opens !== NULL && $now < $opens) {
      if ($early_allowed) {
        return [
          'allowed_now' => TRUE,
          'state' => 'early',
          'reason' => 'before_nominal_open_early_allowed',
        ];
      }
      return [
        'allowed_now' => FALSE,
        'state' => 'not_started',
        'reason' => 'before_entry_opens',
      ];
    }

    if ($closes !== NULL) {
      $allow_late_grace = $late_allowed && $effective_close !== NULL && $effective_close > $closes;
      if ($now > $closes && $allow_late_grace && $now <= $effective_close) {
        return [
          'allowed_now' => TRUE,
          'state' => 'late',
          'reason' => 'within_late_grace',
        ];
      }
      $strict_deadline = $allow_late_grace ? $effective_close : $closes;
      if ($now > $strict_deadline) {
        return [
          'allowed_now' => FALSE,
          'state' => 'expired',
          'reason' => $allow_late_grace ? 'after_effective_close' : 'after_entry_closes',
        ];
      }
    }

    return [
      'allowed_now' => TRUE,
      'state' => 'allowed',
      'reason' => 'within_entry_window',
    ];
  }

}
