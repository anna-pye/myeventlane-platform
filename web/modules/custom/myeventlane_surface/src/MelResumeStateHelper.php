<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Resume-state orchestration hints — copy remains delegated to workflows/onboarding.
 *
 * @see \Drupal\myeventlane_surface\MelWorkflowCTAHelper
 */
final class MelResumeStateHelper {

  /**
   * @param list<\Drupal\myeventlane_surface\ExperienceDefinition> $applicable
   * @param array<string, bool|int|string> $merged_signals
   *
   * @return list<array<string, mixed>>
   */
  public function buildResumeCandidates(array $applicable, MelWorkflowContext $context, array $merged_signals): array {
    $candidates = [];
    foreach ($applicable as $definition) {
      if ($definition->resumeGateSignalsAll === []) {
        continue;
      }
      $gates_ok = TRUE;
      foreach ($definition->resumeGateSignalsAll as $key) {
        if (empty($merged_signals[$key])) {
          $gates_ok = FALSE;
          break;
        }
      }
      if (!$gates_ok) {
        continue;
      }

      if ($context->checkoutSensitive && !empty($merged_signals['route.commerce_checkout'])) {
        if ($definition->category !== MelExperienceContractCategory::CheckoutContinuity) {
          continue;
        }
      }

      $candidates[] = [
        'experience_id' => $definition->id,
        'priority' => $definition->continuityPriority,
        'linked_workflow_ids' => $definition->linkedWorkflowIds,
        'privacy_note' => $definition->privacyBoundaries,
      ];
    }

    usort($candidates, static function (array $a, array $b): int {
      return (int) ($b['priority'] ?? 0) <=> (int) ($a['priority'] ?? 0);
    });

    return $candidates;
  }

}
