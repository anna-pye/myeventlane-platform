<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

/**
 * Twig-safe inventory reservation cards for the venue operations workspace.
 *
 * Values are pre-normalized by {@see InventoryReservationGovernanceManager}.
 */
final class InventoryReservationProjectionBuilder {

  public function __construct(
    private readonly InventoryReservationGovernanceManager $inventoryReservationGovernanceManager,
  ) {}

  /**
   * @param array<string, mixed> $merged
   *
   * @return list<array<string, mixed>>
   */
  public function buildWorkspaceReservationSections(array $merged, int $requestTime): array {
    $model = $this->inventoryReservationGovernanceManager->composeReservationReadModel($merged, $requestTime);
    $rollup = is_array($model['rollup'] ?? NULL) ? $model['rollup'] : [];
    $tally = is_array($rollup['canonical_reservation_state_tally'] ?? NULL) ? $rollup['canonical_reservation_state_tally'] : [];
    $types = is_array($rollup['reservation_type_tally'] ?? NULL) ? $rollup['reservation_type_tally'] : [];
    $readiness = is_array($model['readiness_projection'] ?? NULL) ? $model['readiness_projection'] : [];
    $degradation = is_array($model['degradation_projection'] ?? NULL) ? $model['degradation_projection'] : [];
    $continuity = is_array($model['continuity_projection'] ?? NULL) ? $model['continuity_projection'] : [];
    $allocation = is_array($model['allocation_projection'] ?? NULL) ? $model['allocation_projection'] : [];

    $degraded = (int) ($tally[InventoryReservationGovernanceManager::STATE_DEGRADED] ?? 0);
    $tone = $degraded > 0 || (string) ($degradation['descriptor'] ?? '') !== 'nominal' ? 'elevated' : 'low';

    $type_cards = [];
    foreach ($types as $type => $count) {
      if ((int) $count < 1) {
        continue;
      }
      $type_cards[] = [
        'label' => 'Reservation type — ' . (string) $type,
        'value' => (string) (int) $count,
      ];
    }
    if ($type_cards === []) {
      $type_cards[] = [
        'label' => 'Reservation type coverage',
        'value' => 'no_sampled_rows',
      ];
    }

    return [
      [
        'id' => 'reservation_governance_summary',
        'title' => 'Reservation governance summary',
        'section_tone' => $tone,
        'cards' => [
          [
            'label' => 'Sampled ticket rows (reservation lens)',
            'value' => (string) (int) ($rollup['sampled_ticket_rows'] ?? 0),
          ],
          [
            'label' => 'Partial allocation rows',
            'value' => (string) (int) ($rollup['partial_allocation_rows'] ?? 0),
          ],
          [
            'label' => 'Allocation continuity projection',
            'value' => (string) ($allocation['descriptor'] ?? '') . '; allocated_fraction=' . (string) ($allocation['allocated_fraction'] ?? 0),
          ],
          [
            'label' => 'Reservation readiness projection',
            'value' => (string) ($readiness['descriptor'] ?? '') . '; collection_ready=' . (string) (int) ($readiness['collection_ready_rows'] ?? 0),
          ],
          [
            'label' => 'Reservation degradation projection',
            'value' => (string) ($degradation['descriptor'] ?? ''),
          ],
          [
            'label' => 'Reservation continuity projection',
            'value' => (string) ($continuity['descriptor'] ?? '') . '; continuity_rows=' . (string) (int) ($continuity['continuity_row_count'] ?? 0),
          ],
        ],
      ],
      [
        'id' => 'reservation_lifecycle_cards',
        'title' => 'Reservation lifecycle cards',
        'section_tone' => $tone,
        'cards' => $this->flattenReservationTallyCards($tally),
      ],
      [
        'id' => 'reservation_allocation_visibility',
        'title' => 'Allocation and readiness visibility',
        'section_tone' => $tone,
        'cards' => array_merge(
          [
            [
              'label' => 'Collection-ready rows',
              'value' => (string) (int) ($readiness['collection_ready_rows'] ?? 0),
            ],
            [
              'label' => 'Timed pickup readiness rows',
              'value' => (string) (int) ($readiness['timed_pickup_ready_rows'] ?? 0),
            ],
            [
              'label' => 'Degraded reservation rows',
              'value' => (string) $degraded,
            ],
            [
              'label' => 'Partial allocation visibility',
              'value' => (string) (int) ($rollup['partial_allocation_rows'] ?? 0),
            ],
          ],
          $type_cards,
        ),
      ],
    ];
  }

  /**
   * @param array<string, int> $tally
   *
   * @return list<array<string, string>>
   */
  private function flattenReservationTallyCards(array $tally): array {
    $cards = [];
    foreach ($this->inventoryReservationGovernanceManager->allCanonicalStates() as $state) {
      $cards[] = [
        'label' => 'Reservation — ' . $state,
        'value' => (string) (int) ($tally[$state] ?? 0),
      ];
    }
    return $cards;
  }

}
