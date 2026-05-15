<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\myeventlane_tickets\Entity\Ticket;

/**
 * Canonical operational entitlement capability convergence (read-model only).
 *
 * Normalizes capability types/states, merch/hospitality/redemption/pickup
 * semantics, and execution descriptors by composing reservation governance,
 * fulfillment operational signals, continuity semantics, and the entitlement
 * capability registry. Does not mutate inventory, execute fulfillment, or
 * bypass scanner authority.
 */
final class OperationalEntitlementCapabilityManager {

  public const TYPE_ADMISSION = 'admission';
  public const TYPE_MERCH_PICKUP = 'merch_pickup';
  public const TYPE_HOSPITALITY_ACCESS = 'hospitality_access';
  public const TYPE_FOOD_DRINK_REDEMPTION = 'food_drink_redemption';
  public const TYPE_PARKING_ACCESS = 'parking_access';
  public const TYPE_VIP_ACCESS = 'vip_access';
  public const TYPE_CLOAKROOM_RETRIEVAL = 'cloakroom_retrieval';
  public const TYPE_TIMED_COLLECTION = 'timed_collection';
  public const TYPE_DIGITAL_REDEMPTION = 'digital_redemption';

  public const STATE_UNAVAILABLE = 'unavailable';
  public const STATE_AVAILABLE = 'available';
  public const STATE_RESERVED = 'reserved';
  public const STATE_PREPARED = 'prepared';
  public const STATE_READY = 'ready';
  public const STATE_PARTIALLY_READY = 'partially_ready';
  public const STATE_DEGRADED = 'degraded';
  public const STATE_EXHAUSTED = 'exhausted';
  public const STATE_REDEEMED = 'redeemed';
  public const STATE_FULFILLED = 'fulfilled';
  public const STATE_EXPIRED = 'expired';
  public const STATE_CANCELLED = 'cancelled';

  /**
   * Canonical capability ordering for audit continuity (low → progressed).
   *
   * @var list<string>
   */
  private const CAPABILITY_ORDER = [
    self::STATE_UNAVAILABLE,
    self::STATE_AVAILABLE,
    self::STATE_RESERVED,
    self::STATE_PREPARED,
    self::STATE_READY,
    self::STATE_PARTIALLY_READY,
    self::STATE_DEGRADED,
    self::STATE_EXHAUSTED,
    self::STATE_REDEEMED,
    self::STATE_FULFILLED,
    self::STATE_EXPIRED,
    self::STATE_CANCELLED,
  ];

