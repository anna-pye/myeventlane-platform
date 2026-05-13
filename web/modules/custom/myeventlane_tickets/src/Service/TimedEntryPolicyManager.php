<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\node\NodeInterface;

/**
 * Sole authority for machine-only operational timing on ticket entitlements.
 *
 * No translated strings, no UI labels. Callers compose results into venue and
 * scanner gates; QR contracts are unchanged.
 */
final class TimedEntryPolicyManager {

  /**
   * Evaluates operational timing for one ticket at a wall-clock instant.
   *
   * @return array<string, mixed>
   *   Normalized policy per docs/timed-entry-capacity-convergence.md.
   */
  public function evaluate(Ticket $ticket, int $now, ?int $parsed_qr_expires_at): array {
    $meta = $this->readTicketMetadataMap($ticket);
    $timing = $this->extractTimingContainer($meta);
    $event = $this->loadEvent($ticket);

    $candidates_open = [];
    $candidates_close = [];

    if (isset($timing['entry_opens_at']) && is_numeric($timing['entry_opens_at'])) {
      $candidates_open[] = (int) $timing['entry_opens_at'];
    }
    if (isset($timing['entry_closes_at']) && is_numeric($timing['entry_closes_at'])) {
      $candidates_close[] = (int) $timing['entry_closes_at'];
    }

    $event_start = $this->eventTimestamp($event, 'field_event_start');
    $event_end = $this->eventTimestamp($event, 'field_event_end');
    if ($event_end < 1) {
      $event_end = $event_start;
    }

    if (isset($timing['entry_open_offset_seconds']) && is_numeric($timing['entry_open_offset_seconds']) && $event_start > 0) {
      $candidates_open[] = $event_start + (int) $timing['entry_open_offset_seconds'];
    }
    if (isset($timing['entry_close_offset_seconds']) && is_numeric($timing['entry_close_offset_seconds'])) {
      $base = $event_end > 0 ? $event_end : $event_start;
      if ($base > 0) {
        $candidates_close[] = $base + (int) $timing['entry_close_offset_seconds'];
      }
    }

    $collect = $this->collectWindowBounds($ticket);
    if ($collect['start'] > 0) {
      $candidates_open[] = $collect['start'];
    }
    if ($collect['end'] > 0) {
      $candidates_close[] = $collect['end'];
    }

    $grace_seconds = isset($timing['grace_seconds']) && is_numeric($timing['grace_seconds'])
      ? max(0, (int) $timing['grace_seconds'])
      : 0;
    $late_entry_allowed = array_key_exists('late_entry_allowed', $timing)
      ? (bool) $timing['late_entry_allowed']
      : TRUE;
    $early_entry_allowed = array_key_exists('early_entry_allowed', $timing)
      ? (bool) $timing['early_entry_allowed']
      : FALSE;

    foreach ($this->hardCloseCaps($ticket, $parsed_qr_expires_at) as $cap) {
      if ($cap > 0) {
        $candidates_close[] = $cap;
      }
    }

    $opens_at = $this->latestDefined($candidates_open);
    $closes_at = $this->earliestDefined($candidates_close);

    $session_key = isset($timing['session_key']) && is_scalar($timing['session_key'])
      ? (string) $timing['session_key']
      : NULL;
    if ($session_key === '') {
      $session_key = NULL;
    }
    $capacity_window = isset($timing['capacity_window']) && is_scalar($timing['capacity_window'])
      ? (string) $timing['capacity_window']
      : NULL;
    if ($capacity_window === '') {
      $capacity_window = NULL;
    }

    if ($opens_at === NULL && $closes_at === NULL) {
      return $this->legacyUnrestrictedPolicy($session_key, $capacity_window);
    }

    if ($opens_at !== NULL && $closes_at !== NULL && $opens_at > $closes_at) {
      return $this->denyPolicy(
        'expired',
        'timing_bounds_inverted',
        $opens_at,
        $closes_at,
        $grace_seconds,
        $late_entry_allowed,
        $early_entry_allowed,
        $session_key,
        $capacity_window,
      );
    }

    $effective_close = $closes_at;
    $grace_close = ($closes_at !== NULL && $late_entry_allowed) ? $closes_at + $grace_seconds : $closes_at;

    if ($opens_at !== NULL && $now < $opens_at) {
      if ($early_entry_allowed) {
        return $this->allowPolicy(
          'early',
          'early_entry_window',
          $opens_at,
          $closes_at,
          $grace_seconds,
          $late_entry_allowed,
          $early_entry_allowed,
          $session_key,
          $capacity_window,
        );
      }
      return $this->denyPolicy(
        'not_started',
        'before_entry_open',
        $opens_at,
        $closes_at,
        $grace_seconds,
        $late_entry_allowed,
        $early_entry_allowed,
        $session_key,
        $capacity_window,
      );
    }

    if ($closes_at !== NULL && $now > (int) $grace_close) {
      return $this->denyPolicy(
        'expired',
        'after_entry_close',
        $opens_at,
        $closes_at,
        $grace_seconds,
        $late_entry_allowed,
        $early_entry_allowed,
        $session_key,
        $capacity_window,
      );
    }

    if ($effective_close !== NULL && $now > $effective_close && $grace_close !== NULL && $now <= (int) $grace_close && $late_entry_allowed) {
      return $this->allowPolicy(
        'late',
        'late_entry_grace',
        $opens_at,
        $closes_at,
        $grace_seconds,
        $late_entry_allowed,
        $early_entry_allowed,
        $session_key,
        $capacity_window,
      );
    }

    return $this->allowPolicy(
      'allowed',
      'within_entry_window',
      $opens_at,
      $closes_at,
      $grace_seconds,
      $late_entry_allowed,
      $early_entry_allowed,
      $session_key,
      $capacity_window,
    );
  }

