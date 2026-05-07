<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Escalation policy interpretation (continuity hints, not enforcement).
 */
final class MelEscalationPolicyHelper {

  /**
   * @param list<string> $activePolicyIds
   * @param array<string, bool|int|string> $merged
   *
   * @return array<string, mixed>
   */
  public function interpret(array $activePolicyIds, array $merged): array {
    $escalation_review = in_array('escalation_review_required', $activePolicyIds, TRUE);
    $moderation_attention = in_array('moderation_attention_required', $activePolicyIds, TRUE);
    return [
      'escalation_review_active' => $escalation_review,
      'moderation_attention_active' => $moderation_attention,
      'support_escalation_signal' => !empty($merged['support.flags.escalation']),
      'continuity_overrides_engagement' => $escalation_review || $moderation_attention,
    ];
  }

}
