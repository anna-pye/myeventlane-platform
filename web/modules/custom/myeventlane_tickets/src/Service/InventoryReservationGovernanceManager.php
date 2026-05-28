<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\myeventlane_tickets\Entity\Ticket;

/**
 * Canonical inventory reservation governance (read-model only).
 *
 * Normalizes reservation lifecycle semantics, allocation visibility, readiness,
 * degradation, partial allocation, and continuity from merged inspector rollups.
 * Does not reserve stock, decrement inventory, or orchestrate warehouse systems.
 */
final class InventoryReservationGovernanceManager {

  public const STATE_UNRESERVED = 'unreserved';
  public const STATE_RESERVED = 'reserved';
  public const STATE_PARTIALLY_RESERVED = 'partially_reserved';
  public const STATE_ALLOCATED = 'allocated';
  public const STATE_PREPARED = 'prepared';
  public const STATE_READY_FOR_COLLECTION = 'ready_for_collection';
  public const STATE_DEGRADED = 'degraded';
  public const STATE_EXHAUSTED = 'exhausted';
  public const STATE_FULFILLED = 'fulfilled';
  public const STATE_RELEASED = 'released';
  public const STATE_EXPIRED = 'expired';
  public const STATE_CANCELLED = 'cancelled';

  public const TYPE_MERCH = 'merch';
  public const TYPE_HOSPITALITY = 'hospitality';
  public const TYPE_FOOD_DRINK = 'food_drink';
  public const TYPE_PARKING = 'parking';
  public const TYPE_VIP_PACKAGE = 'vip_package';
  public const TYPE_EQUIPMENT = 'equipment';
  public const TYPE_CLOAKROOM = 'cloakroom';
  public const TYPE_TIMED_PICKUP = 'timed_pickup';
  public const TYPE_DIGITAL_REDEMPTION = 'digital_redemption';

  /**
   * Canonical reservation ordering for audit continuity (low → progressed).
   *
   * @var list<string>
   */
  private const RESERVATION_ORDER = [
    self::STATE_UNRESERVED,
    self::STATE_RESERVED,
    self::STATE_PARTIALLY_RESERVED,
    self::STATE_ALLOCATED,
    self::STATE_PREPARED,
    self::STATE_READY_FOR_COLLECTION,
    self::STATE_DEGRADED,
    self::STATE_EXHAUSTED,
    self::STATE_FULFILLED,
    self::STATE_RELEASED,
    self::STATE_EXPIRED,
    self::STATE_CANCELLED,
  ];