  /**
   * Detects structural timing issues (machine codes only).
   *
   * @return list<string>
   */
  public function detectTimingConflicts(Ticket $ticket, int $now, ?int $parsed_qr_expires_at): array {
    $conflicts = [];
    $meta = $this->readTicketMetadataMap($ticket);
    $timing = $this->extractTimingContainer($meta);
    $event = $this->loadEvent($ticket);
    $event_start = $this->eventTimestamp($event, 'field_event_start');
    $event_end = $this->eventTimestamp($event, 'field_event_end');

    $has_offset_open = isset($timing['entry_open_offset_seconds']) && is_numeric($timing['entry_open_offset_seconds']);
    $has_offset_close = isset($timing['entry_close_offset_seconds']) && is_numeric($timing['entry_close_offset_seconds']);
    if (($has_offset_open || $has_offset_close) && $event_start < 1) {
      $conflicts[] = 'offset_without_event_bounds';
    }

    $candidates_open = [];
    $candidates_close = [];
    if (isset($timing['entry_opens_at']) && is_numeric($timing['entry_opens_at'])) {
      $candidates_open[] = (int) $timing['entry_opens_at'];
    }
    if (isset($timing['entry_closes_at']) && is_numeric($timing['entry_closes_at'])) {
      $candidates_close[] = (int) $timing['entry_closes_at'];
    }
    if ($has_offset_open && $event_start > 0) {
      $candidates_open[] = $event_start + (int) $timing['entry_open_offset_seconds'];
    }
    if ($has_offset_close) {
      $base = $event_end > 0 ? $event_end : $event_start;
      if ($base > 0) {
        $candidates_close[] = $base + (int) $timing['entry_close_offset_seconds'];
      }
    }
    $collect = $this->collectWindowBounds($ticket);
    if ($collect['start'] > 0) {
      $candidates_open[] = $collect['start'];
    }
    if ($collect['end'] > 0) {
      $candidates_close[] = $collect['end'];
    }
    $opens_at = $this->latestDefined($candidates_open);
    $closes_at = $this->earliestDefined($candidates_close);
    if ($opens_at !== NULL && $closes_at !== NULL && $opens_at > $closes_at) {
      $conflicts[] = 'conflicting_entry_bounds';
    }

    // Evaluate is cheap; surface exhausted window as a diagnostic without
    // implying the ticket row is structurally corrupt.
    $snapshot = $this->evaluate($ticket, $now, $parsed_qr_expires_at);
    if (($snapshot['scanner']['state'] ?? '') === 'expired' && ($snapshot['scanner']['reason'] ?? '') === 'timing_bounds_inverted') {
      $conflicts[] = 'timing_bounds_inverted';
    }

    return $conflicts;
  }

