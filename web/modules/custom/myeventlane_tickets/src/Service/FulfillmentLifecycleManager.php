<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\myeventlane_tickets\Entity\Ticket;

/**
 * Canonical fulfillment lifecycle normalization (read-model only).
 *
 * Composes projections from merged inspector rollups, entitlement capability
 * semantics, and per-ticket operational signals. Does not execute scans,
 * mutate entitlements, or drive orchestration.
 */
final class FulfillmentLifecycleManager {

  public const STATE_PENDING = 'pending';
  public const STATE_PREPARED = 'prepared';
  public const STATE_READY = 'ready';
  public const STATE_PARTIALLY_FULFILLED = 'partially_fulfilled';
  public const STATE_FULFILLED = 'fulfilled';
  public const STATE_COLLECTED = 'collected';
  public const STATE_CONSUMED = 'consumed';
  public const STATE_EXPIRED = 'expired';
  public const STATE_FAILED = 'failed';
  public const STATE_CANCELLED = 'cancelled';

  public const TYPE_ADMISSION = 'admission';
  public const TYPE_MERCH_PICKUP = 'merch_pickup';
  public const TYPE_HOSPITALITY = 'hospitality';
  public const TYPE_PARKING = 'parking';
  public const TYPE_VIP_ACCESS = 'vip_access';
  public const TYPE_EQUIPMENT = 'equipment';
  public const TYPE_CLOAKROOM = 'cloakroom';
  public const TYPE_MULTI_REDEEM = 'multi_redeem';
  public const TYPE_DIGITAL_ACCESS = 'digital_access';

  /**
   * Canonical lifecycle ordering for audit continuity (low → progressed).
   *
   * @var list<string>
   */
  private const LIFECYCLE_ORDER = [
    self::STATE_PENDING,
    self::STATE_PREPARED,
    self::STATE_READY,
    self::STATE_PARTIALLY_FULFILLED,
    self::STATE_FULFILLED,
    self::STATE_COLLECTED,
    self::STATE_CONSUMED,
    self::STATE_EXPIRED,
    self::STATE_FAILED,
    self::STATE_CANCELLED,
  ];

  public function __construct(
    private readonly EntitlementCapabilityRegistry $entitlementCapabilityRegistry,
  ) {}

