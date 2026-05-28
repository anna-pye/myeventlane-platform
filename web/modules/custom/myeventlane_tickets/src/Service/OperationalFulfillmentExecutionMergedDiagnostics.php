<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

/**
 * Adapts {@see OperationalIntegrityInspector::inspectOrder()} output to merged
 * inspection shape expected by {@see FulfillmentLifecycleManager::composeFulfillmentReadModel()}.
 *
 * Read-only adapter — does not re-run policy or duplicate fulfillment mapping.
 */
final class OperationalFulfillmentExecutionMergedDiagnostics {

  /**
   * @param array<string, mixed> $diagnostics
   *   Return value of OperationalIntegrityInspector::inspectOrder().
   *
   * @return array<string, mixed>
   */
  public static function toMergedInspectionShape(array $diagnostics): array {
    $issuance = is_array($diagnostics['issuance'] ?? NULL) ? $diagnostics['issuance'] : [];
    $recovery = is_array($diagnostics['recovery'] ?? NULL) ? $diagnostics['recovery'] : [];
    $guest = is_array($diagnostics['guest_continuity'] ?? NULL) ? $diagnostics['guest_continuity'] : [];
    $artifacts = is_array($diagnostics['artifacts'] ?? NULL) ? $diagnostics['artifacts'] : [];
    $fulfillment = is_array($diagnostics['fulfillment_operational_signals'] ?? NULL)
      ? $diagnostics['fulfillment_operational_signals']
      : [];
    $by_ticket = is_array($fulfillment['by_ticket_id'] ?? NULL) ? $fulfillment['by_ticket_id'] : [];

    return [
      'issuance' => [
        'aggregated_expected_quantity' => (int) ($issuance['expected_quantity'] ?? 0),
        'aggregated_issued_ticket_count' => (int) ($issuance['issued_ticket_count'] ?? 0),
        'worst_quantity_alignment_status' => (string) ($issuance['quantity_alignment_status'] ?? 'missing'),
        'aggregated_orphan_ticket_count' => (int) ($issuance['orphan_ticket_count'] ?? 0),
        'aggregated_orphan_eligible_order_item_count' => (int) ($issuance['orphan_eligible_order_item_count'] ?? 0),
      ],
      'recovery' => [
        'completion_states_observed' => array_values(array_unique(array_filter([
          (string) ($recovery['completion_state'] ?? ''),
        ]))),
        'recovery_mismatch_observed' => !empty($recovery['recovery_mismatch']),
      ],
      'guest_continuity' => [
        'continuity_statuses_observed' => array_values(array_unique(array_filter([
          (string) ($guest['continuity_status'] ?? ''),
        ]))),
      ],
      'artifacts' => $artifacts,
      'fulfillment_signals' => [
        'by_ticket_id' => $by_ticket,
      ],
    ];
  }

}
