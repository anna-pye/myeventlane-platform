<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

/**
 * Inventory reservation audit visibility (ordering and continuity only).
 *
 * Emits normalized strings and deterministic ordering anchors — no replay
 * tokens, QR payloads, device fingerprints, or customer identifiers.
 */
final class InventoryReservationAuditProjector {

  public function __construct(
    private readonly InventoryReservationGovernanceManager $inventoryReservationGovernanceManager,
  ) {}

  /**
   * @param array<string, mixed> $merged
   *
   * @return list<array<string, mixed>>
   */
  public function buildWorkspaceReservationAuditSections(array $merged, int $requestTime): array {
    $model = $this->inventoryReservationGovernanceManager->composeReservationReadModel($merged, $requestTime);
    $rollup = is_array($model['rollup'] ?? NULL) ? $model['rollup'] : [];
    $tally = is_array($rollup['canonical_reservation_state_tally'] ?? NULL) ? $rollup['canonical_reservation_state_tally'] : [];
    $degraded = (int) ($tally[InventoryReservationGovernanceManager::STATE_DEGRADED] ?? 0);
    $tone = $degraded > 0 ? 'elevated' : 'low';

    $timeline = $this->buildReservationOrderingTimeline($model, $requestTime);
    $partial_note = $this->partialAllocationContinuityNote($model);
    $degradation_ordering = $this->degradationOrderingSummary($model);

    return [
      [
        'id' => 'reservation_lifecycle_audit_timeline',
        'title' => 'Reservation lifecycle audit timeline',
        'section_tone' => $tone,
        'cards' => [
          [
            'label' => 'Reservation transition ordering',
            'value' => $this->transitionOrderingSummary($model),
          ],
          [
            'label' => 'Allocation audit summary',
            'value' => $this->allocationAuditSummary($model),
          ],
          [
            'label' => 'Reservation degradation ordering',
            'value' => $degradation_ordering,
          ],
          [
            'label' => 'Partial allocation continuity',
            'value' => $partial_note,
          ],
          [
            'label' => 'Reservation continuity summary',
            'value' => $this->continuityAuditSummary($model),
          ],
        ],
        'timeline' => $timeline,
      ],
    ];
  }

  /**
   * @param array<string, mixed> $model
   *
   * @return list<array<string, mixed>>
   */
  private function buildReservationOrderingTimeline(array $model, int $requestTime): array {
    $rows = is_array($model['ticket_projections'] ?? NULL) ? $model['ticket_projections'] : [];
    $out = [];
    $seq = 0;
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $seq++;
      $state = (string) ($row['normalized_reservation_state'] ?? '');
      $type = (string) ($row['reservation_type'] ?? '');
      $partial = !empty($row['partial_allocation']) ? 'yes' : 'no';
      $out[] = [
        'lane_state' => $state,
        'recorded_at_unix' => $requestTime + $seq,
        'audit_note' => 'reservation_type=' . $type . '; partial=' . $partial . '; allocation=' . (string) ($row['allocation_summary'] ?? '') . '; continuity=' . (string) ($row['continuity_summary'] ?? ''),
      ];
    }
    return $out;
  }

  /**
   * @param array<string, mixed> $model
   */
  private function transitionOrderingSummary(array $model): string {
    $rows = is_array($model['ticket_projections'] ?? NULL) ? $model['ticket_projections'] : [];
    $ordered = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $ordered[] = (string) ($row['normalized_reservation_state'] ?? '');
    }
    return $ordered === [] ? 'no_rows' : implode(' → ', $ordered);
  }

  /**
   * @param array<string, mixed> $model
   */
  private function allocationAuditSummary(array $model): string {
    $parts = [];
    $rows = is_array($model['ticket_projections'] ?? NULL) ? $model['ticket_projections'] : [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $parts[] = (string) ($row['allocation_summary'] ?? '');
    }
    return $parts === [] ? 'no_allocation_audit_rows' : implode(' | ', $parts);
  }

  /**
   * @param array<string, mixed> $model
   */
  private function degradationOrderingSummary(array $model): string {
    $degradation = is_array($model['degradation_projection'] ?? NULL) ? $model['degradation_projection'] : [];
    $signals = is_array($degradation['signals'] ?? NULL) ? $degradation['signals'] : [];
    if ($signals === []) {
      return 'nominal';
    }
    return implode(' → ', $signals);
  }

  /**
   * @param array<string, mixed> $model
   */
  private function partialAllocationContinuityNote(array $model): string {
    $n = (int) (($model['rollup'] ?? [])['partial_allocation_rows'] ?? 0);
    if ($n < 1) {
      return 'no_partial_allocation_rows_observed';
    }
    return 'partial_allocation_rows=' . (string) $n . ' (multi-redeem reservation semantics)';
  }

  /**
   * @param array<string, mixed> $model
   */
  private function continuityAuditSummary(array $model): string {
    $continuity = is_array($model['continuity_projection'] ?? NULL) ? $model['continuity_projection'] : [];
    $statuses = is_array($continuity['guest_continuity_statuses'] ?? NULL) ? $continuity['guest_continuity_statuses'] : [];
    return 'guest_continuity=' . ($statuses === [] ? 'n/a' : implode(',', $statuses))
      . '; continuity_rows=' . (string) (int) ($continuity['continuity_row_count'] ?? 0);
  }

}