  /**
   * Builds a single normalized fulfillment read-model for workspace layers.
   *
   * @param array<string, mixed> $merged
   *   Output shape compatible with
   *   {@see OperationalWorkspaceBuilder::mergeInspections()}.
   *
   * @return array<string, mixed>
   */
  public function composeFulfillmentReadModel(array $merged, int $requestTime): array {
    $signals = is_array($merged['fulfillment_signals'] ?? NULL) ? $merged['fulfillment_signals'] : [];
    $by_ticket = is_array($signals['by_ticket_id'] ?? NULL) ? $signals['by_ticket_id'] : [];
    $artifacts = is_array($merged['artifacts'] ?? NULL) ? $merged['artifacts'] : [];
    $continuity = is_array($artifacts['operational_continuity'] ?? NULL) ? $artifacts['operational_continuity'] : [];
    $issuance = is_array($merged['issuance'] ?? NULL) ? $merged['issuance'] : [];
    $guest = is_array($merged['guest_continuity'] ?? NULL) ? $merged['guest_continuity'] : [];
    $recovery = is_array($merged['recovery'] ?? NULL) ? $merged['recovery'] : [];
    $canonical_pdf = (string) ($artifacts['canonical_pdf_readiness'] ?? 'missing');

    $rollup_failed = ((string) ($issuance['worst_quantity_alignment_status'] ?? '')) === 'invalid'
      || !empty($recovery['recovery_mismatch_observed']);

    $ticket_projections = [];
    $state_tally = array_fill_keys(self::LIFECYCLE_ORDER, 0);
    $type_tally = [];
    foreach (self::allFulfillmentTypes() as $t) {
      $type_tally[$t] = 0;
    }

    foreach ($by_ticket as $ticket_id => $row) {
      if (!is_array($row)) {
        continue;
      }
      $entitlement = (string) ($row['entitlement_type'] ?? '');
      $entitlement = $this->entitlementCapabilityRegistry->normalizeEntitlementType($entitlement);
      $cap = $this->entitlementCapabilityRegistry->getCapabilityMap($entitlement);
      $fulfillment_type = $this->mapEntitlementToFulfillmentType(
        $entitlement,
        (int) ($row['redemption_limit'] ?? 1),
      );
      $type_tally[$fulfillment_type] = ($type_tally[$fulfillment_type] ?? 0) + 1;

      $continuity_row = is_array($continuity[(string) $ticket_id] ?? NULL)
        ? $continuity[(string) $ticket_id]
        : [];
      $timing_refs = is_array($continuity_row['timing_session_composition_refs'] ?? NULL)
        ? $continuity_row['timing_session_composition_refs']
        : [];

      $normalized = $this->normalizeTicketLifecycleState(
        $row,
        $cap,
        $rollup_failed,
        $canonical_pdf,
        $fulfillment_type,
      );

      $state_tally[$normalized] = ($state_tally[$normalized] ?? 0) + 1;

      $redemption_summary = $this->buildRedemptionExecutionSummary(
        $row,
        $cap,
        (string) ($timing_refs['timed_scanner_state'] ?? ''),
        (string) ($timing_refs['session_scanner_state'] ?? ''),
      );
      $pickup_summary = $this->buildPickupLifecycleSummary($row, $cap, $normalized);

      $ticket_projections[] = [
        'ticket_ordinal' => (int) $ticket_id,
        'entitlement_type' => $entitlement,
        'fulfillment_type' => $fulfillment_type,
        'normalized_lifecycle_state' => $normalized,
        'redemption_execution_summary' => $redemption_summary,
        'pickup_lifecycle_summary' => $pickup_summary,
        'collection_semantics' => $this->collectionSemanticsDescriptor($cap, $normalized),
        'consumption_semantics' => $this->consumptionSemanticsDescriptor($cap, $row, $normalized),
        'partial_fulfillment' => $this->isPartialFulfillment($row, $cap),
        'readiness_lane' => $this->readinessLaneDescriptor($normalized, $cap, $canonical_pdf),
      ];
    }

    usort($ticket_projections, static function (array $a, array $b): int {
      return ($a['ticket_ordinal'] ?? 0) <=> ($b['ticket_ordinal'] ?? 0);
    });

    return [
      'request_time' => $requestTime,
      'rollup' => [
        'canonical_lifecycle_state_tally' => $state_tally,
        'fulfillment_type_tally' => $type_tally,
        'sampled_ticket_rows' => count($ticket_projections),
        'partial_fulfillment_rows' => $this->countPartialRows($ticket_projections),
        'rollup_continuity_composition' => [
          'guest_continuity_statuses' => $guest['continuity_statuses_observed'] ?? [],
          'worst_quantity_alignment_status' => (string) ($issuance['worst_quantity_alignment_status'] ?? ''),
          'canonical_pdf_readiness' => $canonical_pdf,
        ],
      ],
      'ticket_projections' => $ticket_projections,
      'completion_projection' => $this->buildCompletionProjection($state_tally, count($ticket_projections)),
      'readiness_projection' => $this->buildReadinessProjection($state_tally, $ticket_projections, $canonical_pdf),
      'degradation_projection' => $this->buildDegradationProjection($rollup_failed, $state_tally, $canonical_pdf),
    ];
  }

  /**
   * @return list<string>
   */
  public function allCanonicalStates(): array {
    return self::LIFECYCLE_ORDER;
  }

  /**
   * @return list<string>
   */
  public function allFulfillmentTypes(): array {
    return [
      self::TYPE_ADMISSION,
      self::TYPE_MERCH_PICKUP,
      self::TYPE_HOSPITALITY,
      self::TYPE_PARKING,
      self::TYPE_VIP_ACCESS,
      self::TYPE_EQUIPMENT,
      self::TYPE_CLOAKROOM,
      self::TYPE_MULTI_REDEEM,
      self::TYPE_DIGITAL_ACCESS,
    ];
  }

