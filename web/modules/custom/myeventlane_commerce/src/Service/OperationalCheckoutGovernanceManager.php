<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Read-only governance projections for checkout orchestration (signals only).
 *
 * Does not mutate carts, orders, inventory, shipments, entitlements, or scanners.
 */
final class OperationalCheckoutGovernanceManager {

  use StringTranslationTrait;

  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @param array<string, mixed> $checkout_contract
   *   Document shaped by {@see OperationalCheckoutOrchestrationManager}.
   *
   * @return array<string, mixed>
   */
  public function projectCheckoutGovernance(array $checkout_contract): array {
    $rollup = is_array($checkout_contract['readiness_rollup'] ?? NULL) ? $checkout_contract['readiness_rollup'] : [];
    $blocked = (int) ($rollup['blocked'] ?? 0);
    $degraded = (int) ($rollup['degraded'] ?? 0);
    $authoring = (int) ($rollup['authoring'] ?? 0);
    $nominal = (int) ($rollup['nominal'] ?? 0);
    $unknown = (int) ($rollup['unknown'] ?? 0);

    $pickup = is_array($checkout_contract['pickup_groups'] ?? NULL) ? $checkout_contract['pickup_groups'] : [];
    $pickup_modes = [];
    foreach ($pickup as $line) {
      if (!is_array($line)) {
        continue;
      }
      $pres = is_array($line['presentation'] ?? NULL) ? $line['presentation'] : [];
      $mode = (string) ($pres['pickup_mode'] ?? '');
      if ($mode !== '') {
        $pickup_modes[$mode] = TRUE;
      }
    }

    $hospitality = is_array($checkout_contract['hospitality_groups'] ?? NULL) ? $checkout_contract['hospitality_groups'] : [];
    $hospitality_hidden = 0;
    foreach ($hospitality as $line) {
      if (!is_array($line)) {
        continue;
      }
      $pres = is_array($line['presentation'] ?? NULL) ? $line['presentation'] : [];
      if (($pres['customer_visibility'] ?? '') === 'hidden') {
        $hospitality_hidden++;
      }
    }

    $timed = is_array($checkout_contract['timed_collection_groups'] ?? NULL) ? $checkout_contract['timed_collection_groups'] : [];
    $timed_conflict = 0;
    foreach ($timed as $line) {
      if (!is_array($line)) {
        continue;
      }
      $pres = is_array($line['presentation'] ?? NULL) ? $line['presentation'] : [];
      if (($pres['pickup_mode'] ?? '') === 'conflict') {
        $timed_conflict++;
      }
    }

    $continuity = is_array($checkout_contract['continuity_groups'] ?? NULL) ? $checkout_contract['continuity_groups'] : [];
    $composition_hint = FALSE;
    foreach ($continuity as $row) {
      if (is_array($row) && ($row['kind'] ?? '') === 'composition_hint') {
        $composition_hint = TRUE;
        break;
      }
    }

    $severity = 'nominal';
    if ($blocked > 0 || $timed_conflict > 0) {
      $severity = 'elevated';
    }
    elseif ($degraded > 1 || $hospitality_hidden > 0 || count($pickup_modes) > 2) {
      $severity = 'elevated';
    }
    elseif ($degraded > 0 || $authoring > 0 || $unknown > 0) {
      $severity = 'attention';
    }

    return [
      'severity' => $severity,
      'severity_label' => match ($severity) {
        'elevated' => (string) $this->t('Review operational guidance'),
        'attention' => (string) $this->t('Check operational reminders'),
        default => (string) $this->t('Operational expectations look nominal'),
      },
      'degraded_fulfillment' => [
        'signals' => [],
        'note' => (string) $this->t('Live fulfillment execution is shown in dedicated venue strips; checkout orchestration is planning-only.'),
      ],
      'incomplete_readiness' => [
        'blocked_count' => $blocked,
        'degraded_count' => $degraded,
        'authoring_count' => $authoring,
        'unknown_count' => $unknown,
        'nominal_count' => $nominal,
      ],
      'partial_pickup_grouping' => [
        'pickup_line_count' => count($pickup),
        'distinct_pickup_mode_count' => count($pickup_modes),
      ],
      'continuity_conflicts' => [
        'continuity_row_count' => count($continuity),
        'has_composition_hint' => $composition_hint,
      ],
      'hospitality_degradation' => [
        'hidden_row_count' => $hospitality_hidden,
        'total_row_count' => count($hospitality),
      ],
      'timed_collection_conflicts' => [
        'conflict_row_count' => $timed_conflict,
        'total_row_count' => count($timed),
      ],
    ];
  }

}
