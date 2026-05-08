<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\myeventlane_core\MelSurfaceId;

/**
 * Trust threshold interpretation for operational policy (no trust scoring).
 */
final class MelTrustThresholdHelper {

  /**
   * @param list<string> $activePolicyIds
   * @param array<string, string> $evaluations
   *
   * @return array<string, mixed>
   */
  public function interpret(
    MelSurfaceId $surface,
    array $activePolicyIds,
  ): array {
    $staff = $surface === MelSurfaceId::Staff;
    return [
      'payout_review_required' => in_array('payout_review_required', $activePolicyIds, TRUE),
      'moderation_attention_required' => in_array('moderation_attention_required', $activePolicyIds, TRUE),
      'trust_warning_active' => in_array('trust_warning_active', $activePolicyIds, TRUE),
      'verified_vendor_required' => in_array('verified_vendor_required', $activePolicyIds, TRUE),
      'escalation_review_required' => in_array('escalation_review_required', $activePolicyIds, TRUE),
      'trust_sensitive_messaging_required' => in_array('trust_sensitive_tone', $activePolicyIds, TRUE)
        || in_array('payout_review_required', $activePolicyIds, TRUE)
        || in_array('moderation_attention_required', $activePolicyIds, TRUE),
      'escalation_safe_ux_required' => in_array('escalation_review_required', $activePolicyIds, TRUE)
        || in_array('escalation_tone', $activePolicyIds, TRUE),
      'staff_diagnostic_surface' => $staff,
    ];
  }

}
