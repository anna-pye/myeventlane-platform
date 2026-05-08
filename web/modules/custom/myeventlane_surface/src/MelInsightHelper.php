<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\myeventlane_core\MelSurfaceId;
use Drupal\myeventlane_core\MelStateEvaluation;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Deterministic, privacy-safe operational insight lines (orchestration copy only).
 */
final class MelInsightHelper {

  use StringTranslationTrait;

  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @param array<string, string> $evaluations
   *
   * @return list<array<string, mixed>>
   */
  public function buildInsights(MelWorkflowContext $context, array $evaluations): array {
    $insights = [];
    $satisfied = static fn (string $id): bool => ($evaluations[$id] ?? '') === MelStateEvaluation::Satisfied->value;
    $unsatisfied = static fn (string $id): bool => ($evaluations[$id] ?? '') === MelStateEvaluation::Unsatisfied->value;

    if ($context->surface === MelSurfaceId::Vendor) {
      if ($unsatisfied('stripe_connected')) {
        $insights[] = $this->insightLine(
          'vendor_payouts_not_connected',
          $this->t('Connect Stripe payouts when you are ready to sell paid tickets.'),
          ['trigger' => 'state.stripe_connected.unsatisfied'],
        );
      }
      if ($unsatisfied('payout_ready') && $satisfied('stripe_connected')) {
        $insights[] = $this->insightLine(
          'vendor_payouts_not_ready',
          $this->t('Stripe is linked — finish verification so payouts can go live.'),
          ['trigger' => 'state.payout_ready.unsatisfied'],
        );
      }
      if ($satisfied('sold_out')) {
        $insights[] = $this->insightLine(
          'vendor_event_sold_out',
          $this->t('At least one event is sold out — consider waitlists or follow-up dates from your workspace.'),
          ['trigger' => 'state.sold_out.satisfied'],
        );
      }
      if ($satisfied('draft') || $satisfied('incomplete')) {
        $insights[] = $this->insightLine(
          'vendor_draft_incomplete',
          $this->t('Draft or incomplete listings are waiting — open the editor when you want to finish and publish.'),
          ['trigger' => 'state.draft_or_incomplete.satisfied'],
        );
      }
    }

    if ($context->surface === MelSurfaceId::Customer) {
      if ($unsatisfied('profile_completed')) {
        $insights[] = $this->insightLine(
          'customer_profile_incomplete',
          $this->t('Adding a friendly name or photo keeps confirmations personalised.'),
          ['trigger' => 'state.profile_completed.unsatisfied'],
        );
      }
    }

    if ($context->surface === MelSurfaceId::Staff) {
      if ($satisfied('moderation_hold')) {
        $insights[] = $this->insightLine(
          'staff_moderation_active',
          $this->t('Operations: moderation hold signal is active (diagnostic).'),
          ['trigger' => 'state.moderation_hold.satisfied'],
        );
      }
      if ($satisfied('escalation_review')) {
        $insights[] = $this->insightLine(
          'staff_escalation_active',
          $this->t('Operations: escalation review signal is active (diagnostic).'),
          ['trigger' => 'state.escalation_review.satisfied'],
        );
      }
    }

    return $insights;
  }

  /**
   * @param array<string, string> $meta
   *
   * @return array<string, mixed>
   */
  private function insightLine(string $id, \Stringable $headline, array $meta): array {
    return [
      'id' => $id,
      'headline' => $headline,
      'meta' => $meta,
    ];
  }

}
