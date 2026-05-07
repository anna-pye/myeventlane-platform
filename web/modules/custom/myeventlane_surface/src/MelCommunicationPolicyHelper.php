<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Communication policy hints — categorical keys only (no generated copy).
 */
final class MelCommunicationPolicyHelper {

  /**
   * @param list<string> $activePolicyIds
   *
   * @return array<string, bool>
   */
  public function activeToneHints(array $activePolicyIds): array {
    $keys = [
      'trust_sensitive_tone',
      'escalation_tone',
      'moderation_safe_tone',
      'reduced_urgency_mode',
      'reassurance_priority',
    ];
    $hints = [];
    foreach ($keys as $key) {
      $hints[$key] = in_array($key, $activePolicyIds, TRUE);
    }
    return $hints;
  }

}
