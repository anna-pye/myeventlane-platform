<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Canonical operational policy interpretation for MEL surfaces.
 *
 * Permissions, moderation enforcement, Commerce, and workflows remain authoritative;
 * this manager exposes explainable interpretation, suppression, and tone hints only.
 */
final class MelOperationalPolicyManager {

  use StringTranslationTrait;

  public function __construct(
    private readonly MelOperationalPolicyRegistry $registry,
    private readonly MelPolicyResolver $policyResolver,
    private readonly MelStateRegistry $stateRegistry,
    private readonly MelStateResolver $stateResolver,
    private readonly MelTrustThresholdHelper $trustThresholdHelper,
    private readonly MelEscalationPolicyHelper $escalationPolicyHelper,
    private readonly MelSuppressionPolicyHelper $suppressionPolicyHelper,
    private readonly MelAutomationBoundaryHelper $automationBoundaryHelper,
    private readonly MelCommunicationPolicyHelper $communicationPolicyHelper,
    private readonly MelPolicyAccessibilityHelper $policyAccessibilityHelper,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LoggerChannelInterface $logger,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Portable policy interpretation payload for preprocess and downstream systems.
   *
   * @return array<string, mixed>
   */
  public function buildPageInterpretation(): array {
    try {
      $context = $this->stateResolver->buildWorkflowContext();
      $merged = $this->stateResolver->mergeDomainSignals($context);
      $definitions = $this->stateRegistry->all();
      $evaluations = [];
      foreach ($definitions as $id => $definition) {
        $evaluations[$id] = $this->stateResolver->evaluate($definition, $context, $merged)->value;
      }

      $active_policy_ids = $this->policyResolver->resolveActivePolicyIds($context, $merged, $evaluations);
      $suppression = $this->suppressionPolicyHelper->suppressionMap($active_policy_ids);
      $escalation = $this->escalationPolicyHelper->interpret($active_policy_ids, $merged);
      $automation = $this->automationBoundaryHelper->interpret($context, $evaluations, $merged);
      $communication_hints = $this->communicationPolicyHelper->activeToneHints($active_policy_ids);
      $trust_thresholds = $this->trustThresholdHelper->interpret($context->surface, $active_policy_ids);
      $intelligence_suppress_ids = $this->suppressionPolicyHelper->intelligenceDefinitionIdsToSuppress(
        $suppression,
        $context->surface,
      );

      $interpretation = [
        'surface_policy_profile' => $this->surfacePolicyProfile($context->surface, $context->checkoutSensitive),
        'active_policy_ids' => $active_policy_ids,
        'registry_snapshot' => $this->registrySnapshotForStaff($context->surface),
        'trust_thresholds' => $trust_thresholds,
        'escalation' => $escalation,
        'suppression' => $suppression,
        'automation' => $automation,
        'communication_hints' => $communication_hints,
        'lifecycle_safety' => $this->lifecycleSafetyFlags($active_policy_ids),
        'automation_contract_reference' => $this->serialiseAutomationReference(),
        'intelligence_governance' => [
          'suppress_definition_ids' => $intelligence_suppress_ids,
          'orchestration_cap_reduction' => $this->orchestrationCapReduction($suppression, $communication_hints),
        ],
        'experience_governance' => [
          'elevate_competing_cta_suppression' => !empty($suppression['suppress_cross_sell_guidance'])
            || !empty($suppression['suppress_marketing_guidance']),
        ],
        'explainability' => $this->buildExplainabilityLines($active_policy_ids, $suppression),
        'privacy' => [
          'vendor_isolation' => TRUE,
          'no_moderation_internals' => TRUE,
          'no_cross_account_signals' => TRUE,
          'staff_registry_visible' => $context->surface === MelSurfaceId::Staff,
        ],
        'accessibility' => [
          'polite_policy_region' => $this->policyAccessibilityHelper->politePolicyRegionAttributes(),
          'reduced_motion' => $this->policyAccessibilityHelper->reducedMotionDataAttribute(),
          'wcag_notes' => $this->policyAccessibilityHelper->wcagNotes(),
        ],
        '#cache' => [
          'contexts' => ['route', 'user', 'user.permissions', 'languages:language_interface'],
          'tags' => [],
        ],
      ];

      if ($context->account->isAuthenticated()) {
        $interpretation['#cache']['tags'][] = 'user:' . $context->account->id();
      }

      try {
        $this->moduleHandler->alter('mel_operational_policy_interpretation', $interpretation, $context, $merged, $evaluations);
      }
      catch (\Throwable $e) {
        // Alter hooks must never suppress canonical interpretation; log and continue.
        $this->logger->warning('mel_operational_policy_interpretation alter failed: @message', [
          '@message' => $e->getMessage(),
        ]);
      }

      return $interpretation;
    }
    catch (\Throwable $e) {
      $this->logger->error('MEL operational policy interpretation failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [
        'surface_policy_profile' => 'unknown',
        'active_policy_ids' => [],
        'registry_snapshot' => [],
        'trust_thresholds' => [],
        'escalation' => [],
        'suppression' => [],
        'automation' => [],
        'communication_hints' => [],
        'lifecycle_safety' => [],
        'intelligence_governance' => [
          'suppress_definition_ids' => [],
          'orchestration_cap_reduction' => 0,
        ],
        'experience_governance' => [
          'elevate_competing_cta_suppression' => FALSE,
        ],
        'automation_contract_reference' => [],
        'explainability' => [
          'lines' => [],
          'framework' => 'unavailable',
        ],
        'privacy' => [
          'vendor_isolation' => TRUE,
          'no_moderation_internals' => TRUE,
          'no_cross_account_signals' => TRUE,
          'staff_registry_visible' => FALSE,
        ],
        'accessibility' => [
          'polite_policy_region' => $this->policyAccessibilityHelper->politePolicyRegionAttributes(),
          'reduced_motion' => $this->policyAccessibilityHelper->reducedMotionDataAttribute(),
          'wcag_notes' => $this->policyAccessibilityHelper->wcagNotes(),
        ],
        '#cache' => [
          'contexts' => ['route', 'user'],
          'max-age' => 0,
        ],
      ];
    }
  }

  /**
   * @param list<string> $active_policy_ids
   *
   * @return array<string, bool>
   */
  private function lifecycleSafetyFlags(array $active_policy_ids): array {
    $ids = [
      'stale_draft_protection',
      'abandoned_checkout_safety',
      'inactive_vendor_safeguard',
      'cancelled_event_suppression',
    ];
    $out = [];
    foreach ($ids as $id) {
      $out[$id] = in_array($id, $active_policy_ids, TRUE);
    }
    return $out;
  }

  /**
   * @param array<string, bool> $suppression
   * @param array<string, bool> $communication_hints
   */
  private function orchestrationCapReduction(array $suppression, array $communication_hints): int {
    $n = 0;
    if (!empty($suppression['suppress_recommendations'])) {
      $n += 1;
    }
    if (!empty($communication_hints['reduced_urgency_mode'])) {
      $n += 1;
    }
    if (!empty($suppression['suppress_nonessential_notifications'])) {
      $n += 1;
    }
    return min($n, 3);
  }

  /**
   * @param list<string> $active_policy_ids
   * @param array<string, bool> $suppression
   *
   * @return array<string, mixed>
   */
  private function buildExplainabilityLines(array $active_policy_ids, array $suppression): array {
    $lines = [];
    foreach ($active_policy_ids as $id) {
      $lines[] = $this->explainLineForPolicyId($id);
    }
    if (!empty($suppression['suppress_recommendations']) && !in_array('suppress_recommendations', $active_policy_ids, TRUE)) {
      $lines[] = $this->t('Some suggestions are hidden because this screen is checkout-sensitive or trust-sensitive.');
    }
    return [
      'lines' => array_values(array_filter($lines)),
      'framework' => $this->t('Operational policy explains UX density and tone hints only; enforcement stays in Drupal, Commerce, and moderation systems.'),
    ];
  }

  private function explainLineForPolicyId(string $id): ?string {
    return match ($id) {
      'moderation_attention_required' => $this->t('Trust-sensitive layout is active to keep moderation details private.'),
      'moderation_safe_tone' => $this->t('Moderation-safe tone hints apply; operational detail stays within staff tools.'),
      'payout_review_required' => $this->t('Payout readiness is still completing; promotional prompts are reduced.'),
      'escalation_review_required', 'escalation_tone' => $this->t('Escalation continuity is prioritised over growth prompts.'),
      'suppress_recommendations' => $this->t('Recommendations are reduced to avoid competing with your primary task.'),
      'suppress_marketing_guidance' => $this->t('Marketing-style prompts are reduced on this screen.'),
      'suppress_boost_prompts' => $this->t('Boost prompts are hidden while trust or payout requirements need attention.'),
      'suppress_nonessential_notifications' => $this->t('Nonessential prompts are reduced to lower interruption density.'),
      'suppress_cross_sell_guidance' => $this->t('Cross-sell guidance is reduced to protect checkout focus.'),
      'abandoned_checkout_safety' => $this->t('Checkout recovery tone is emphasised; Commerce still owns payment steps.'),
      'cancelled_event_suppression' => $this->t('Growth prompts are reduced for cancelled events.'),
      'inactive_vendor_safeguard' => $this->t('Vendor setup prompts stay focused until onboarding completes.'),
      'stale_draft_protection' => $this->t('Draft lifecycle prompts favour completion over promotion.'),
      'trust_sensitive_tone', 'trust_warning_active' => $this->t('Messaging follows trust-sensitive guidance without exposing internal review detail.'),
      'reduced_urgency_mode' => $this->t('Urgency and promotional cadence are intentionally lowered.'),
      'reassurance_priority' => $this->t('Reassurance is emphasised for this customer moment.'),
      'verified_vendor_required' => $this->t('Verification prompts stay factual until the verified vendor signal is satisfied.'),
      default => NULL,
    };
  }

  /**
   * @return array<string, array<string, mixed>>
   */
  private function serialiseAutomationReference(): array {
    $out = [];
    foreach ($this->registry->automationReference() as $def) {
      $out[$def->id] = [
        'id' => $def->id,
        'category' => $def->category->value,
        'interpretation_rule_summary' => $def->interpretationRuleSummary,
        'source_systems' => $def->sourceSystems,
        'privacy_boundaries' => $def->privacyBoundaries,
        'accessibility_notes' => $def->accessibilityNotes,
      ];
    }
    return $out;
  }

  private function surfacePolicyProfile(MelSurfaceId $surface, bool $checkout_sensitive): string {
    if ($checkout_sensitive) {
      return 'checkout_minimal_reassuring';
    }
    return match ($surface) {
      MelSurfaceId::Customer => 'customer_shell_reassuring',
      MelSurfaceId::Vendor => 'vendor_shell_operational_trust_aware',
      MelSurfaceId::Staff => 'staff_surface_diagnostic',
      MelSurfaceId::Public => 'public_surface_minimal_trust',
      MelSurfaceId::Auth => 'auth_surface_minimal',
    };
  }

  /**
   * @return list<array<string, string>>
   */
  private function registrySnapshotForStaff(MelSurfaceId $surface): array {
    if ($surface !== MelSurfaceId::Staff) {
      return [];
    }
    $out = [];
    foreach ($this->registry->all() as $def) {
      $out[] = [
        'id' => $def->id,
        'category' => $def->category->value,
        'interpretation_rule_summary' => $def->interpretationRuleSummary,
      ];
    }
    return $out;
  }

}
