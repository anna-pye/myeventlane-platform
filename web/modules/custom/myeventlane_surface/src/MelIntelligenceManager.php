<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\myeventlane_core\MelSurfaceId;
use Drupal\myeventlane_core\MelStateEvaluation;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\myeventlane_core\MelReadinessHelper;

/**
 * Canonical orchestration entry point for MEL intelligence (governance only).
 */
final class MelIntelligenceManager {

  public function __construct(
    private readonly MelIntelligenceRegistry $registry,
    private readonly MelWorkflowResolver $workflowResolver,
    private readonly MelStateResolver $stateResolver,
    private readonly MelStateRegistry $stateRegistry,
    private readonly MelExperienceManager $experienceManager,
    private readonly MelRecommendationResolver $recommendationResolver,
    private readonly MelPriorityResolver $priorityResolver,
    private readonly MelAdaptiveGuidanceHelper $adaptiveGuidanceHelper,
    private readonly MelEngagementOrchestrator $engagementOrchestrator,
    private readonly MelInsightHelper $insightHelper,
    private readonly MelIntelligenceAccessibilityHelper $intelligenceAccessibilityHelper,
    private readonly MelOperationalPolicyManager $operationalPolicyManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LoggerChannelInterface $logger,
    private readonly MelReadinessHelper $readinessHelper,
  ) {}

  /**
   * Portable intelligence payload for preprocess (Twig + attachments).
   *
   * @param array<string, mixed>|null $preloadedExperienceAttachments
   *   When provided (e.g. from SurfaceNegotiator memo), avoids duplicate
   *   MELExperienceSystem work per request.
   * @param array<string, mixed>|null $preloadedOperationalPolicy
   *   When provided, avoids duplicate MELOperationalPolicySystem work per request.
   *
   * @return array<string, mixed>
   */
  public function buildPagePayload(?array $preloadedExperienceAttachments = NULL, ?array $preloadedOperationalPolicy = NULL): array {
    try {
      $context = $this->workflowResolver->buildContext();
      $merged = $this->stateResolver->mergeDomainSignals($context);
      $state_definitions = $this->stateRegistry->all();
      $evaluations = [];
      foreach ($state_definitions as $id => $definition) {
        $evaluations[$id] = $this->stateResolver->evaluate($definition, $context, $merged)->value;
      }

      $policy = $preloadedOperationalPolicy ?? $this->operationalPolicyManager->buildPageInterpretation();
      $experience = $preloadedExperienceAttachments ?? $this->experienceManager->buildPageAttachments($policy);

      $intel_definitions = $this->registry->all();
      $applicable = $this->recommendationResolver->resolveApplicable(
        $intel_definitions,
        $context,
        $merged,
        $evaluations,
        $experience,
      );
      $ranked = $this->priorityResolver->rank($context->account, $applicable);
      $adaptive = $this->adaptiveGuidanceHelper->resolveProfiles($context, $merged, $experience);
      $shell = $this->resolveShellProfile($context);
      $orchestration = $this->applyOperationalPolicyToOrchestration(
        $this->engagementOrchestrator->orchestrate($context, $ranked, $shell, $adaptive),
        $policy,
        $context,
      );
      $visible_set = array_flip($orchestration['visible_ids']);

      $items = [];
      foreach ($ranked as $definition) {
        if (!isset($visible_set[$definition->id])) {
          continue;
        }
        $items[] = $this->buildItem($definition, $evaluations, $context);
      }

      $insights = $this->insightHelper->buildInsights($context, $evaluations);
      if ($context->surface !== MelSurfaceId::Staff) {
        $insights = array_values(array_filter(
          $insights,
          static fn (array $row): bool => str_starts_with((string) ($row['id'] ?? ''), 'staff_') === FALSE,
        ));
      }

      $payload = [
        'shell_profile' => $shell->value,
        'adaptive_profiles' => $adaptive,
        'prioritisation_trace' => $this->priorityResolver->explainOrder($ranked),
        'orchestration' => $orchestration,
        'items' => $items,
        'insights' => $insights,
        'explainability' => [
          'framework' => 'Each item includes why_shown, trigger_signal_keys, and recommended_action summary.',
          'no_hidden_scores' => TRUE,
          'dismiss_supported' => TRUE,
          'operational_policy_framework' => $policy['explainability']['framework'] ?? '',
          'operational_policy_lines' => $policy['explainability']['lines'] ?? [],
        ],
        'operational_policy' => [
          'active_policy_ids' => $policy['active_policy_ids'] ?? [],
          'communication_hints' => $policy['communication_hints'] ?? [],
          'suppression' => array_filter($policy['suppression'] ?? []),
          'automation' => $policy['automation'] ?? [],
          'surface_policy_profile' => $policy['surface_policy_profile'] ?? '',
        ],
        'accessibility' => [
          'region_attributes' => $this->intelligenceAccessibilityHelper->regionAttributes(),
          'polite_live_attributes' => $this->intelligenceAccessibilityHelper->politeLiveAttributes(),
          'reduced_motion' => $this->intelligenceAccessibilityHelper->reducedMotionDataAttribute(),
        ],
        'privacy' => [
          'vendor_isolation' => TRUE,
          'no_cross_account_reasons' => TRUE,
          'staff_diagnostic_only_on_staff' => $context->surface === MelSurfaceId::Staff,
        ],
        '#cache' => [
          'contexts' => ['route', 'user', 'user.permissions', 'languages:language_interface'],
          'tags' => [],
        ],
      ];

      if ($context->account->isAuthenticated()) {
        $payload['#cache']['tags'][] = 'user:' . $context->account->id();
      }

      $this->moduleHandler->alter('mel_intelligence_page_payload', $payload, $context, $merged);

      $this->suppressPublicGovernanceNoiseOffCheckout($payload, $context);

      return $payload;
    }
    catch (\Throwable $e) {
      $this->logger->error('MEL intelligence orchestration failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [
        'shell_profile' => MelIntelligenceShellProfile::CustomerShell->value,
        'adaptive_profiles' => [],
        'prioritisation_trace' => [],
        'orchestration' => [
          'visible_cap' => 0,
          'visible_ids' => [],
          'suppressed_ids' => [],
          'density_policy' => 'error_fallback',
          'adaptive_profiles' => [],
          'messaging_hints' => [],
        ],
        'items' => [],
        'insights' => [],
        'explainability' => [
          'framework' => 'unavailable',
          'no_hidden_scores' => TRUE,
          'dismiss_supported' => FALSE,
          'operational_policy_framework' => '',
          'operational_policy_lines' => [],
        ],
        'operational_policy' => [
          'active_policy_ids' => [],
          'communication_hints' => [],
          'suppression' => [],
          'automation' => [],
          'surface_policy_profile' => '',
        ],
        'accessibility' => [
          'region_attributes' => $this->intelligenceAccessibilityHelper->regionAttributes(),
          'polite_live_attributes' => $this->intelligenceAccessibilityHelper->politeLiveAttributes(),
          'reduced_motion' => $this->intelligenceAccessibilityHelper->reducedMotionDataAttribute(),
        ],
        'privacy' => [
          'vendor_isolation' => TRUE,
          'no_cross_account_reasons' => TRUE,
          'staff_diagnostic_only_on_staff' => FALSE,
        ],
        '#cache' => [
          'contexts' => ['route', 'user'],
          'max-age' => 0,
        ],
      ];
    }
  }

