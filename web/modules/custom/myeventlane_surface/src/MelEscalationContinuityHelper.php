<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Escalation continuity — boolean, privacy-safe flags only (no moderation internals).
 */
final class MelEscalationContinuityHelper {

  /**
   * @param list<\Drupal\myeventlane_surface\ExperienceDefinition> $applicable
   * @param array<string, bool|int|string> $merged_signals
   *
   * @return array<string, bool>
   */
  public function buildSafeFlags(array $applicable, array $merged_signals, string $route_name): array {
    $flags = [
      'moderation_hold_active' => FALSE,
      'support_escalation_active' => FALSE,
      'commerce_dispute_context_active' => FALSE,
    ];

    foreach ($applicable as $definition) {
      if ($definition->category !== MelExperienceContractCategory::EscalationContinuity) {
        continue;
      }
      switch ($definition->id) {
        case 'moderation_interruption':
          $flags['moderation_hold_active'] = $flags['moderation_hold_active']
            || !empty($merged_signals['event.lifecycle.moderation_hold']);
          break;

        case 'support_escalation':
          $flags['support_escalation_active'] = $flags['support_escalation_active']
            || !empty($merged_signals['support.flags.escalation']);
          break;

        case 'dispute_refund_continuity':
          $flags['commerce_dispute_context_active'] = $flags['commerce_dispute_context_active']
            || ($route_name !== '' && str_starts_with($route_name, 'myeventlane_refunds.'));
          break;
      }
    }

    return $flags;
  }

}
