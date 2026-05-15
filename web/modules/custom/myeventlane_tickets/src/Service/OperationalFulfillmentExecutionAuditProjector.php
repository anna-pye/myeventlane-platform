<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

/**
 * Audit timeline for fulfillment execution orchestration (read-only).
 */
final class OperationalFulfillmentExecutionAuditProjector {

  public function __construct(
    private readonly OperationalFulfillmentExecutionManager $operationalFulfillmentExecutionManager,
  ) {}

  /**
   * @param array<string, mixed> $merged
   *
   * @return list<array<string, mixed>>
   */
  public function buildWorkspaceExecutionAuditSections(array $merged, int $requestTime): array {
    $bundle = $this->operationalFulfillmentExecutionManager->composeExecutionBundleFromMerged($merged, $requestTime);
    $executions = is_array($bundle['executions'] ?? NULL) ? $bundle['executions'] : [];
    $failed = 0;
    foreach ($executions as $row) {
      if (!is_array($row)) {
        continue;
      }
      if (($row['execution_state'] ?? '') === FulfillmentLifecycleManager::STATE_FAILED) {
        $failed++;
      }
    }
    $tone = $failed > 0 ? 'elevated' : 'low';

    $timeline = [];
    $seq = 0;
    foreach ($executions as $row) {
      if (!is_array($row)) {
        continue;
      }
      $seq++;
      $timeline[] = [
        'lane_state' => (string) ($row['execution_state'] ?? ''),
        'recorded_at_unix' => $requestTime + $seq,
        'audit_note' => 'execution_type=' . (string) ($row['execution_type'] ?? '')
          . '; completion=' . (string) ($row['completion_state'] ?? '')
          . '; collection=' . (string) ($row['collection_state'] ?? ''),
      ];
    }

    return [
      [
        'id' => 'operational_execution_audit_timeline',
        'title' => 'Operational execution audit timeline',
        'section_tone' => $tone,
        'cards' => [
          [
            'label' => 'Execution rows sampled',
            'value' => (string) count($executions),
          ],
          [
            'label' => 'Failed execution rows',
            'value' => (string) $failed,
          ],
        ],
        'timeline' => $timeline,
      ],
    ];
  }

}