  /**
   * @param array<string, mixed> $orchestration
   * @param array<string, mixed> $policy
   *
   * @return array<string, mixed>
   */
  private function applyOperationalPolicyToOrchestration(
    array $orchestration,
    array $policy,
    MelWorkflowContext $context,
  ): array {
    $gov = $policy['intelligence_governance'] ?? [];
    $hidden = $gov['suppress_definition_ids'] ?? [];
    if (!is_array($hidden)) {
      $hidden = [];
    }
    $cap_reduction = (int) ($gov['orchestration_cap_reduction'] ?? 0);
    $visible_ids = $orchestration['visible_ids'] ?? [];
    if (!is_array($visible_ids)) {
      $visible_ids = [];
    }
    $before = $visible_ids;
    $filtered = array_values(array_filter(
      $visible_ids,
      static fn (string $id): bool => !in_array($id, $hidden, TRUE),
    ));
    $cap = (int) ($orchestration['visible_cap'] ?? count($filtered));
    $cap = max(1, $cap - $cap_reduction);
    $after = array_slice($filtered, 0, $cap);
    $removed = array_values(array_diff($before, $after));
    $suppressed = $orchestration['suppressed_ids'] ?? [];
    if (!is_array($suppressed)) {
      $suppressed = [];
    }
    $orchestration['visible_ids'] = $after;
    $orchestration['suppressed_ids'] = array_values(array_unique(array_merge($suppressed, $removed)));
    $orchestration['operational_policy_applied'] = TRUE;
    $orchestration['policy_surface_profile'] = (string) ($policy['surface_policy_profile'] ?? '');
    return $orchestration;
  }