  /**
   * @param array<string, mixed> $row
   * @param array<string, mixed> $cap
   */
  public function normalizeTicketLifecycleState(
    array $row,
    array $cap,
    bool $rollup_failed,
    string $canonical_pdf_readiness,
    string $fulfillment_type = '',
  ): string {
    $fulfilment = (string) ($row['fulfilment_status'] ?? '');
    $ticket_status = (string) ($row['ticket_status'] ?? '');
    $admission_in = !empty($row['admission_checked_in']);
    $count = (int) ($row['redemption_count'] ?? 0);
    $limit = max(0, (int) ($row['redemption_limit'] ?? 0));
    $redeemable = (bool) ($cap['redeemable'] ?? FALSE);
    $multi = (bool) ($cap['multi_use'] ?? FALSE);
    $requires_fulfilment = (bool) ($cap['requires_fulfilment'] ?? FALSE);

    if ($rollup_failed) {
      return self::STATE_FAILED;
    }

    if (!in_array($fulfilment, [
      Ticket::FULFILMENT_PENDING,
      Ticket::FULFILMENT_READY,
      Ticket::FULFILMENT_COLLECTED,
      Ticket::FULFILMENT_REDEEMED,
      Ticket::FULFILMENT_EXPIRED,
      Ticket::FULFILMENT_CANCELLED,
    ], TRUE)) {
      return self::STATE_PENDING;
    }

    if ($fulfilment === Ticket::FULFILMENT_CANCELLED || $ticket_status === Ticket::STATUS_VOID) {
      return self::STATE_CANCELLED;
    }
    if ($ticket_status === Ticket::STATUS_REFUNDED) {
      return self::STATE_CANCELLED;
    }
    if ($fulfilment === Ticket::FULFILMENT_EXPIRED) {
      return self::STATE_EXPIRED;
    }

    if ($fulfilment === Ticket::FULFILMENT_REDEEMED) {
      return self::STATE_CONSUMED;
    }
    if ($fulfilment === Ticket::FULFILMENT_COLLECTED) {
      return self::STATE_COLLECTED;
    }
    if ($fulfilment === Ticket::FULFILMENT_READY) {
      return self::STATE_READY;
    }

    if ($redeemable && $multi && $limit > 1 && $count > 0 && $count < $limit) {
      return self::STATE_PARTIALLY_FULFILLED;
    }

    $entitlement = $this->entitlementCapabilityRegistry->normalizeEntitlementType((string) ($row['entitlement_type'] ?? ''));
    if ($this->entitlementCapabilityRegistry->isAdmissionEntitlementType($entitlement) && $admission_in) {
      return self::STATE_FULFILLED;
    }

    if ($fulfilment === Ticket::FULFILMENT_PENDING) {
      if ($requires_fulfilment && $canonical_pdf_readiness === 'valid' && $fulfillment_type === self::TYPE_MERCH_PICKUP) {
        return self::STATE_PREPARED;
      }
      return self::STATE_PENDING;
    }

    return self::STATE_PENDING;
  }

  /**
   * Maps stored entitlement types to canonical fulfillment types (prep semantics).
   */
  public function mapEntitlementToFulfillmentType(string $entitlement_type, int $redemption_limit): string {
    $ent = $this->entitlementCapabilityRegistry->normalizeEntitlementType($entitlement_type);
    return match ($ent) {
      Ticket::ENTITLEMENT_TICKET => self::TYPE_ADMISSION,
      Ticket::ENTITLEMENT_MERCH => self::TYPE_MERCH_PICKUP,
      Ticket::ENTITLEMENT_PARKING => self::TYPE_PARKING,
      Ticket::ENTITLEMENT_DRINK, Ticket::ENTITLEMENT_FOOD => self::TYPE_HOSPITALITY,
      Ticket::ENTITLEMENT_VIP => self::TYPE_VIP_ACCESS,
      Ticket::ENTITLEMENT_ADDON => $redemption_limit > 1 ? self::TYPE_MULTI_REDEEM : self::TYPE_EQUIPMENT,
      default => self::TYPE_DIGITAL_ACCESS,
    };
  }

  /**
   * @param array<string, mixed> $row
   * @param array<string, mixed> $cap
   */
  public function isPartialFulfillment(array $row, array $cap): bool {
    $count = (int) ($row['redemption_count'] ?? 0);
    $limit = max(0, (int) ($row['redemption_limit'] ?? 0));
    $redeemable = (bool) ($cap['redeemable'] ?? FALSE);
    $multi = (bool) ($cap['multi_use'] ?? FALSE);
    return $redeemable && $multi && $limit > 1 && $count > 0 && $count < $limit;
  }

  /**
   * @param list<array<string, mixed>> $ticket_projections
   */
  private function countPartialRows(array $ticket_projections): int {
    $n = 0;
    foreach ($ticket_projections as $p) {
      if (!empty($p['partial_fulfillment'])) {
        $n++;
      }
    }
    return $n;
  }

