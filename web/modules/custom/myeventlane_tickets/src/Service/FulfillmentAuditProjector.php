<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

/**
 * Fulfillment lifecycle audit visibility (ordering and continuity only).
 *
 * Emits normalized strings and deterministic ordering anchors — no replay
 * tokens, QR payloads, device fingerprints, or customer identifiers.
 */
final class FulfillmentAuditProjector {

  public function __construct(
    private readonly FulfillmentLifecycleManager $fulfillmentLifecycleManager,
  ) {}

  /**
   * @param array<string, mixed> $merged
   *
   * @return list<array<string, mixed>>
   */
  public function buildWorkspaceFulfillmentAuditSections(array $merged, int $requestTime): array {
    $model = $this->fulfillmentLifecycleManager->composeFulfillmentReadModel($merged, $requestTime);
    $rollup = is_array($model['rollup'] ?? NULL) ? $model['rollup'] : [];
    $tally = is_array($rollup['canonical_lifecycle_state_tally'] ?? NULL) ? $rollup['canonical_lifecycle_state_tally'] : [];
    $failed = (int) ($tally[FulfillmentLifecycleManager::STATE_FAILED] ?? 0);
    $tone = $failed > 0 ? 'elevated' : 'low';

    $timeline = $this->buildFulfillmentOrderingTimeline($model, $requestTime);
    $partial_note = $this->partialFulfillmentContinuityNote($model);

    return [
      [
        'id' => 'fulfillment_lifecycle_audit_timeline',
        'title' => 'Fulfillment lifecycle audit timeline',
        'section_tone' => $tone,
        'cards' => [
          [
            'label' => 'Fulfillment transition ordering',
            'value' => $this->transitionOrderingSummary($model),
          ],
          [
            'label' => 'Redemption audit summary',
            'value' => $this->redemptionAuditSummary($model),
          ],
          [
            'label' => 'Pickup audit summary',
            'value' => $this->pickupAuditSummary($model),
          ],
          [
            'label' => 'Partial fulfillment continuity',
            'value' => $partial_note,
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
  private function buildFulfillmentOrderingTimeline(array $model, int $requestTime): array {
    $rows = is_array($model['ticket_projections'] ?? NULL) ? $model['ticket_projections'] : [];
    $out = [];
    $seq = 0;
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $seq++;
      $state = (string) ($row['normalized_lifecycle_state'] ?? '');
      $type = (string) ($row['fulfillment_type'] ?? '');
      $partial = !empty($row['partial_fulfillment']) ? 'yes' : 'no';
      $out[] = [
        'lane_state' => $state,
        'recorded_at_unix' => $requestTime + $seq,
        'audit_note' => 'fulfillment_type=' . $type . '; partial=' . $partial . '; collection=' . (string) ($row['collection_semantics'] ?? '') . '; consumption=' . (string) ($row['consumption_semantics'] ?? ''),
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
      $ordered[] = (string) ($row['normalized_lifecycle_state'] ?? '');
    }
    return $ordered === [] ? 'no_rows' : implode(' → ', $ordered);
  }

  /**
   * @param array<string, mixed> $model
   */
  private function redemptionAuditSummary(array $model): string {
    $parts = [];
    $rows = is_array($model['ticket_projections'] ?? NULL) ? $model['ticket_projections'] : [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $parts[] = (string) ($row['redemption_execution_summary'] ?? '');
    }
    return $parts === [] ? 'no_redemption_audit_rows' : implode(' | ', $parts);
  }

  /**
   * @param array<string, mixed> $model
   */
  private function pickupAuditSummary(array $model): string {
    $parts = [];
    $rows = is_array($model['ticket_projections'] ?? NULL) ? $model['ticket_projections'] : [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $parts[] = (string) ($row['pickup_lifecycle_summary'] ?? '');
    }
    return $parts === [] ? 'no_pickup_audit_rows' : implode(' | ', $parts);
  }

  /**
   * @param array<string, mixed> $model
   */
  private function partialFulfillmentContinuityNote(array $model): string {
    $n = (int) (($model['rollup'] ?? [])['partial_fulfillment_rows'] ?? 0);
    if ($n < 1) {
      return 'no_partial_fulfillment_rows_observed';
    }
    return 'partial_fulfillment_rows=' . (string) $n . ' (multi-redeem semantics)';
  }

}