  public function __construct(
    private readonly EntitlementCapabilityRegistry $entitlementCapabilityRegistry,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Builds a single normalized reservation read-model for workspace layers.
   *
   * @param array<string, mixed> $merged
   *   Output shape compatible with
   *   {@see OperationalWorkspaceBuilder::mergeInspections()}.
   *
   * @return array<string, mixed>
   */
  public function composeReservationReadModel(array $merged, int $requestTime): array {
    $signals = is_array($merged['fulfillment_signals'] ?? NULL) ? $merged['fulfillment_signals'] : [];
    $by_ticket = is_array($signals['by_ticket_id'] ?? NULL) ? $signals['by_ticket_id'] : [];
    $artifacts = is_array($merged['artifacts'] ?? NULL) ? $merged['artifacts'] : [];
    $continuity = is_array($artifacts['operational_continuity'] ?? NULL) ? $artifacts['operational_continuity'] : [];
    $timed = is_array($artifacts['timed_entry_policy'] ?? NULL) ? $artifacts['timed_entry_policy'] : [];
    $issuance = is_array($merged['issuance'] ?? NULL) ? $merged['issuance'] : [];
    $guest = is_array($merged['guest_continuity'] ?? NULL) ? $merged['guest_continuity'] : [];
    $recovery = is_array($merged['recovery'] ?? NULL) ? $merged['recovery'] : [];
    $canonical_pdf = (string) ($artifacts['canonical_pdf_readiness'] ?? 'missing');

    $rollup_degraded = $this->rollupDegraded($issuance, $recovery, $guest, $canonical_pdf);
    $issuance_aligned = ((string) ($issuance['worst_quantity_alignment_status'] ?? '')) === 'valid';

    $ticket_projections = [];
    $state_tally = array_fill_keys(self::RESERVATION_ORDER, 0);
    $type_tally = [];
    foreach (self::allReservationTypes() as $t) {
      $type_tally[$t] = 0;
    }

    foreach ($by_ticket as $ticket_id => $row) {
      if (!is_array($row)) {
        continue;
      }
      $entitlement = $this->entitlementCapabilityRegistry->normalizeEntitlementType((string) ($row['entitlement_type'] ?? ''));
      $cap = $this->entitlementCapabilityRegistry->getCapabilityMap($entitlement);

      $continuity_row = is_array($continuity[(string) $ticket_id] ?? NULL)
        ? $continuity[(string) $ticket_id]
        : [];
      $continuity_mode = (string) (($continuity_row['continuity_summary']['continuity_mode'] ?? '') ?: '');
      $timing_row = is_array($timed[(string) $ticket_id] ?? NULL) ? $timed[(string) $ticket_id] : [];
      $timing_policy = is_array($timing_row['policy'] ?? NULL) ? $timing_row['policy'] : [];
      $timed_scanner_state = (string) (($timing_policy['scanner']['state'] ?? '') ?: '');

      $reservation_type = $this->mapEntitlementToReservationType($entitlement, (int) ($row['redemption_limit'] ?? 1), $timed_scanner_state);
      $type_tally[$reservation_type] = ($type_tally[$reservation_type] ?? 0) + 1;

      $normalized = $this->normalizeTicketReservationState(
        $row,
        $cap,
        $rollup_degraded,
        $canonical_pdf,
        $continuity_mode,
        $reservation_type,
        $issuance_aligned,
      );

      $state_tally[$normalized] = ($state_tally[$normalized] ?? 0) + 1;

      $allocation_summary = $this->buildAllocationSummary($row, $cap, $normalized, $reservation_type);
      $readiness_summary = $this->buildReadinessSummary($normalized, $cap, $canonical_pdf, $timed_scanner_state);
      $continuity_summary = $this->buildContinuitySummary($continuity_mode, $normalized);

      $ticket_projections[] = [
        'ticket_ordinal' => (int) $ticket_id,
        'entitlement_type' => $entitlement,
        'reservation_type' => $reservation_type,
        'normalized_reservation_state' => $normalized,
        'allocation_summary' => $allocation_summary,
        'readiness_summary' => $readiness_summary,
        'continuity_summary' => $continuity_summary,
        'partial_allocation' => $this->isPartialAllocation($row, $cap),
        'degradation_lane' => $rollup_degraded ? 'rollup_degraded' : 'nominal',
      ];
    }

    usort($ticket_projections, static function (array $a, array $b): int {
      return ($a['ticket_ordinal'] ?? 0) <=> ($b['ticket_ordinal'] ?? 0);
    });

    return [
      'request_time' => $requestTime,
      'rollup' => [
        'canonical_reservation_state_tally' => $state_tally,
        'reservation_type_tally' => $type_tally,
        'sampled_ticket_rows' => count($ticket_projections),
        'partial_allocation_rows' => $this->countPartialRows($ticket_projections),
        'rollup_continuity_composition' => [
          'guest_continuity_statuses' => $guest['continuity_statuses_observed'] ?? [],
          'worst_quantity_alignment_status' => (string) ($issuance['worst_quantity_alignment_status'] ?? ''),
          'canonical_pdf_readiness' => $canonical_pdf,
          'issuance_aligned' => $issuance_aligned,
        ],
      ],
      'ticket_projections' => $ticket_projections,
      'readiness_projection' => $this->buildReadinessProjection($state_tally, $ticket_projections, $canonical_pdf),
      'degradation_projection' => $this->buildDegradationProjection($rollup_degraded, $state_tally, $canonical_pdf),
      'continuity_projection' => $this->buildContinuityProjection($ticket_projections, $guest),
      'allocation_projection' => $this->buildAllocationProjection($state_tally, count($ticket_projections)),
    ];
  }

  /**
   * Normalizes a reservation state token.
   */
  public function normalizeReservationState(?string $raw): string {
    $token = strtolower(trim((string) $raw));
    if ($token === '') {
      return self::STATE_UNRESERVED;
    }
    if (!in_array($token, self::RESERVATION_ORDER, TRUE)) {
      $this->logger->warning('InventoryReservationGovernanceManager: unknown reservation state @token normalized to unreserved.', [
        '@token' => $token,
        'token' => $token,
      ]);
      return self::STATE_UNRESERVED;
    }
    return $token;
  }

  /**
   * Normalizes a reservation type token.
   */
  public function normalizeReservationType(?string $raw): string {
    $token = strtolower(trim((string) $raw));
    if ($token === '') {
      return self::TYPE_DIGITAL_REDEMPTION;
    }
    if (!in_array($token, self::allReservationTypes(), TRUE)) {
      $this->logger->warning('InventoryReservationGovernanceManager: unknown reservation type @token normalized to digital_redemption.', [
        '@token' => $token,
        'token' => $token,
      ]);
      return self::TYPE_DIGITAL_REDEMPTION;
    }
    return $token;
  }

  /**
   * Projects an observational reservation state from merged rollup keys only.
   *
   * @param array<string, mixed> $merged
   */
  public function projectReservationStateFromRollup(array $merged): string {
    if ($merged === []) {
      return self::STATE_UNRESERVED;
    }
    $model = $this->composeReservationReadModel($merged, 0);
    $tally = is_array($model['rollup']['canonical_reservation_state_tally'] ?? NULL)
      ? $model['rollup']['canonical_reservation_state_tally']
      : [];
    foreach (array_reverse(self::RESERVATION_ORDER) as $state) {
      if (($tally[$state] ?? 0) > 0) {
        return $state;
      }
    }
    return self::STATE_UNRESERVED;
  }

  /**
   * Maps stored entitlement types to canonical reservation types.
   */
  public function mapEntitlementToReservationType(
    string $entitlement_type,
    int $redemption_limit,
    string $timed_scanner_state = '',
  ): string {
    $ent = $this->entitlementCapabilityRegistry->normalizeEntitlementType($entitlement_type);
    if ($timed_scanner_state !== '' && $timed_scanner_state !== 'n/a') {
      return self::TYPE_TIMED_PICKUP;
    }
    return match ($ent) {
      Ticket::ENTITLEMENT_MERCH => self::TYPE_MERCH,
      Ticket::ENTITLEMENT_PARKING => self::TYPE_PARKING,
      Ticket::ENTITLEMENT_DRINK => self::TYPE_FOOD_DRINK,
      Ticket::ENTITLEMENT_FOOD => self::TYPE_FOOD_DRINK,
      Ticket::ENTITLEMENT_VIP => self::TYPE_VIP_PACKAGE,
      Ticket::ENTITLEMENT_ADDON => $redemption_limit > 1 ? self::TYPE_EQUIPMENT : self::TYPE_HOSPITALITY,
      default => self::TYPE_DIGITAL_REDEMPTION,
    };
  }

  /**
   * @param array<string, mixed> $row
   * @param array<string, mixed> $cap
   */
  public function isPartialAllocation(array $row, array $cap): bool {
    $count = (int) ($row['redemption_count'] ?? 0);
    $limit = max(0, (int) ($row['redemption_limit'] ?? 0));
    $redeemable = (bool) ($cap['redeemable'] ?? FALSE);
    $multi = (bool) ($cap['multi_use'] ?? FALSE);
    return $redeemable && $multi && $limit > 1 && $count > 0 && $count < $limit;
  }

  /**
   * @param array<string, mixed> $row
   * @param array<string, mixed> $cap
   */
  public function normalizeTicketReservationState(
    array $row,
    array $cap,
    bool $rollup_degraded,
    string $canonical_pdf_readiness,
    string $continuity_mode,
    string $reservation_type,
    bool $issuance_aligned,
  ): string {
    if ($rollup_degraded && $continuity_mode === 'degraded') {
      return self::STATE_DEGRADED;
    }
    if ($rollup_degraded) {
      return self::STATE_DEGRADED;
    }

    $fulfilment = (string) ($row['fulfilment_status'] ?? '');
    $ticket_status = (string) ($row['ticket_status'] ?? '');
    $admission_in = !empty($row['admission_checked_in']);
    $count = (int) ($row['redemption_count'] ?? 0);
    $limit = max(0, (int) ($row['redemption_limit'] ?? 0));
    $redeemable = (bool) ($cap['redeemable'] ?? FALSE);
    $multi = (bool) ($cap['multi_use'] ?? FALSE);
    $requires_fulfilment = (bool) ($cap['requires_fulfilment'] ?? FALSE);
    $supports_collection = (bool) ($cap['supports_collection'] ?? FALSE);

    if ($ticket_status === Ticket::STATUS_VOID || $fulfilment === Ticket::FULFILMENT_CANCELLED) {
      return self::STATE_CANCELLED;
    }
    if ($ticket_status === Ticket::STATUS_REFUNDED) {
      return self::STATE_RELEASED;
    }
    if ($fulfilment === Ticket::FULFILMENT_EXPIRED) {
      return self::STATE_EXPIRED;
    }

    if ($redeemable && $multi && $limit > 0 && $count >= $limit) {
      return self::STATE_EXHAUSTED;
    }
    if ($fulfilment === Ticket::FULFILMENT_REDEEMED) {
      return self::STATE_FULFILLED;
    }
    if ($fulfilment === Ticket::FULFILMENT_COLLECTED) {
      return self::STATE_FULFILLED;
    }

    $entitlement = $this->entitlementCapabilityRegistry->normalizeEntitlementType((string) ($row['entitlement_type'] ?? ''));
    if ($this->entitlementCapabilityRegistry->isAdmissionEntitlementType($entitlement) && $admission_in) {
      return self::STATE_FULFILLED;
    }

    if ($this->isPartialAllocation($row, $cap)) {
      return self::STATE_PARTIALLY_RESERVED;
    }

    if ($fulfilment === Ticket::FULFILMENT_READY) {
      if ($supports_collection) {
        return self::STATE_READY_FOR_COLLECTION;
      }
      return self::STATE_ALLOCATED;
    }

    if ($fulfilment === Ticket::FULFILMENT_PENDING) {
      if (!$issuance_aligned) {
        return self::STATE_UNRESERVED;
      }
      if ($requires_fulfilment && $reservation_type === self::TYPE_MERCH
        && in_array($canonical_pdf_readiness, ['valid', 'canonical'], TRUE)) {
        return self::STATE_PREPARED;
      }
      if ($reservation_type === self::TYPE_PARKING || $reservation_type === self::TYPE_VIP_PACKAGE) {
        return self::STATE_ALLOCATED;
      }
      if ($reservation_type === self::TYPE_TIMED_PICKUP) {
        return self::STATE_RESERVED;
      }
      return self::STATE_RESERVED;
    }

    return self::STATE_UNRESERVED;
  }

  /**
   * Builds a read-only audit timeline from normalized reservation events.
   *
   * @param list<array<string, mixed>> $events
   *
   * @return list<array<string, mixed>>
   */
  public function projectReservationAuditTimeline(array $events): array {
    $out = [];
    foreach ($events as $event) {
      if (!is_array($event)) {
        continue;
      }
      $state = $this->normalizeReservationState(isset($event['state']) ? (string) $event['state'] : NULL);
      $at = (int) ($event['recorded_at'] ?? 0);
      $note = trim((string) ($event['note'] ?? ''));
      $out[] = [
        'reservation_state' => $state,
        'recorded_at_unix' => $at,
        'audit_note' => $note !== '' ? $note : 'reservation_governance_event',
      ];
    }
    usort($out, static function (array $a, array $b): int {
      return ($a['recorded_at_unix'] ?? 0) <=> ($b['recorded_at_unix'] ?? 0);
    });
    return $out;
  }

  /**
   * @return list<string>
   */
  public function allCanonicalStates(): array {
    return self::RESERVATION_ORDER;
  }

  /**
   * @return list<string>
   */
  public function allReservationTypes(): array {
    return [
      self::TYPE_MERCH,
      self::TYPE_HOSPITALITY,
      self::TYPE_FOOD_DRINK,
      self::TYPE_PARKING,
      self::TYPE_VIP_PACKAGE,
      self::TYPE_EQUIPMENT,
      self::TYPE_CLOAKROOM,
      self::TYPE_TIMED_PICKUP,
      self::TYPE_DIGITAL_REDEMPTION,
    ];
  }

  /**
   * @param array<string, mixed> $issuance
   * @param array<string, mixed> $recovery
   * @param array<string, mixed> $guest
   */
  private function rollupDegraded(
    array $issuance,
    array $recovery,
    array $guest,
    string $canonical_pdf_readiness,
  ): bool {
    if (!empty($recovery['recovery_mismatch_observed'])) {
      return TRUE;
    }
    $worst = (string) ($issuance['worst_quantity_alignment_status'] ?? '');
    if (in_array($worst, ['invalid', 'missing'], TRUE)) {
      return TRUE;
    }
    foreach ($guest['continuity_statuses_observed'] ?? [] as $st) {
      if ((string) $st === 'invalid') {
        return TRUE;
      }
    }
    if ($canonical_pdf_readiness !== 'valid' && $canonical_pdf_readiness !== 'canonical') {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * @param array<string, mixed> $row
   * @param array<string, mixed> $cap
   */
  private function buildAllocationSummary(
    array $row,
    array $cap,
    string $normalized,
    string $reservation_type,
  ): string {
    $pieces = [
      'reservation_type=' . $reservation_type,
      'state=' . $normalized,
      'redemptions=' . (string) (int) ($row['redemption_count'] ?? 0) . '/' . (string) (int) ($row['redemption_limit'] ?? 0),
    ];
    if ($this->isPartialAllocation($row, $cap)) {
      $pieces[] = 'partial_allocation=yes';
    }
    else {
      $pieces[] = 'partial_allocation=no';
    }
    return implode('; ', $pieces);
  }

  private function buildReadinessSummary(
    string $normalized,
    array $cap,
    string $canonical_pdf_readiness,
    string $timed_scanner_state,
  ): string {
    return 'lifecycle=' . $normalized
      . '; requires_fulfilment=' . ((bool) ($cap['requires_fulfilment'] ?? FALSE) ? 'yes' : 'no')
      . '; pdf=' . $canonical_pdf_readiness
      . '; timed=' . ($timed_scanner_state !== '' ? $timed_scanner_state : 'n/a');
  }

  private function buildContinuitySummary(string $continuity_mode, string $normalized): string {
    return 'continuity_mode=' . ($continuity_mode !== '' ? $continuity_mode : 'n/a')
      . '; reservation_state=' . $normalized;
  }

  /**
   * @param list<array<string, mixed>> $ticket_projections
   */
  private function countPartialRows(array $ticket_projections): int {
    $n = 0;
    foreach ($ticket_projections as $p) {
      if (!empty($p['partial_allocation'])) {
        $n++;
      }
    }
    return $n;
  }

  /**
   * @param array<string, int> $state_tally
   * @param list<array<string, mixed>> $ticket_projections
   *
   * @return array<string, mixed>
   */
  private function buildReadinessProjection(
    array $state_tally,
    array $ticket_projections,
    string $canonical_pdf_readiness,
  ): array {
    $readyish = ($state_tally[self::STATE_PREPARED] ?? 0)
      + ($state_tally[self::STATE_READY_FOR_COLLECTION] ?? 0)
      + ($state_tally[self::STATE_ALLOCATED] ?? 0)
      + ($state_tally[self::STATE_PARTIALLY_RESERVED] ?? 0);
    return [
      'descriptor' => 'reservation_readiness_visibility',
      'collection_ready_rows' => (int) ($state_tally[self::STATE_READY_FOR_COLLECTION] ?? 0),
      'execution_ready_rows' => $readyish,
      'artifact_readiness_signal' => $canonical_pdf_readiness,
      'timed_pickup_ready_rows' => $this->countTimedPickupReady($ticket_projections),
    ];
  }

  /**
   * @param array<string, int> $state_tally
   *
   * @return array<string, mixed>
   */
  private function buildDegradationProjection(
    bool $rollup_degraded,
    array $state_tally,
    string $canonical_pdf_readiness,
  ): array {
    $parts = [];
    if ($rollup_degraded) {
      $parts[] = 'rollup_or_continuity_degraded';
    }
    if (($state_tally[self::STATE_DEGRADED] ?? 0) > 0) {
      $parts[] = 'normalized_degraded_rows';
    }
    if ($canonical_pdf_readiness !== 'valid' && $canonical_pdf_readiness !== 'canonical') {
      $parts[] = 'artifact_readiness_degraded';
    }
    return [
      'descriptor' => $parts === [] ? 'nominal' : implode(';', $parts),
      'signals' => $parts,
    ];
  }

  /**
   * @param list<array<string, mixed>> $ticket_projections
   * @param array<string, mixed> $guest
   *
   * @return array<string, mixed>
   */
  private function buildContinuityProjection(array $ticket_projections, array $guest): array {
    $modes = [];
    foreach ($ticket_projections as $row) {
      if (!is_array($row)) {
        continue;
      }
      $summary = (string) ($row['continuity_summary'] ?? '');
      if (str_contains($summary, 'continuity_mode=')) {
        $modes[] = $summary;
      }
    }
    return [
      'descriptor' => 'reservation_continuity_visibility',
      'guest_continuity_statuses' => $guest['continuity_statuses_observed'] ?? [],
      'continuity_row_count' => count($modes),
    ];
  }

  /**
   * @param array<string, int> $state_tally
   *
   * @return array<string, mixed>
   */
  private function buildAllocationProjection(array $state_tally, int $sampled): array {
    if ($sampled < 1) {
      return [
        'descriptor' => 'no_sampled_ticket_rows',
        'allocated_fraction' => 0.0,
      ];
    }
    $allocated = ($state_tally[self::STATE_ALLOCATED] ?? 0)
      + ($state_tally[self::STATE_PREPARED] ?? 0)
      + ($state_tally[self::STATE_READY_FOR_COLLECTION] ?? 0)
      + ($state_tally[self::STATE_PARTIALLY_RESERVED] ?? 0)
      + ($state_tally[self::STATE_RESERVED] ?? 0);
    return [
      'descriptor' => 'allocation_visibility',
      'allocated_fraction' => round($allocated / $sampled, 4),
      'allocated_rows' => $allocated,
    ];
  }

  /**
   * @param list<array<string, mixed>> $ticket_projections
   */
  private function countTimedPickupReady(array $ticket_projections): int {
    $n = 0;
    foreach ($ticket_projections as $row) {
      if (!is_array($row)) {
        continue;
      }
      if (($row['reservation_type'] ?? '') !== self::TYPE_TIMED_PICKUP) {
        continue;
      }
      $state = (string) ($row['normalized_reservation_state'] ?? '');
      if (in_array($state, [self::STATE_PREPARED, self::STATE_READY_FOR_COLLECTION, self::STATE_ALLOCATED], TRUE)) {
        $n++;
      }
    }
    return $n;
  }

}