  /**
   * @param array<string, int> $state_tally
   *
   * @return array<string, mixed>
   */
  private function buildCompletionProjection(array $state_tally, int $sampled): array {
    if ($sampled < 1) {
      return [
        'descriptor' => 'no_sampled_ticket_rows',
        'fulfilled_or_beyond_fraction' => 0.0,
      ];
    }
    $terminal = ($state_tally[self::STATE_FULFILLED] ?? 0)
      + ($state_tally[self::STATE_COLLECTED] ?? 0)
      + ($state_tally[self::STATE_CONSUMED] ?? 0);
    return [
      'descriptor' => 'fulfillment_completion_visibility',
      'fulfilled_or_beyond_fraction' => round($terminal / $sampled, 4),
      'terminal_rows' => $terminal,
    ];
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
      + ($state_tally[self::STATE_READY] ?? 0)
      + ($state_tally[self::STATE_PARTIALLY_FULFILLED] ?? 0);
    return [
      'descriptor' => 'fulfillment_readiness_visibility',
      'pickup_ready_rows' => (int) ($state_tally[self::STATE_READY] ?? 0),
      'execution_ready_rows' => $readyish,
      'artifact_readiness_signal' => $canonical_pdf_readiness,
      'blocked_rows' => ($state_tally[self::STATE_FAILED] ?? 0) + ($state_tally[self::STATE_EXPIRED] ?? 0),
    ];
  }

  /**
   * @param array<string, int> $state_tally
   *
   * @return array<string, mixed>
   */
  private function buildDegradationProjection(
    bool $rollup_failed,
    array $state_tally,
    string $canonical_pdf_readiness,
  ): array {
    $parts = [];
    if ($rollup_failed) {
      $parts[] = 'issuance_or_recovery_rollup_failed';
    }
    if (($state_tally[self::STATE_FAILED] ?? 0) > 0) {
      $parts[] = 'normalized_failed_rows';
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
   * @param array<string, mixed> $cap
   */
  private function collectionSemanticsDescriptor(array $cap, string $normalized): string {
    $supports = (bool) ($cap['supports_collection'] ?? FALSE);
    if (!$supports) {
      return 'collection_not_applicable';
    }
    return match ($normalized) {
      self::STATE_COLLECTED, self::STATE_CONSUMED => 'collection_complete_visibility',
      self::STATE_READY, self::STATE_PREPARED => 'collection_ready_visibility',
      default => 'collection_pending_visibility',
    };
  }

  /**
   * @param array<string, mixed> $row
   * @param array<string, mixed> $cap
   */
  private function consumptionSemanticsDescriptor(array $cap, array $row, string $normalized): string {
    if (!(bool) ($cap['redeemable'] ?? FALSE)) {
      return 'consumption_not_redeemable_visibility';
    }
    if ($normalized === self::STATE_CONSUMED) {
      return 'consumption_complete_visibility';
    }
    if ($this->isPartialFulfillment($row, $cap)) {
      return 'consumption_partial_visibility';
    }
    return 'consumption_pending_visibility';
  }

  /**
   * @param array<string, mixed> $row
   * @param array<string, mixed> $cap
   */
  private function buildRedemptionExecutionSummary(
    array $row,
    array $cap,
    string $timed_scanner_state,
    string $session_scanner_state,
  ): string {
    $action = $this->entitlementCapabilityRegistry->getScannerOperationAction((string) ($row['entitlement_type'] ?? ''));
    $pieces = [
      'scanner_action=' . $action,
      'timed=' . ($timed_scanner_state !== '' ? $timed_scanner_state : 'n/a'),
      'session=' . ($session_scanner_state !== '' ? $session_scanner_state : 'n/a'),
      'redemptions=' . (string) (int) ($row['redemption_count'] ?? 0) . '/' . (string) (int) ($row['redemption_limit'] ?? 0),
    ];
    if (!(bool) ($cap['redeemable'] ?? FALSE)) {
      $pieces[] = 'redemption_lane=validation_only';
    }
    else {
      $pieces[] = 'redemption_lane=redeemable';
    }
    return implode('; ', $pieces);
  }

  /**
   * @param array<string, mixed> $row
   * @param array<string, mixed> $cap
   */
  private function buildPickupLifecycleSummary(array $row, array $cap, string $normalized): string {
    if (!(bool) ($cap['requires_fulfilment'] ?? FALSE)) {
      return 'pickup_lane=not_required';
    }
    return 'pickup_lane=required; state=' . $normalized . '; fulfilment_row=' . (string) ($row['fulfilment_status'] ?? '');
  }

  /**
   * @param array<string, mixed> $cap
   */
  private function readinessLaneDescriptor(string $normalized, array $cap, string $canonical_pdf_readiness): string {
    return 'lifecycle=' . $normalized . '; requires_fulfilment=' . ((bool) ($cap['requires_fulfilment'] ?? FALSE) ? 'yes' : 'no') . '; pdf=' . $canonical_pdf_readiness;
  }

}