  public function __construct(
    private readonly EntitlementCapabilityRegistry $entitlementCapabilityRegistry,
    private readonly InventoryReservationGovernanceManager $inventoryReservationGovernanceManager,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Builds a single normalized capability read-model for workspace layers.
   *
   * @param array<string, mixed> $merged
   *   Output shape compatible with
   *   {@see OperationalWorkspaceBuilder::mergeInspections()}.
   *
   * @return array<string, mixed>
   */
  public function composeCapabilityReadModel(array $merged, int $requestTime): array {
    $reservation_model = $this->inventoryReservationGovernanceManager->composeReservationReadModel($merged, $requestTime);
    $reservation_rows = is_array($reservation_model['ticket_projections'] ?? NULL)
      ? $reservation_model['ticket_projections']
      : [];
    $signals = is_array($merged['fulfillment_signals'] ?? NULL) ? $merged['fulfillment_signals'] : [];
    $by_ticket = is_array($signals['by_ticket_id'] ?? NULL) ? $signals['by_ticket_id'] : [];
    $artifacts = is_array($merged['artifacts'] ?? NULL) ? $merged['artifacts'] : [];
    $continuity = is_array($artifacts['operational_continuity'] ?? NULL) ? $artifacts['operational_continuity'] : [];
    $timed = is_array($artifacts['timed_entry_policy'] ?? NULL) ? $artifacts['timed_entry_policy'] : [];

    $state_tally = array_fill_keys(self::CAPABILITY_ORDER, 0);
    $type_tally = [];
    foreach ($this->allCanonicalTypes() as $t) {
      $type_tally[$t] = 0;
    }

    $ticket_projections = [];
    foreach ($reservation_rows as $reservation_row) {
      if (!is_array($reservation_row)) {
        continue;
      }
      $ticket_id = (string) ($reservation_row['ticket_ordinal'] ?? '');
      $signal_row = is_array($by_ticket[$ticket_id] ?? NULL) ? $by_ticket[$ticket_id] : [];
      $entitlement = $this->entitlementCapabilityRegistry->normalizeEntitlementType(
        (string) ($reservation_row['entitlement_type'] ?? ($signal_row['entitlement_type'] ?? ''))
      );
      $cap = $this->entitlementCapabilityRegistry->getCapabilityMap($entitlement);

      $continuity_row = is_array($continuity[$ticket_id] ?? NULL) ? $continuity[$ticket_id] : [];
      $continuity_mode = (string) (($continuity_row['continuity_summary']['continuity_mode'] ?? '') ?: '');
      $timing_row = is_array($timed[$ticket_id] ?? NULL) ? $timed[$ticket_id] : [];
      $timing_policy = is_array($timing_row['policy'] ?? NULL) ? $timing_row['policy'] : [];
      $timed_scanner_state = (string) (($timing_policy['scanner']['state'] ?? '') ?: '');

      $capability_type = $this->mapEntitlementToCapabilityType(
        $entitlement,
        (int) ($signal_row['redemption_limit'] ?? ($reservation_row['redemption_limit'] ?? 1)),
        $timed_scanner_state,
        (string) ($reservation_row['reservation_type'] ?? ''),
      );
      $type_tally[$capability_type] = ($type_tally[$capability_type] ?? 0) + 1;

      $reservation_state = (string) ($reservation_row['normalized_reservation_state'] ?? '');
      $normalized = $this->normalizeCapabilityStateFromComposition(
        $reservation_state,
        $signal_row,
        $cap,
        $continuity_mode,
      );
      $state_tally[$normalized] = ($state_tally[$normalized] ?? 0) + 1;

      $execution_descriptor = $this->normalizeExecutionDescriptor($cap, $capability_type, $normalized);
      $fulfillment_summary = $this->buildFulfillmentCapabilitySummary($signal_row, $cap, $normalized);
      $reservation_summary = $this->buildReservationCapabilitySummary($reservation_row, $normalized);

      $ticket_projections[] = [
        'ticket_ordinal' => (int) ($reservation_row['ticket_ordinal'] ?? 0),
        'entitlement_type' => $entitlement,
        'capability_type' => $capability_type,
        'normalized_capability_state' => $normalized,
        'execution_descriptor' => $execution_descriptor,
        'scanner_visibility' => $this->buildScannerVisibility($cap),
        'fulfillment_capability_summary' => $fulfillment_summary,
        'reservation_capability_summary' => $reservation_summary,
        'readiness_summary' => $this->buildReadinessSummary($normalized, $cap, $fulfillment_summary),
        'continuity_summary' => $this->buildContinuitySummary($continuity_mode, $normalized),
        'degradation_lane' => (string) ($reservation_row['degradation_lane'] ?? 'nominal'),
      ];
    }

    usort($ticket_projections, static function (array $a, array $b): int {
      return ($a['ticket_ordinal'] ?? 0) <=> ($b['ticket_ordinal'] ?? 0);
    });

    $reservation_rollup = is_array($reservation_model['rollup'] ?? NULL) ? $reservation_model['rollup'] : [];
    $reservation_readiness = is_array($reservation_model['readiness_projection'] ?? NULL)
      ? $reservation_model['readiness_projection']
      : [];
    $reservation_degradation = is_array($reservation_model['degradation_projection'] ?? NULL)
      ? $reservation_model['degradation_projection']
      : [];
    $reservation_continuity = is_array($reservation_model['continuity_projection'] ?? NULL)
      ? $reservation_model['continuity_projection']
      : [];

    return [
      'request_time' => $requestTime,
      'rollup' => [
        'canonical_capability_state_tally' => $state_tally,
        'capability_type_tally' => $type_tally,
        'sampled_ticket_rows' => count($ticket_projections),
        'rollup_continuity_composition' => $reservation_rollup['rollup_continuity_composition'] ?? [],
        'reservation_state_tally' => $reservation_rollup['canonical_reservation_state_tally'] ?? [],
      ],
      'ticket_projections' => $ticket_projections,
      'readiness_projection' => $this->buildReadinessProjection($state_tally, $ticket_projections, $reservation_readiness),
      'degradation_projection' => $this->buildDegradationProjection($state_tally, $reservation_degradation),
      'continuity_projection' => $this->buildContinuityProjection($ticket_projections, $reservation_continuity),
      'fulfillment_capability_projection' => $this->buildFulfillmentCapabilityProjection($ticket_projections),
      'reservation_capability_projection' => $this->buildReservationCapabilityProjection($reservation_model),
    ];
  }

  /**
   * Normalizes a capability type token.
   */
  public function normalizeCapabilityType(?string $raw): string {
    $token = strtolower(trim((string) $raw));
    if ($token === '') {
      return self::TYPE_DIGITAL_REDEMPTION;
    }
    if (!in_array($token, $this->allCanonicalTypes(), TRUE)) {
      $this->logger->warning('OperationalEntitlementCapabilityManager: unknown capability type @token normalized to digital_redemption.', [
        '@token' => $token,
        'token' => $token,
      ]);
      return self::TYPE_DIGITAL_REDEMPTION;
    }
    return $token;
  }

  /**
   * Normalizes a capability state token.
   */
  public function normalizeCapabilityState(?string $raw): string {
    $token = strtolower(trim((string) $raw));
    if ($token === '') {
      return self::STATE_UNAVAILABLE;
    }
    if (!in_array($token, self::CAPABILITY_ORDER, TRUE)) {
      $this->logger->warning('OperationalEntitlementCapabilityManager: unknown capability state @token normalized to unavailable.', [
        '@token' => $token,
        'token' => $token,
      ]);
      return self::STATE_UNAVAILABLE;
    }
    return $token;
  }

  /**
   * Projects an observational capability state from merged rollup keys only.
   *
   * @param array<string, mixed> $merged
   */
  public function projectCapabilityStateFromRollup(array $merged): string {
    if ($merged === []) {
      return self::STATE_UNAVAILABLE;
    }
    $model = $this->composeCapabilityReadModel($merged, 0);
    $tally = is_array($model['rollup']['canonical_capability_state_tally'] ?? NULL)
      ? $model['rollup']['canonical_capability_state_tally']
      : [];
    foreach (array_reverse(self::CAPABILITY_ORDER) as $state) {
      if (($tally[$state] ?? 0) > 0) {
        return $state;
      }
    }
    return self::STATE_UNAVAILABLE;
  }

  /**
   * Maps stored entitlement types to canonical capability types.
   */
  public function mapEntitlementToCapabilityType(
    string $entitlement_type,
    int $redemption_limit,
    string $timed_scanner_state = '',
    string $reservation_type_hint = '',
  ): string {
    if ($reservation_type_hint !== '') {
      return $this->mapReservationTypeToCapabilityType($reservation_type_hint);
    }
    if ($timed_scanner_state !== '' && $timed_scanner_state !== 'n/a') {
      return self::TYPE_TIMED_COLLECTION;
    }
    $ent = $this->entitlementCapabilityRegistry->normalizeEntitlementType($entitlement_type);
    return match ($ent) {
      Ticket::ENTITLEMENT_TICKET => self::TYPE_ADMISSION,
      Ticket::ENTITLEMENT_MERCH => self::TYPE_MERCH_PICKUP,
      Ticket::ENTITLEMENT_PARKING => self::TYPE_PARKING_ACCESS,
      Ticket::ENTITLEMENT_DRINK, Ticket::ENTITLEMENT_FOOD => self::TYPE_FOOD_DRINK_REDEMPTION,
      Ticket::ENTITLEMENT_VIP => self::TYPE_VIP_ACCESS,
      Ticket::ENTITLEMENT_ADDON => $redemption_limit > 1
        ? self::TYPE_HOSPITALITY_ACCESS
        : self::TYPE_CLOAKROOM_RETRIEVAL,
      default => self::TYPE_DIGITAL_REDEMPTION,
    };
  }

  /**
   * Builds a read-only audit timeline from normalized capability events.
   *
   * @param list<array<string, mixed>> $events
   *
   * @return list<array<string, mixed>>
   */
  public function projectCapabilityAuditTimeline(array $events): array {
    $out = [];
    foreach ($events as $event) {
      if (!is_array($event)) {
        continue;
      }
      $state = $this->normalizeCapabilityState(isset($event['state']) ? (string) $event['state'] : NULL);
      $at = (int) ($event['recorded_at'] ?? 0);
      $note = trim((string) ($event['note'] ?? ''));
      $out[] = [
        'capability_state' => $state,
        'recorded_at_unix' => $at,
        'audit_note' => $note !== '' ? $note : 'capability_governance_event',
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
    return self::CAPABILITY_ORDER;
  }

  /**
   * @return list<string>
   */
  public function allCanonicalTypes(): array {
    return [
      self::TYPE_ADMISSION,
      self::TYPE_MERCH_PICKUP,
      self::TYPE_HOSPITALITY_ACCESS,
      self::TYPE_FOOD_DRINK_REDEMPTION,
      self::TYPE_PARKING_ACCESS,
      self::TYPE_VIP_ACCESS,
      self::TYPE_CLOAKROOM_RETRIEVAL,
      self::TYPE_TIMED_COLLECTION,
      self::TYPE_DIGITAL_REDEMPTION,
    ];
  }

  /**
   * Maps reservation governance type tokens to capability types.
   */
  public function mapReservationTypeToCapabilityType(string $reservation_type): string {
    return match ($this->inventoryReservationGovernanceManager->normalizeReservationType($reservation_type)) {
      InventoryReservationGovernanceManager::TYPE_MERCH => self::TYPE_MERCH_PICKUP,
      InventoryReservationGovernanceManager::TYPE_HOSPITALITY => self::TYPE_HOSPITALITY_ACCESS,
      InventoryReservationGovernanceManager::TYPE_FOOD_DRINK => self::TYPE_FOOD_DRINK_REDEMPTION,
      InventoryReservationGovernanceManager::TYPE_PARKING => self::TYPE_PARKING_ACCESS,
      InventoryReservationGovernanceManager::TYPE_VIP_PACKAGE => self::TYPE_VIP_ACCESS,
      InventoryReservationGovernanceManager::TYPE_CLOAKROOM => self::TYPE_CLOAKROOM_RETRIEVAL,
      InventoryReservationGovernanceManager::TYPE_TIMED_PICKUP => self::TYPE_TIMED_COLLECTION,
      InventoryReservationGovernanceManager::TYPE_EQUIPMENT => self::TYPE_HOSPITALITY_ACCESS,
      default => self::TYPE_DIGITAL_REDEMPTION,
    };
  }

  /**
   * Maps reservation governance state to capability state without re-evaluating rows.
   */
  public function mapReservationStateToCapabilityState(string $reservation_state): string {
    return match ($this->inventoryReservationGovernanceManager->normalizeReservationState($reservation_state)) {
      InventoryReservationGovernanceManager::STATE_UNRESERVED => self::STATE_UNAVAILABLE,
      InventoryReservationGovernanceManager::STATE_RESERVED => self::STATE_RESERVED,
      InventoryReservationGovernanceManager::STATE_PARTIALLY_RESERVED => self::STATE_PARTIALLY_READY,
      InventoryReservationGovernanceManager::STATE_ALLOCATED => self::STATE_PREPARED,
      InventoryReservationGovernanceManager::STATE_PREPARED => self::STATE_PREPARED,
      InventoryReservationGovernanceManager::STATE_READY_FOR_COLLECTION => self::STATE_READY,
      InventoryReservationGovernanceManager::STATE_DEGRADED => self::STATE_DEGRADED,
      InventoryReservationGovernanceManager::STATE_EXHAUSTED => self::STATE_EXHAUSTED,
      InventoryReservationGovernanceManager::STATE_FULFILLED => self::STATE_FULFILLED,
      InventoryReservationGovernanceManager::STATE_RELEASED => self::STATE_AVAILABLE,
      InventoryReservationGovernanceManager::STATE_EXPIRED => self::STATE_EXPIRED,
      InventoryReservationGovernanceManager::STATE_CANCELLED => self::STATE_CANCELLED,
      default => self::STATE_UNAVAILABLE,
    };
  }

  /**
   * @param array<string, mixed> $row
   * @param array<string, mixed> $cap
   */
  public function normalizeCapabilityStateFromComposition(
    string $reservation_state,
    array $row,
    array $cap,
    string $continuity_mode,
  ): string {
    $fulfilment = (string) ($row['fulfilment_status'] ?? '');
    if ($fulfilment === Ticket::FULFILMENT_REDEEMED) {
      return self::STATE_REDEEMED;
    }
    if ($continuity_mode === 'degraded' && $reservation_state === InventoryReservationGovernanceManager::STATE_DEGRADED) {
      return self::STATE_DEGRADED;
    }
    $base = $this->mapReservationStateToCapabilityState($reservation_state);
    if ($base === self::STATE_UNAVAILABLE && (bool) ($cap['redeemable'] ?? FALSE)) {
      return self::STATE_AVAILABLE;
    }
    return $base;
  }

  /**
   * @param array<string, mixed> $cap
   */
  public function normalizeExecutionDescriptor(array $cap, string $capability_type, string $capability_state): string {
    $scanner = (string) ($cap['scanner_mode'] ?? '');
    $fulfilment_mode = (string) ($cap['fulfilment_mode'] ?? 'none');
    return 'capability_type=' . $capability_type
      . '; state=' . $capability_state
      . '; scanner_action=' . ($scanner !== '' ? $scanner : 'n/a')
      . '; fulfilment_mode=' . $fulfilment_mode
      . '; scan_required=' . ((bool) ($cap['scan_required'] ?? FALSE) ? 'yes' : 'no');
  }

  /**
   * @param array<string, mixed> $row
   * @param array<string, mixed> $cap
   */
  private function buildFulfillmentCapabilitySummary(array $row, array $cap, string $normalized): string {
    $fulfilment = (string) ($row['fulfilment_status'] ?? '');
    return 'fulfilment_status=' . ($fulfilment !== '' ? $fulfilment : 'n/a')
      . '; capability_state=' . $normalized
      . '; requires_fulfilment=' . ((bool) ($cap['requires_fulfilment'] ?? FALSE) ? 'yes' : 'no')
      . '; supports_collection=' . ((bool) ($cap['supports_collection'] ?? FALSE) ? 'yes' : 'no');
  }

  /**
   * @param array<string, mixed> $reservation_row
   */
  private function buildReservationCapabilitySummary(array $reservation_row, string $normalized): string {
    return 'reservation_state=' . (string) ($reservation_row['normalized_reservation_state'] ?? '')
      . '; capability_state=' . $normalized
      . '; reservation_type=' . (string) ($reservation_row['reservation_type'] ?? '');
  }

  /**
   * @param array<string, mixed> $cap
   */
  private function buildScannerVisibility(array $cap): string {
    return 'scanner_action=' . (string) ($cap['scanner_mode'] ?? 'n/a')
      . '; redeemable=' . ((bool) ($cap['redeemable'] ?? FALSE) ? 'yes' : 'no')
      . '; multi_use=' . ((bool) ($cap['multi_use'] ?? FALSE) ? 'yes' : 'no');
  }

  private function buildReadinessSummary(string $normalized, array $cap, string $fulfillment_summary): string {
    return 'capability_state=' . $normalized
      . '; redeemable=' . ((bool) ($cap['redeemable'] ?? FALSE) ? 'yes' : 'no')
      . '; ' . $fulfillment_summary;
  }

  private function buildContinuitySummary(string $continuity_mode, string $normalized): string {
    return 'continuity_mode=' . ($continuity_mode !== '' ? $continuity_mode : 'n/a')
      . '; capability_state=' . $normalized;
  }

  /**
   * @param array<string, int> $state_tally
   * @param list<array<string, mixed>> $ticket_projections
   * @param array<string, mixed> $reservation_readiness
   *
   * @return array<string, mixed>
   */
  private function buildReadinessProjection(
    array $state_tally,
    array $ticket_projections,
    array $reservation_readiness,
  ): array {
    $readyish = ($state_tally[self::STATE_PREPARED] ?? 0)
      + ($state_tally[self::STATE_READY] ?? 0)
      + ($state_tally[self::STATE_PARTIALLY_READY] ?? 0)
      + ($state_tally[self::STATE_AVAILABLE] ?? 0);
    return [
      'descriptor' => 'capability_readiness_visibility',
      'execution_ready_rows' => $readyish,
      'collection_ready_rows' => (int) ($state_tally[self::STATE_READY] ?? 0),
      'reservation_readiness_signal' => (string) ($reservation_readiness['descriptor'] ?? ''),
      'timed_collection_ready_rows' => $this->countTimedCollectionReady($ticket_projections),
      'merch_pickup_ready_rows' => $this->countTypeReady($ticket_projections, self::TYPE_MERCH_PICKUP),
      'hospitality_ready_rows' => $this->countTypeReady($ticket_projections, self::TYPE_HOSPITALITY_ACCESS),
    ];
  }

  /**
   * @param array<string, int> $state_tally
   * @param array<string, mixed> $reservation_degradation
   *
   * @return array<string, mixed>
   */
  private function buildDegradationProjection(array $state_tally, array $reservation_degradation): array {
    $parts = [];
    $res_signals = is_array($reservation_degradation['signals'] ?? NULL) ? $reservation_degradation['signals'] : [];
    foreach ($res_signals as $signal) {
      $parts[] = (string) $signal;
    }
    if (($state_tally[self::STATE_DEGRADED] ?? 0) > 0) {
      $parts[] = 'normalized_degraded_capability_rows';
    }
    return [
      'descriptor' => $parts === [] ? 'nominal' : implode(';', $parts),
      'signals' => $parts,
    ];
  }

  /**
   * @param list<array<string, mixed>> $ticket_projections
   * @param array<string, mixed> $reservation_continuity
   *
   * @return array<string, mixed>
   */
  private function buildContinuityProjection(array $ticket_projections, array $reservation_continuity): array {
    return [
      'descriptor' => 'capability_continuity_visibility',
      'guest_continuity_statuses' => $reservation_continuity['guest_continuity_statuses'] ?? [],
      'continuity_row_count' => count($ticket_projections),
      'reservation_continuity_signal' => (string) ($reservation_continuity['descriptor'] ?? ''),
    ];
  }

  /**
   * @param list<array<string, mixed>> $ticket_projections
   *
   * @return array<string, mixed>
   */
  private function buildFulfillmentCapabilityProjection(array $ticket_projections): array {
    $collect = 0;
    $redeem = 0;
    foreach ($ticket_projections as $row) {
      if (!is_array($row)) {
        continue;
      }
      $summary = (string) ($row['fulfillment_capability_summary'] ?? '');
      if (str_contains($summary, 'requires_fulfilment=yes')) {
        $collect++;
      }
      if (str_contains($summary, 'fulfilment_status=' . Ticket::FULFILMENT_REDEEMED)) {
        $redeem++;
      }
    }
    return [
      'descriptor' => 'fulfillment_capability_visibility',
      'fulfilment_collect_rows' => $collect,
      'fulfilment_redeemed_rows' => $redeem,
    ];
  }

  /**
   * @param array<string, mixed> $reservation_model
   *
   * @return array<string, mixed>
   */
  private function buildReservationCapabilityProjection(array $reservation_model): array {
    $rollup = is_array($reservation_model['rollup'] ?? NULL) ? $reservation_model['rollup'] : [];
    $allocation = is_array($reservation_model['allocation_projection'] ?? NULL)
      ? $reservation_model['allocation_projection']
      : [];
    return [
      'descriptor' => 'reservation_capability_visibility',
      'reservation_type_tally' => $rollup['reservation_type_tally'] ?? [],
      'allocated_fraction' => (float) ($allocation['allocated_fraction'] ?? 0.0),
    ];
  }

  /**
   * @param list<array<string, mixed>> $ticket_projections
   */
  private function countTimedCollectionReady(array $ticket_projections): int {
    return $this->countTypeReady($ticket_projections, self::TYPE_TIMED_COLLECTION);
  }

  /**
   * @param list<array<string, mixed>> $ticket_projections
   */
  private function countTypeReady(array $ticket_projections, string $capability_type): int {
    $n = 0;
    foreach ($ticket_projections as $row) {
      if (!is_array($row)) {
        continue;
      }
      if (($row['capability_type'] ?? '') !== $capability_type) {
        continue;
      }
      $state = (string) ($row['normalized_capability_state'] ?? '');
      if (in_array($state, [self::STATE_PREPARED, self::STATE_READY, self::STATE_PARTIALLY_READY], TRUE)) {
        $n++;
      }
    }
    return $n;
  }

}
