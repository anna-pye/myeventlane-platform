<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Canonical orchestration entry point for MEL experience continuity (UX coherence only).
 */
final class MelExperienceManager {

  use StringTranslationTrait;

  public function __construct(
    private readonly MelExperienceRegistry $experienceRegistry,
    private readonly MelWorkflowResolver $workflowResolver,
    private readonly MelWorkflowRegistry $workflowRegistry,
    private readonly MelStateResolver $stateResolver,
    private readonly MelJourneyResolver $journeyResolver,
    private readonly MelResumeStateHelper $resumeStateHelper,
    private readonly MelRetentionHelper $retentionHelper,
    private readonly MelContinuationHelper $continuationHelper,
    private readonly MelEscalationContinuityHelper $escalationContinuityHelper,
    private readonly MelExperienceAccessibilityHelper $experienceAccessibilityHelper,
    private readonly MelStateManager $stateManager,
    private readonly MelInteractionManager $interactionManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly RequestStack $requestStack,
    private readonly LoggerChannelInterface $logger,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Portable continuity payload for preprocess (Twig + attachments).
   *
   * @param array<string, mixed>|null $preloadedOperationalPolicy
   *   When provided (e.g. from SurfaceNegotiator or MelIntelligenceManager memo),
   *   merges MELOperationalPolicySystem hints without a circular DI dependency on
   *   MelOperationalPolicyManager.
   *
   * @return array<string, mixed>
   */
  public function buildPageAttachments(?array $preloadedOperationalPolicy = NULL): array {
    try {
      $context = $this->workflowResolver->buildContext();
      $merged = $this->stateResolver->mergeDomainSignals($context);
      $applicable = $this->journeyResolver->resolveApplicable($this->experienceRegistry, $context, $merged);
      $this->moduleHandler->alter('mel_experience_applicable', $applicable, $context, $merged);

      $resume = $this->resumeStateHelper->buildResumeCandidates($applicable, $context, $merged);
      $retention = $this->retentionHelper->buildRetentionProfile($applicable);
      $continuation = $this->continuationHelper->buildContinuationHints($applicable, $this->workflowRegistry);
      $escalation = $this->escalationContinuityHelper->buildSafeFlags($applicable, $merged, $context->routeName);

      $defer_workflow_primary = $this->stateManager->shouldSuppressPrimaryWorkflowCta($context);
      $interaction_ctx = $this->interactionManager->getPageContext();

      $memory = [];
      $request = $this->requestStack->getCurrentRequest();
      if ($request !== NULL) {
        $attr = $request->attributes->get('mel_experience_memory');
        $memory = is_array($attr) ? $attr : [];
      }
      $this->moduleHandler->alter('mel_experience_memory', $memory, $context, $merged);

      $primary = $this->pickPrimaryExperienceId($applicable, $resume);

      $policy_cta = FALSE;
      $policy_block = [
        'surface_policy_profile' => '',
        'active_policy_ids' => [],
        'suppression' => [],
      ];
      if ($preloadedOperationalPolicy !== NULL) {
        $policy_cta = !empty($preloadedOperationalPolicy['experience_governance']['elevate_competing_cta_suppression']);
        $policy_block = [
          'surface_policy_profile' => (string) ($preloadedOperationalPolicy['surface_policy_profile'] ?? ''),
          'active_policy_ids' => $preloadedOperationalPolicy['active_policy_ids'] ?? [],
          'suppression' => array_filter($preloadedOperationalPolicy['suppression'] ?? []),
        ];
      }

      $attachments = [
        'surface' => $context->surface->value,
        'applicable_ids' => array_map(static fn (ExperienceDefinition $d): string => $d->id, $applicable),
        'primary_experience_id' => $primary,
        'resume_candidates' => $resume,
        'retention' => $retention,
        'continuation' => $continuation,
        'escalation_safe' => $escalation,
        'cta_governance' => [
          'suppress_competing_discovery_cta' => $defer_workflow_primary
            || $resume !== []
            || !empty($escalation['moderation_hold_active'])
            || $policy_cta,
          'one_action_per_moment' => TRUE,
          'defer_workflow_primary' => $defer_workflow_primary,
        ],
        'interaction_profile' => $interaction_ctx['profile'] ?? NULL,
        'contextual_memory' => $memory,
        'operational_policy' => $policy_block,
        'accessibility' => [
          'continuation_landmark' => $this->experienceAccessibilityHelper->continuationLandmarkAttributes('mel-experience-continuation-heading'),
          'polite_live_region' => $this->experienceAccessibilityHelper->politeContinuationLiveAttributes(),
          'reduced_motion' => $this->experienceAccessibilityHelper->reducedMotionDataAttribute(),
          'notes' => $this->collectAccessibilityNotes($applicable),
        ],
        'privacy' => [
          'boundary_summaries' => $this->collectPrivacyBoundaries($applicable),
        ],
        '#cache' => [
          'contexts' => ['route', 'user', 'user.permissions', 'languages:language_interface'],
          'tags' => [],
        ],
      ];

      if ($context->account->isAuthenticated()) {
        $attachments['#cache']['tags'][] = 'user:' . $context->account->id();
      }

      $this->moduleHandler->alter('mel_experience_attachments', $attachments, $context, $merged);

      return $attachments;
    }
    catch (\Throwable $e) {
      $this->logger->error('MEL experience orchestration failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [
        'surface' => '',
        'applicable_ids' => [],
        'primary_experience_id' => NULL,
        'resume_candidates' => [],
        'retention' => ['tier' => MelRetentionTier::None->value, 'contributor_experience_ids' => [], 'implications' => []],
        'continuation' => ['cross_surface_hints' => [], 'workflow_edges' => [], 'cta_sequencing_notes' => []],
        'escalation_safe' => [
          'moderation_hold_active' => FALSE,
          'support_escalation_active' => FALSE,
          'commerce_dispute_context_active' => FALSE,
        ],
        'cta_governance' => [
          'suppress_competing_discovery_cta' => FALSE,
          'one_action_per_moment' => TRUE,
          'defer_workflow_primary' => FALSE,
        ],
        'interaction_profile' => NULL,
        'contextual_memory' => [],
        'operational_policy' => [
          'surface_policy_profile' => '',
          'active_policy_ids' => [],
          'suppression' => [],
        ],
        'accessibility' => [
          'continuation_landmark' => $this->experienceAccessibilityHelper->continuationLandmarkAttributes('mel-experience-continuation-heading'),
          'polite_live_region' => $this->experienceAccessibilityHelper->politeContinuationLiveAttributes(),
          'reduced_motion' => $this->experienceAccessibilityHelper->reducedMotionDataAttribute(),
          'notes' => [],
        ],
        'privacy' => ['boundary_summaries' => []],
        '#cache' => [
          'contexts' => ['route', 'user'],
          'max-age' => 0,
        ],
      ];
    }
  }

  /**
   * @param list<\Drupal\myeventlane_surface\ExperienceDefinition> $applicable
   * @param list<array<string, mixed>> $resume
   */
  private function pickPrimaryExperienceId(array $applicable, array $resume): ?string {
    if ($resume !== []) {
      return (string) ($resume[0]['experience_id'] ?? '');
    }
    if ($applicable === []) {
      return NULL;
    }
    usort($applicable, static function (ExperienceDefinition $a, ExperienceDefinition $b): int {
      return $b->continuityPriority <=> $a->continuityPriority;
    });
    return $applicable[0]->id;
  }

  /**
   * @param list<\Drupal\myeventlane_surface\ExperienceDefinition> $applicable
   *
   * @return list<string>
   */
  private function collectAccessibilityNotes(array $applicable): array {
    $notes = [];
    foreach ($applicable as $definition) {
      if ($definition->accessibilityNotes !== '') {
        $notes[] = $definition->accessibilityNotes;
      }
    }
    return array_values(array_unique($notes));
  }

  /**
   * @param list<\Drupal\myeventlane_surface\ExperienceDefinition> $applicable
   *
   * @return list<string>
   */
  private function collectPrivacyBoundaries(array $applicable): array {
    $lines = [];
    foreach ($applicable as $definition) {
      if ($definition->privacyBoundaries !== '') {
        $lines[] = $definition->id . ': ' . $definition->privacyBoundaries;
      }
    }
    return $lines;
  }

}
