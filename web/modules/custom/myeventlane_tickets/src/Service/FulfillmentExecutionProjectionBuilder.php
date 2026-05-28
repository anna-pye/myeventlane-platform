<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

/**
 * Twig-safe fulfillment execution cards for the venue operations workspace.
 *
 * Values are pre-normalized by {@see FulfillmentLifecycleManager}.
 */
final class FulfillmentExecutionProjectionBuilder {

  public function __construct(
    private readonly FulfillmentLifecycleManager $fulfillmentLifecycleManager,
  ) {}

  /**
   * @param array<string, mixed> $merged
   *
   * @return list<array<string, mixed>>
   */
  public function buildWorkspaceFulfillmentSections(array $merged, int $requestTime): array {
    $model = $this->fulfillmentLifecycleManager->composeFulfillmentReadModel($merged, $requestTime);
    $rollup = is_array($model['rollup'] ?? NULL) ? $model['rollup'] : [];
    $tally = is_array($rollup['canonical_lifecycle_state_tally'] ?? NULL) ? $rollup['canonical_lifecycle_state_tally'] : [];
    $types = is_array($rollup['fulfillment_type_tally'] ?? NULL) ? $rollup['fulfillment_type_tally'] : [];
    $completion = is_array($model['completion_projection'] ?? NULL) ? $model['completion_projection'] : [];
    $readiness = is_array($model['readiness_projection'] ?? NULL) ? $model['readiness_projection'] : [];
    $degradation = is_array($model['degradation_projection'] ?? NULL) ? $model['degradation_projection'] : [];

    $failed = (int) ($tally[FulfillmentLifecycleManager::STATE_FAILED] ?? 0);
    $tone = $failed > 0 || (string) ($degradation['descriptor'] ?? '') !== 'nominal' ? 'elevated' : 'low';

    $type_cards = [];
    foreach ($types as $type => $count) {
      if ((int) $count < 1) {
        continue;
      }
      $type_cards[] = [
        'label' => 'Fulfillment type — ' . (string) $type,
        'value' => (string) (int) $count,
      ];
    }
    if ($type_cards === []) {
      $type_cards[] = [
        'label' => 'Fulfillment type coverage',
        'value' => 'no_sampled_rows',
      ];
    }

    return [
      [
        'id' => 'fulfillment_execution_summary',
        'title' => 'Fulfillment execution summary',
        'section_tone' => $tone,
        'cards' => [
          [
            'label' => 'Sampled ticket rows (fulfillment lens)',
            'value' => (string) (int) ($rollup['sampled_ticket_rows'] ?? 0),
          ],
          [
            'label' => 'Partial fulfillment rows',
            'value' => (string) (int) ($rollup['partial_fulfillment_rows'] ?? 0),
          ],
          [
            'label' => 'Fulfillment completion projection',
            'value' => (string) ($completion['descriptor'] ?? '') . '; terminal_fraction=' . (string) ($completion['fulfilled_or_beyond_fraction'] ?? 0),
          ],
          [
            'label' => 'Fulfillment readiness projection',
            'value' => (string) ($readiness['descriptor'] ?? '') . '; pickup_ready=' . (string) (int) ($readiness['pickup_ready_rows'] ?? 0),
          ],
          [
            'label' => 'Fulfillment degradation projection',
            'value' => (string) ($degradation['descriptor'] ?? ''),
          ],
        ],
      ],
      [
        'id' => 'fulfillment_lifecycle_cards',
        'title' => 'Fulfillment lifecycle cards',
        'section_tone' => $tone,
        'cards' => $this->flattenLifecycleTallyCards($tally),
      ],
      [
        'id' => 'fulfillment_pickup_redemption_visibility',
        'title' => 'Pickup and redemption visibility',
        'section_tone' => $tone,
        'cards' => array_merge(
          [
            [
              'label' => 'Pickup readiness rows (ready state)',
              'value' => (string) (int) ($readiness['pickup_ready_rows'] ?? 0),
            ],
            [
              'label' => 'Redemption execution lane (aggregate)',
              'value' => $this->aggregateRedemptionLaneSummary($model),
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
  private function flattenLifecycleTallyCards(array $tally): array {
    $cards = [];
    foreach ($this->fulfillmentLifecycleManager->allCanonicalStates() as $state) {
      $cards[] = [
        'label' => 'Lifecycle — ' . $state,
        'value' => (string) (int) ($tally[$state] ?? 0),
      ];
    }
    return $cards;
  }

  /**
   * @param array<string, mixed> $model
   */
  private function aggregateRedemptionLaneSummary(array $model): string {
    $rows = is_array($model['ticket_projections'] ?? NULL) ? $model['ticket_projections'] : [];
    $parts = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $parts[] = (string) ($row['redemption_execution_summary'] ?? '');
    }
    if ($parts === []) {
      return 'no_redemption_projection_rows';
    }
    return implode(' | ', $parts);
  }

}
