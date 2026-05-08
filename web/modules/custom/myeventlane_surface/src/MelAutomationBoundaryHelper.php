<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\myeventlane_core\MelSurfaceId;
use Drupal\myeventlane_core\MelStateEvaluation;

/**
 * Automation boundary hints (when prompts may appear — interpretation only).
 */
final class MelAutomationBoundaryHelper {

  /**
   * @param array<string, string> $evaluations
   * @param array<string, bool|int|string> $merged
   *
   * @return array<string, bool>
   */
  public function interpret(
    MelWorkflowContext $context,
    array $evaluations,
    array $merged,
  ): array {
    $mod = ($evaluations['moderation_hold'] ?? '') === MelStateEvaluation::Satisfied->value;
    $esc = ($evaluations['escalation_review'] ?? '') === MelStateEvaluation::Satisfied->value
      || !empty($merged['support.flags.escalation']);
    $checkout_block = $context->checkoutSensitive && !empty($merged['route.commerce_checkout']);
    $block_engagement = $mod || $esc || $checkout_block;
    $payment_processing = ($evaluations['payment_processing'] ?? '') === MelStateEvaluation::Satisfied->value;

    return [
      'allow_engagement_guidance' => !$block_engagement,
      'allow_recovery_guidance' => !$payment_processing,
      'allow_resume_prompts' => !$esc,
      'restrict_escalation_prompts' => $context->surface === MelSurfaceId::Public
        || $context->surface === MelSurfaceId::Auth,
      'restrict_checkout_interruptions' => $context->checkoutSensitive || $payment_processing,
    ];
  }

}
