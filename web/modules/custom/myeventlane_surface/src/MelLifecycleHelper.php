<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\myeventlane_core\MelStateEvaluation;

/**
 * Chooses a single primary lifecycle label from interpreted states (chips/badges).
 */
final class MelLifecycleHelper {

  /**
   * Priority order for mutually exclusive lifecycle presentation.
   *
   * @var list<string>
   */
  private const PRIMARY_ORDER = [
    'moderation_hold',
    'archived',
    'cancelled',
    'sold_out',
    'draft',
    'incomplete',
    'publishable',
    'published',
    'boosted',
  ];

  /**
   * @param array<string, \Drupal\myeventlane_core\MelStateEvaluation> $evaluations
   */
  public function resolvePrimaryLifecycleId(array $evaluations): ?string {
    foreach (self::PRIMARY_ORDER as $id) {
      if (($evaluations[$id] ?? NULL) === MelStateEvaluation::Satisfied) {
        return $id;
      }
    }
    return NULL;
  }

}
