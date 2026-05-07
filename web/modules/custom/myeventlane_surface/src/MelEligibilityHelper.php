<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Lists interpreted feature eligibility facets for UX prompts (not permissions).
 */
final class MelEligibilityHelper {

  /**
   * @param array<string, \Drupal\myeventlane_surface\MelStateEvaluation> $evaluations
   *
   * @return list<string>
   */
  public function satisfiedFeatureIds(MelStateRegistry $registry, array $evaluations): array {
    $eligible = [];
    foreach (array_keys($registry->byCategory(MelStateContractCategory::FeatureEligibility)) as $id) {
      if (($evaluations[$id] ?? NULL) === MelStateEvaluation::Satisfied) {
        $eligible[] = $id;
      }
    }
    return $eligible;
  }

}
