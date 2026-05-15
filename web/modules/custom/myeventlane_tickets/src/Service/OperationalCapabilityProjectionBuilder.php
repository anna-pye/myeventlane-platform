<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

/**
 * Twig-safe operational capability cards for the venue operations workspace.
 *
 * Values are pre-normalized by {@see OperationalEntitlementCapabilityManager}.
 */
final class OperationalCapabilityProjectionBuilder {

  public function __construct(
    private readonly OperationalEntitlementCapabilityManager $operationalEntitlementCapabilityManager,
  ) {}

  /**
   * @param array<string, mixed> $merged
   *
   * @return list<array<string, mixed>>
   */
  public function buildWorkspaceCapabilitySections(array $merged, int $requestTime): array {
    $model = $this->operationalEntitlementCapabilityManager->composeCapabilityReadModel($merged, $requestTime);
    $rollup = is_array($model['rollup'] ?? NULL) ? $model['rollup'] : [];
    $tally = is_array($rollup['canonical_capability_state_tally'] ?? NULL) ? $rollup['canonical_capability_state_tally'] : [];
    $types = is_array($rollup['capability_type_tally'] ?? NULL) ? $rollup['capability_type_tally'] : [];
    $readiness = is_array($model['readiness_projection'] ?? NULL) ? $model['readiness_projection'] : [];
    $degradation = is_array($model['degradation_projection'] ?? NULL) ? $model['degradation_projection'] : [];
    $continuity = is_array($model['continuity_projection'] ?? NULL) ? $model['continuity_projection'] : [];
    $fulfillment = is_array($model['fulfillment_capability_projection'] ?? NULL)
      ? $model['fulfillment_capability_projection']
      : [];
    $reservation = is_array($model['reservation_capability_projection'] ?? NULL)
      ? $model['reservation_capability_projection']
      : [];

    $degraded = (int) ($tally[OperationalEntitlementCapabilityManager::STATE_DEGRADED] ?? 0);
    $tone = $degraded > 0 || (string) ($degradation['descriptor'] ?? '') !== 'nominal' ? 'elevated' : 'low';

    $type_cards = [];
    foreach ($types as $type => $count) {
      if ((int) $count < 1) {
        continue;
      }
      $type_cards[] = [
        'label' => 'Capability type — ' . (string) $type,
        'value' => (string) (int) $count,
      ];
    }
    if ($type_cards === []) {
      $type_cards[] = [
        'label' => 'Capability type coverage',
        'value' => 'no_sampled_rows',
      ];
    }

    return [
      [
        'id' => 'capability_governance_summary',
        'title' => 'Operational capability summary',
        'section_tone' => $tone,
        'cards' => [
          [
            'label' => 'Sampled ticket rows (capability lens)',
            'value' => (string) (int) ($rollup['sampled_ticket_rows'] ?? 0),
          ],
          [
            'label' => 'Capability readiness projection',
            'value' => (string) ($readiness['descriptor'] ?? '') . '; execution_ready=' . (string) (int) ($readiness['execution_ready_rows'] ?? 0),
          ],
          [
            'label' => 'Capability degradation projection',
            'value' => (string) ($degradation['descriptor'] ?? ''),
          ],
          [
            'label' => 'Capability continuity projection',
            'value' => (string) ($continuity['descriptor'] ?? '') . '; continuity_rows=' . (string) (int) ($continuity['continuity_row_count'] ?? 0),
          ],
          [
            'label' => 'Fulfillment-capability visibility',
            'value' => (string) ($fulfillment['descriptor'] ?? '') . '; collect_rows=' . (string) (int) ($fulfillment['fulfilment_collect_rows'] ?? 0),
          ],
          [
            'label' => 'Reservation-capability visibility',
            'value' => (string) ($reservation['descriptor'] ?? '') . '; allocated_fraction=' . (string) ($reservation['allocated_fraction'] ?? 0),
          ],
        ],
      ],
      [
        'id' => 'capability_lifecycle_cards',
        'title' => 'Capability lifecycle cards',
        'section_tone' => $tone,
        'cards' => $this->flattenCapabilityTallyCards($tally),
      ],
      [
        'id' => 'capability_readiness_visibility',
        'title' => 'Capability readiness and execution visibility',
        'section_tone' => $tone,
        'cards' => array_merge(
          [
            [
              'label' => 'Collection-ready capability rows',
              'value' => (string) (int) ($readiness['collection_ready_rows'] ?? 0),
            ],
            [
              'label' => 'Merch pickup readiness rows',
              'value' => (string) (int) ($readiness['merch_pickup_ready_rows'] ?? 0),
            ],
            [
              'label' => 'Hospitality access readiness rows',
              'value' => (string) (int) ($readiness['hospitality_ready_rows'] ?? 0),
            ],
            [
              'label' => 'Timed collection readiness rows',
              'value' => (string) (int) ($readiness['timed_collection_ready_rows'] ?? 0),
            ],
            [
              'label' => 'Degraded capability rows',
              'value' => (string) $degraded,
            ],
            [
              'label' => 'Fulfilment redeemed rows (visibility)',
              'value' => (string) (int) ($fulfillment['fulfilment_redeemed_rows'] ?? 0),
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
  private function flattenCapabilityTallyCards(array $tally): array {
    $cards = [];
    foreach ($this->operationalEntitlementCapabilityManager->allCanonicalStates() as $state) {
      $cards[] = [
        'label' => 'Capability — ' . $state,
        'value' => (string) (int) ($tally[$state] ?? 0),
      ];
    }
    return $cards;
  }

}
