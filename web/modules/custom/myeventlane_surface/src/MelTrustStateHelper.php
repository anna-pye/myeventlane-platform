<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Surfaces safe trust indicators per shell (no moderation leakage).
 */
final class MelTrustStateHelper {

  /**
   * @param array<string, \Drupal\myeventlane_surface\MelStateEvaluation> $evaluations
   * @param array<string, \Drupal\myeventlane_surface\StateDefinition> $definitions
   *
   * @return list<string>
   */
  public function visibleTrustStateIds(MelSurfaceId $surface, array $evaluations, array $definitions): array {
    $visible = [];
    foreach ($definitions as $id => $definition) {
      if ($definition->category !== MelStateContractCategory::Trust) {
        continue;
      }
      if (($evaluations[$id] ?? NULL) !== MelStateEvaluation::Satisfied) {
        continue;
      }
      if ($surface === MelSurfaceId::Public || $surface === MelSurfaceId::Auth) {
        if (!$definition->publicSafe) {
          continue;
        }
      }
      $visible[] = $id;
    }
    return $visible;
  }

}