  /**
   * Drops low-context public-discovery intelligence everywhere except checkout.
   *
   * Homepage, categories, listings, and static pages already carry editorial
   * marketing and trust cues; repeating generic “browse / trust” prompts as a
   * governance-style panel reduces clarity. Checkout Cart/Commerce routes keep
   * {@see MelIntelligencePresentationLane::CheckoutMinimal} prompts intact.
   *
   * @param array<string, mixed> $payload
   */
  private function suppressPublicGovernanceNoiseOffCheckout(array &$payload, MelWorkflowContext $context): void {
    if ($context->surface !== MelSurfaceId::Public) {
      return;
    }
    if ($context->checkoutSensitive) {
      return;
    }
    $items = $payload['items'] ?? [];
    if (!is_array($items) || $items === []) {
      return;
    }
    $lane = MelIntelligencePresentationLane::PublicDiscovery->value;
    $before_ids = array_column($items, 'id');
    $filtered = array_values(array_filter(
      $items,
      static fn (array $item): bool => ($item['presentation_lane'] ?? '') !== $lane,
    ));
    if (count($filtered) === count($items)) {
      return;
    }
    $payload['items'] = $filtered;
    $this->rewriteOrchestrationVisibleIdsAfterItemFilter($payload, $before_ids, $filtered);
  }

  /**
   * Keeps prioritisation envelopes aligned when items are stripped post-orchestration.
   *
   * @param array<string, mixed> $payload
   * @param list<string|int> $before_ids
   * @param list<array<string, mixed>> $filtered
   */
  private function rewriteOrchestrationVisibleIdsAfterItemFilter(array &$payload, array $before_ids, array $filtered): void {
    $orchestration = $payload['orchestration'] ?? [];
    if (!is_array($orchestration)) {
      return;
    }
    $kept_ids = array_column($filtered, 'id');
    $removed = array_values(array_diff($before_ids, $kept_ids));
    $orchestration['visible_ids'] = $kept_ids;
    $suppressed = $orchestration['suppressed_ids'] ?? [];
    if (!is_array($suppressed)) {
      $suppressed = [];
    }
    $orchestration['suppressed_ids'] = array_values(array_unique(array_merge($suppressed, $removed)));
    $payload['orchestration'] = $orchestration;
  }

  private function resolveShellProfile(MelWorkflowContext $context): MelIntelligenceShellProfile {
    if ($context->surface === MelSurfaceId::Staff) {
      return MelIntelligenceShellProfile::StaffSurface;
    }
    if ($context->checkoutSensitive) {
      return MelIntelligenceShellProfile::Checkout;
    }
    return match ($context->surface) {
      MelSurfaceId::Vendor => MelIntelligenceShellProfile::VendorShell,
      MelSurfaceId::Customer => MelIntelligenceShellProfile::CustomerShell,
      MelSurfaceId::Public => MelIntelligenceShellProfile::PublicSurface,
      MelSurfaceId::Auth => MelIntelligenceShellProfile::AuthShell,
      default => MelIntelligenceShellProfile::PublicSurface,
    };
  }

  /**
   * @param array<string, bool|int|string> $merged
   * @param array<string, string> $evaluations
   *
   * @return array<string, mixed>
   */
  private function buildItem(
    IntelligenceDefinition $definition,
    array $evaluations,
    MelWorkflowContext $context,
  ): array {
    $copy = $this->readinessHelper->intelligenceRecommendationCopy(
      $definition->id,
      $context->surface,
    );
    $state_trigger_ids = array_keys($definition->stateExpectations);
    foreach ($definition->stateExpectationAlternativeGroups as $group) {
      $state_trigger_ids = array_merge($state_trigger_ids, array_keys($group));
    }
    $trigger_keys = array_values(array_unique(array_merge(
      $definition->triggerRequireAllSignals,
      $definition->triggerAnyOfSignals,
      $state_trigger_ids,
      array_map(
        static fn (string $key): string => 'suppress_when_active:' . $key,
        $definition->triggerBlockAnySignals,
      ),
    )));

    return [
      'id' => $definition->id,
      'category' => $definition->category->value,
      'presentation_lane' => $definition->presentationLane->value,
      'headline' => $copy['headline'],
      'recommended_action' => $copy['action'],
      'dismissible' => TRUE,
      'why_shown' => $definition->explainabilityNotes,
      'trigger_signal_keys' => $trigger_keys,
      'state_snapshot' => $this->stateSnapshotForDefinition($definition, $evaluations),
      'recommendation_source_key' => $definition->recommendationSourceKey,
      'cta_implications' => $definition->ctaImplications,
      'retention_implications' => $definition->retentionImplications,
      'privacy_boundaries' => $definition->privacyBoundaries,
      'accessibility_notes' => $definition->accessibilityNotes,
      'orchestration_kind' => $definition->orchestrationKind->value,
    ];
  }

  /**
   * @param array<string, string> $evaluations
   *
   * @return array<string, string>
   */
  private function stateSnapshotForDefinition(IntelligenceDefinition $definition, array $evaluations): array {
    $ids = array_keys($definition->stateExpectations);
    foreach ($definition->stateExpectationAlternativeGroups as $group) {
      $ids = array_merge($ids, array_keys($group));
    }
    $ids = array_values(array_unique($ids));
    $snap = [];
    foreach ($ids as $stateId) {
      $snap[$stateId] = (string) ($evaluations[$stateId] ?? MelStateEvaluation::Unknown->value);
    }
    return $snap;
  }

}
