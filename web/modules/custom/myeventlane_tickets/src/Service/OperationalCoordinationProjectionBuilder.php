<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

/**
 * Twig-safe coordination cards for the venue operations workspace.
 *
 * All values are pre-normalized by {@see OperationalCoordinationStateManager}.
 */
final class OperationalCoordinationProjectionBuilder {

  public function __construct(
    private readonly OperationalCoordinationStateManager $operationalCoordinationStateManager,
  ) {}

  /**
   * @param array<string, mixed> $merged
   *
   * @return list<array<string, mixed>>
   */
  public function buildWorkspaceCoordinationSections(array $merged, int $requestTime): array {
    $model = $this->operationalCoordinationStateManager->composeOperationalCoordinationReadModel($merged, $requestTime);
    $tone = (string) ($model['governed_severity'] ?? 'low');

    return [
      [
        'id' => 'operational_coordination',
        'title' => 'Operational coordination',
        'section_tone' => $tone,
        'cards' => [
          [
            'label' => 'Canonical coordination state',
            'value' => (string) ($model['coordination_state'] ?? ''),
          ],
          [
            'label' => 'Operational posture',
            'value' => (string) ($model['operational_posture'] ?? ''),
          ],
          [
            'label' => 'Operational coordination visibility',
            'value' => (string) ($model['operational_coordination_visibility_descriptor'] ?? ''),
          ],
          [
            'label' => 'Governed severity (rollup composition)',
            'value' => (string) ($model['governed_severity'] ?? ''),
          ],
        ],
      ],
      [
        'id' => 'venue_operational_posture',
        'title' => 'Venue posture and readiness',
        'section_tone' => $tone,
        'cards' => [
          [
            'label' => 'Venue operational readiness',
            'value' => (string) ($model['venue_operational_readiness_descriptor'] ?? ''),
          ],
          [
            'label' => 'Operational degradation summary',
            'value' => (string) ($model['operational_degradation_descriptor'] ?? ''),
          ],
          [
            'label' => 'Venue confidence state',
            'value' => (string) ($model['venue_confidence_state'] ?? ''),
          ],
        ],
      ],
      [
        'id' => 'recovery_reconciliation_coordination',
        'title' => 'Recovery and reconciliation coordination',
        'section_tone' => $tone,
        'cards' => [
          [
            'label' => 'Recovery coordination visibility',
            'value' => (string) ($model['recovery_coordination_descriptor'] ?? ''),
          ],
          [
            'label' => 'Reconciliation readiness visibility',
            'value' => (string) ($model['reconciliation_readiness_descriptor'] ?? ''),
          ],
          [
            'label' => 'Incident saturation state',
            'value' => (string) ($model['incident_saturation_state'] ?? ''),
          ],
          [
            'label' => 'Projected resolution state (observational)',
            'value' => (string) ($model['projected_resolution_state'] ?? ''),
          ],
        ],
      ],
    ];
  }

}
