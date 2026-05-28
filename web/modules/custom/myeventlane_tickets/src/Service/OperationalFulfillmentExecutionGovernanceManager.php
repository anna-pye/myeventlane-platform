<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Read-only execution governance projections (severity, stall hints).
 *
 * Consumes documents from {@see OperationalFulfillmentExecutionManager} only.
 */
final class OperationalFulfillmentExecutionGovernanceManager {

  use StringTranslationTrait;

  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @param array<string, mixed> $execution_bundle
   *   Output of OperationalFulfillmentExecutionManager::composeExecutionBundleFromMerged().
   *
   * @return array<string, mixed>
   */
  public function projectGovernance(array $execution_bundle): array {
    if (!empty($execution_bundle['empty'])) {
      return [
        'severity' => 'nominal',
        'severity_label' => (string) $this->t('Nominal'),
        'stalled_executions' => 0,
        'degraded_visibility' => FALSE,
        'continuity_conflict' => FALSE,
        'partial_completion_rows' => (int) (($execution_bundle['rollup'] ?? [])['partial_rows'] ?? 0),
        'readiness_failure' => FALSE,
        'notes' => [],
      ];
    }

    $executions = is_array($execution_bundle['executions'] ?? NULL) ? $execution_bundle['executions'] : [];
    $rollup = is_array($execution_bundle['rollup'] ?? NULL) ? $execution_bundle['rollup'] : [];
    $partial = (int) ($rollup['partial_rows'] ?? 0);
    $stalled = 0;
    foreach ($executions as $row) {
      if (!is_array($row)) {
        continue;
      }
      $state = (string) ($row['execution_state'] ?? '');
      if (in_array($state, [
        FulfillmentLifecycleManager::STATE_PENDING,
        FulfillmentLifecycleManager::STATE_PREPARED,
      ], TRUE)) {
        $stalled++;
      }
    }

    $failed_like = 0;
    foreach ($executions as $row) {
      if (!is_array($row)) {
        continue;
      }
      $state = (string) ($row['execution_state'] ?? '');
      if (in_array($state, [
        FulfillmentLifecycleManager::STATE_FAILED,
        FulfillmentLifecycleManager::STATE_EXPIRED,
      ], TRUE)) {
        $failed_like++;
      }
    }

    $continuity_conflict = str_contains(strtolower((string) ($rollup['readiness_descriptor'] ?? '')), 'degraded');
    $severity = 'nominal';
    if ($failed_like > 0) {
      $severity = 'elevated';
    }
    elseif ($stalled > 0 || $partial > 0 || $continuity_conflict) {
      $severity = 'watch';
    }

    $notes = [];
    if ($partial > 0) {
      $notes[] = (string) $this->t('Partial completion semantics observed on one or more rows.');
    }
    if ($stalled > 0) {
      $notes[] = (string) $this->t('Some executions remain in early lifecycle stages.');
    }

    return [
      'severity' => $severity,
      'severity_label' => $this->severityLabel($severity),
      'stalled_executions' => $stalled,
      'degraded_visibility' => $continuity_conflict || $failed_like > 0,
      'continuity_conflict' => $continuity_conflict,
      'partial_completion_rows' => $partial,
      'readiness_failure' => $failed_like > 0,
      'notes' => $notes,
    ];
  }

  private function severityLabel(string $severity): string {
    return match ($severity) {
      'elevated' => (string) $this->t('Elevated attention'),
      'watch' => (string) $this->t('Watch'),
      default => (string) $this->t('Nominal'),
    };
  }

}
