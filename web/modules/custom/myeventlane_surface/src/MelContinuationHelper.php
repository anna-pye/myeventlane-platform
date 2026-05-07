<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Journey / lifecycle continuity hints derived from linked workflow contracts.
 */
final class MelContinuationHelper {

  /**
   * @param list<\Drupal\myeventlane_surface\ExperienceDefinition> $applicable
   *
   * @return array<string, mixed>
   */
  public function buildContinuationHints(array $applicable, MelWorkflowRegistry $workflow_registry): array {
    $transitions = [];
    $workflow_edges = [];

    foreach ($applicable as $definition) {
      foreach ($definition->crossSurfaceTransitions as $hint) {
        $transitions[$hint] = TRUE;
      }

      foreach ($definition->linkedWorkflowIds as $workflow_id) {
        $workflow = $workflow_registry->all()[$workflow_id] ?? NULL;
        if (!$workflow instanceof WorkflowDefinition) {
          continue;
        }
        foreach ($workflow->nextStepRecommendations as $next_id) {
          $workflow_edges[] = [
            'from_workflow_id' => $workflow_id,
            'to_workflow_id' => $next_id,
            'experience_id' => $definition->id,
          ];
        }
      }
    }

    return [
      'cross_surface_hints' => array_keys($transitions),
      'workflow_edges' => $workflow_edges,
      'cta_sequencing_notes' => $this->collectSequencingNotes($applicable),
    ];
  }

  /**
   * @param list<\Drupal\myeventlane_surface\ExperienceDefinition> $applicable
   *
   * @return list<string>
   */
  private function collectSequencingNotes(array $applicable): array {
    $notes = [];
    foreach ($applicable as $definition) {
      if ($definition->ctaSequencingNotes !== '') {
        $notes[] = $definition->ctaSequencingNotes;
      }
    }
    return array_values(array_unique($notes));
  }

}
