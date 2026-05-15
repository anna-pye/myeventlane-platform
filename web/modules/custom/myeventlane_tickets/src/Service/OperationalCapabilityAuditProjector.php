<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

/**
 * Operational capability audit visibility (ordering and continuity only).
 *
 * Emits normalized strings and deterministic ordering anchors — no replay
 * tokens, QR payloads, device fingerprints, or customer identifiers.
 */
final class OperationalCapabilityAuditProjector {

  public function __construct(
    private readonly OperationalEntitlementCapabilityManager $operationalEntitlementCapabilityManager,
  ) {}

  /**
   * @param array<string, mixed> $merged
   *
   * @return list<array<string, mixed>>
   */
  public function buildWorkspaceCapabilityAuditSections(array $merged, int $requestTime): array {
    $model = $this->operationalEntitlementCapabilityManager->composeCapabilityReadModel($merged, $requestTime);
    $rollup = is_array($model['rollup'] ?? NULL) ? $model['rollup'] : [];
    $tally = is_array($rollup['canonical_capability_state_tally'] ?? NULL) ? $rollup['canonical_capability_state_tally'] : [];
    $degraded = (int) ($tally[OperationalEntitlementCapabilityManager::STATE_DEGRADED] ?? 0);
    $tone = $degraded > 0 ? 'elevated' : 'low';

    $timeline = $this->buildCapabilityOrderingTimeline($model, $requestTime);

    return [
      [
        'id' => 'capability_lifecycle_audit_timeline',
        'title' => 'Capability lifecycle audit timeline',
        'section_tone' => $tone,
        'cards' => [
          [
            'label' => 'Capability transition ordering',
            'value' => $this->transitionOrderingSummary($model),
          ],
          [
            'label' => 'Fulfillment-capability audit summary',
            'value' => $this->fulfillmentAuditSummary($model),
          ],
          [
            'label' => 'Reservation-capability audit summary',
            'value' => $this->reservationAuditSummary($model),
          ],
          [
            'label' => 'Capability degradation ordering',
            'value' => $this->degradationOrderingSummary($model),
          ],
          [
            'label' => 'Capability continuity summary',
            'value' => $this->continuityAuditSummary($model),
          ],
          [
            'label' => 'Scanner capability visibility (read-only)',
            'value' => $this->scannerVisibilitySummary($model),
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
  private function buildCapabilityOrderingTimeline(array $model, int $requestTime): array {
    $rows = is_array($model['ticket_projections'] ?? NULL) ? $model['ticket_projections'] : [];
    $out = [];
    $seq = 0;
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $seq++;
      $state = (string) ($row['normalized_capability_state'] ?? '');
      $type = (string) ($row['capability_type'] ?? '');
      $out[] = [
        'lane_state' => $state,
        'recorded_at_unix' => $requestTime + $seq,
        'audit_note' => 'capability_type=' . $type
          . '; execution=' . (string) ($row['execution_descriptor'] ?? '')
          . '; fulfillment=' . (string) ($row['fulfillment_capability_summary'] ?? '')
          . '; reservation=' . (string) ($row['reservation_capability_summary'] ?? '')
          . '; continuity=' . (string) ($row['continuity_summary'] ?? ''),
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
      $ordered[] = (string) ($row['normalized_capability_state'] ?? '');
    }
    return $ordered === [] ? 'no_rows' : implode(' → ', $ordered);
  }

  /**
   * @param array<string, mixed> $model
   */
  private function fulfillmentAuditSummary(array $model): string {
    $parts = [];
    $rows = is_array($model['ticket_projections'] ?? NULL) ? $model['ticket_projections'] : [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $parts[] = (string) ($row['fulfillment_capability_summary'] ?? '');
    }
    return $parts === [] ? 'no_fulfillment_capability_rows' : implode(' | ', $parts);
  }

  /**
   * @param array<string, mixed> $model
   */
  private function reservationAuditSummary(array $model): string {
    $parts = [];
    $rows = is_array($model['ticket_projections'] ?? NULL) ? $model['ticket_projections'] : [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $parts[] = (string) ($row['reservation_capability_summary'] ?? '');
    }
    return $parts === [] ? 'no_reservation_capability_rows' : implode(' | ', $parts);
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
  private function continuityAuditSummary(array $model): string {
    $continuity = is_array($model['continuity_projection'] ?? NULL) ? $model['continuity_projection'] : [];
    $statuses = is_array($continuity['guest_continuity_statuses'] ?? NULL) ? $continuity['guest_continuity_statuses'] : [];
    return 'guest_continuity=' . ($statuses === [] ? 'n/a' : implode(',', $statuses))
      . '; continuity_rows=' . (string) (int) ($continuity['continuity_row_count'] ?? 0)
      . '; reservation_signal=' . (string) ($continuity['reservation_continuity_signal'] ?? '');
  }

  /**
   * @param array<string, mixed> $model
   */
  private function scannerVisibilitySummary(array $model): string {
    $parts = [];
    $rows = is_array($model['ticket_projections'] ?? NULL) ? $model['ticket_projections'] : [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $parts[] = (string) ($row['scanner_visibility'] ?? '');
    }
    return $parts === [] ? 'no_scanner_visibility_rows' : implode(' | ', $parts);
  }

}
