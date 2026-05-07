<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_surface\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_surface\MelInsightHelper;
use Drupal\myeventlane_surface\MelReadinessHelper;
use Drupal\myeventlane_surface\MelStateEvaluation;
use Drupal\myeventlane_surface\MelSurfaceId;
use Drupal\myeventlane_surface\MelWorkflowContext;
use PHPUnit\Framework\TestCase;

/**
 * Recommendation presentation hygiene: no leakage phrasing / robotic dismissals.
 *
 * @coversDefaultClass \Drupal\myeventlane_surface\MelReadinessHelper
 *
 * @group myeventlane_surface
 */
final class MelRecommendationPresentationVocabularyTest extends TestCase {

  /**
   * Registry ids mirrored from MelIntelligenceRegistry — keep aligned when prompts change.
   *
   * @return list<string>
   */
  private function intelligenceRegistryIds(): array {
    return [
      'recommended_events',
      'continue_saved_event',
      'complete_profile_prompt',
      'add_to_calendar_prompt',
      'revisit_recent_event',
      'support_followup_prompt',
      'complete_vendor_setup',
      'connect_payouts',
      'publish_event_prompt',
      'boost_event_prompt',
      'low_ticket_sales_prompt',
      'attendee_engagement_prompt',
      'analytics_review_prompt',
      'complete_attendee_details',
      'retry_checkout',
      'add_wallet_prompt',
      'share_event_prompt',
      'order_followup_prompt',
      'draft_completion_prompt',
      'sold_out_momentum_prompt',
      'cancelled_event_followup',
      'moderation_recovery_prompt',
      're_engagement_prompt',
      'incomplete_onboarding_prompt',
      'incomplete_customer_onboarding_prompt',
      'return_to_platform_prompt',
      'trust_building_prompt',
      'staff_trust_escalation_diagnostic',
      'staff_moderation_hold_diagnostic',
      'public_discovery_events_hint',
    ];
  }

  /**
   * @return list<string>
   */
  private function forbiddenSubstrings(): array {
    return [
      'ignore this suggestion',
      'signals evaluated',
      'why you are seeing this',
      'discovery systems',
      'commerce-provided',
      'machine-like guidance',
      'behavioural framing',
      'behavioral framing',
    ];
  }

  public function testIntelligencePresentationDoesNotContainForbiddenPhrases(): void {
    $helper = new MelReadinessHelper($this->translatorStub());
    $forbidden = $this->forbiddenSubstrings();
    foreach (MelSurfaceId::cases() as $surface) {
      $chunks = [];
      foreach ([FALSE, TRUE] as $public_checkout_focus) {
        if ($public_checkout_focus && $surface !== MelSurfaceId::Public) {
          continue;
        }
        foreach ($helper->intelligencePanelThemeVariables($surface, $public_checkout_focus) as $line) {
          $chunks[] = (string) $line;
        }
      }
      foreach ($this->intelligenceRegistryIds() as $id) {
        $copy = $helper->intelligenceRecommendationCopy($id, $surface);
        $chunks[] = $copy['headline'];
        $chunks[] = $copy['action'];
      }
      $unknown = $helper->intelligenceRecommendationCopy('__unknown_fixture__', $surface);
      $chunks[] = $unknown['headline'];
      $chunks[] = $unknown['action'];
      $chunks[] = $helper->eventStudioIntelligenceEmptyHeading();
      $chunks[] = $helper->eventStudioIntelligenceEmptyBody();
      $chunks[] = $helper->eventStudioFieldTipsEmptyHeading();
      $this->assertStringContainsNoneOfIgnoringCase($chunks, $forbidden, 'surface:' . $surface->value);
    }
  }

  public function testInsightStringsStayProductOriented(): void {
    $insight = new MelInsightHelper($this->translatorStub());
    $account = $this->createMock(AccountInterface::class);

    $context_evaluations = [
      [MelSurfaceId::Vendor, ['stripe_connected' => MelStateEvaluation::Unsatisfied->value]],
      [MelSurfaceId::Vendor, [
        'stripe_connected' => MelStateEvaluation::Satisfied->value,
        'payout_ready' => MelStateEvaluation::Unsatisfied->value,
      ],
      ],
      [MelSurfaceId::Vendor, ['sold_out' => MelStateEvaluation::Satisfied->value]],
      [MelSurfaceId::Vendor, ['draft' => MelStateEvaluation::Satisfied->value]],
      [MelSurfaceId::Vendor, ['incomplete' => MelStateEvaluation::Satisfied->value]],
      [MelSurfaceId::Customer, ['profile_completed' => MelStateEvaluation::Unsatisfied->value]],
      [MelSurfaceId::Staff, ['moderation_hold' => MelStateEvaluation::Satisfied->value]],
      [MelSurfaceId::Staff, ['escalation_review' => MelStateEvaluation::Satisfied->value]],
    ];

    $chunks = [];
    foreach ($context_evaluations as [$surface, $evaluations]) {
      $ctx = new MelWorkflowContext($surface, 'r', '/', $account, FALSE, []);
      foreach ($insight->buildInsights($ctx, $evaluations) as $row) {
        $chunks[] = (string) ($row['headline'] ?? '');
      }
    }

    $this->assertNotSame([], $chunks);
    $this->assertStringContainsNoneOfIgnoringCase($chunks, $this->forbiddenSubstrings(), 'MelInsightHelper');
  }

  /**
   * @param list<string|\Stringable> $chunks
   * @param list<string> $forbidden
   */
  private function assertStringContainsNoneOfIgnoringCase(array $chunks, array $forbidden, string $context_label): void {
    $combined = strtolower(implode("\n", array_map(static fn ($c): string => (string) $c, $chunks)));
    foreach ($forbidden as $needle) {
      $this->assertStringNotContainsString(
        $needle,
        $combined,
        sprintf('%s must not contain "%s".', $context_label, $needle),
      );
    }
  }

  private function translatorStub(): TranslationInterface {
    $translator = $this->createMock(TranslationInterface::class);
    $translator
      ->method('translateString')
      ->willReturnCallback(static fn (TranslatableMarkup $markup): string => $markup->getUntranslatedString());
    return $translator;
  }

}