  /**
   * @return array<string, mixed>
   */
  private function legacyUnrestrictedPolicy(?string $session_key, ?string $capacity_window): array {
    return [
      'entry_window' => [
        'opens_at' => NULL,
        'closes_at' => NULL,
        'grace_seconds' => 0,
        'late_entry_allowed' => TRUE,
        'early_entry_allowed' => FALSE,
      ],
      'session' => [
        'session_key' => $session_key,
        'capacity_window' => $capacity_window,
      ],
      'scanner' => [
        'allowed_now' => TRUE,
        'state' => 'allowed',
        'reason' => 'legacy_unrestricted',
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function allowPolicy(
    string $state,
    string $reason,
    ?int $opens_at,
    ?int $closes_at,
    int $grace_seconds,
    bool $late_entry_allowed,
    bool $early_entry_allowed,
    ?string $session_key,
    ?string $capacity_window,
  ): array {
    return [
      'entry_window' => [
        'opens_at' => $opens_at,
        'closes_at' => $closes_at,
        'grace_seconds' => $grace_seconds,
        'late_entry_allowed' => $late_entry_allowed,
        'early_entry_allowed' => $early_entry_allowed,
      ],
      'session' => [
        'session_key' => $session_key,
        'capacity_window' => $capacity_window,
      ],
      'scanner' => [
        'allowed_now' => TRUE,
        'state' => $state,
        'reason' => $reason,
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function denyPolicy(
    string $state,
    string $reason,
    ?int $opens_at,
    ?int $closes_at,
    int $grace_seconds,
    bool $late_entry_allowed,
    bool $early_entry_allowed,
    ?string $session_key,
    ?string $capacity_window,
  ): array {
    return [
      'entry_window' => [
        'opens_at' => $opens_at,
        'closes_at' => $closes_at,
        'grace_seconds' => $grace_seconds,
        'late_entry_allowed' => $late_entry_allowed,
        'early_entry_allowed' => $early_entry_allowed,
      ],
      'session' => [
        'session_key' => $session_key,
        'capacity_window' => $capacity_window,
      ],
      'scanner' => [
        'allowed_now' => FALSE,
        'state' => $state,
        'reason' => $reason,
      ],
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
  private function extractTimingContainer(array $metadata): array {
    foreach (['mel_operational_timing', 'operational_timing'] as $key) {
      if (!empty($metadata[$key]) && is_array($metadata[$key])) {
        return $metadata[$key];
      }
    }
    return [];
  }

  private function loadEvent(Ticket $ticket): ?EntityInterface {
    if (!$ticket->hasField('event_id') || $ticket->get('event_id')->isEmpty()) {
      return NULL;
    }
    $event = $ticket->get('event_id')->entity;
    return $event instanceof EntityInterface ? $event : NULL;
  }

  private function eventTimestamp(?EntityInterface $event, string $field): int {
    if (!$event instanceof NodeInterface || !$event->hasField($field) || $event->get($field)->isEmpty()) {
      return 0;
    }
    $raw = trim((string) $event->get($field)->value);
    if ($raw === '') {
      return 0;
    }
    $ts = strtotime($raw . ' UTC');
    return $ts === FALSE ? 0 : $ts;
  }

  /**
   * @return array{start:int,end:int}
   */
  private function collectWindowBounds(Ticket $ticket): array {
    if (!$ticket->hasField('collect_window') || $ticket->get('collect_window')->isEmpty()) {
      return ['start' => 0, 'end' => 0];
    }
    $item = $ticket->get('collect_window')->first();
    if ($item === NULL) {
      return ['start' => 0, 'end' => 0];
    }
    $start = trim((string) ($item->value ?? ''));
    $end = trim((string) ($item->end_value ?? ''));
    $start_ts = $start !== '' ? strtotime($start . ' UTC') : FALSE;
    $end_ts = $end !== '' ? strtotime($end . ' UTC') : FALSE;
    return [
      'start' => $start_ts === FALSE ? 0 : (int) $start_ts,
      'end' => $end_ts === FALSE ? 0 : (int) $end_ts,
    ];
  }

  /**
   * @return list<int>
   */
  private function hardCloseCaps(Ticket $ticket, ?int $parsed_qr_expires_at): array {
    $caps = [];
    if ($ticket->hasField('expires_at') && !$ticket->get('expires_at')->isEmpty()) {
      $raw = trim((string) $ticket->get('expires_at')->value);
      if ($raw !== '') {
        $ts = strtotime($raw . ' UTC');
        if ($ts !== FALSE) {
          $caps[] = (int) $ts;
        }
      }
    }
    if ($parsed_qr_expires_at !== NULL && $parsed_qr_expires_at > 0) {
      $caps[] = $parsed_qr_expires_at;
    }
    return $caps;
  }

  /**
   * @param list<int> $values
   */
  private function latestDefined(array $values): ?int {
    $filtered = array_values(array_filter($values, static fn (int $v): bool => $v > 0));
    if ($filtered === []) {
      return NULL;
    }
    return max($filtered);
  }

  /**
   * @param list<int> $values
   */
  private function earliestDefined(array $values): ?int {
    $filtered = array_values(array_filter($values, static fn (int $v): bool => $v > 0));
    if ($filtered === []) {
      return NULL;
    }
    return min($filtered);
  }

}
