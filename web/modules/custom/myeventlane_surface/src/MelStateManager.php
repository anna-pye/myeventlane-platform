<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Canonical interpretation layer for readiness, lifecycle, trust, and eligibility UX.
 */
final class MelStateManager {

  use StringTranslationTrait;

  public function __construct(
    private readonly MelStateRegistry $registry,
    private readonly MelStateResolver $resolver,
    private readonly MelReadinessHelper $readinessHelper,
    private readonly MelLifecycleHelper $lifecycleHelper,
    private readonly MelTrustStateHelper $trustStateHelper,
    private readonly MelEligibilityHelper $eligibilityHelper,
    private readonly MelStateAccessibilityHelper $stateAccessibilityHelper,
    private readonly LoggerChannelInterface $logger,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Portable interpretation payload for preprocess and downstream MEL systems.
   *
   * @return array<string, mixed>
   */
  public function buildPageInterpretation(): array {
    try {
      $context = $this->resolver->buildWorkflowContext();
      $merged = $this->resolver->mergeDomainSignals($context);
      $definitions = $this->registry->all();
      $evaluations = [];
      foreach ($definitions as $id => $definition) {
        $evaluations[$id] = $this->resolver->evaluate($definition, $context, $merged);
      }

      $grouped = [];
      foreach ($evaluations as $id => $evaluation) {
        $category = $definitions[$id]->category->value;
        $grouped[$category][$id] = $evaluation->value;
      }

      $tone = $this->surfaceTone($context->surface);
      $primary_lifecycle = $this->lifecycleHelper->resolvePrimaryLifecycleId($evaluations);
      $trust_visible = $this->trustStateHelper->visibleTrustStateIds($context->surface, $evaluations, $definitions);
      $features = $this->eligibilityHelper->satisfiedFeatureIds($this->registry, $evaluations);
      $readiness_summaries = $this->readinessHelper->buildSummaries($context->surface, $evaluations, $merged);

      $cta_governance = [
        'defer_workflow_primary' => $this->shouldDeferWorkflowPrimaryFromMerged($merged),
        'elevate_stripe_cta' => $this->shouldElevateStripeCta($context, $merged, $evaluations),
        'hide_publish_cta' => ($evaluations['publishable'] ?? NULL) !== MelStateEvaluation::Satisfied,
        'hide_boost_cta' => ($evaluations['boost_eligible'] ?? NULL) !== MelStateEvaluation::Satisfied,
        'hide_export_cta' => ($evaluations['export_eligible'] ?? NULL) !== MelStateEvaluation::Satisfied,
      ];

      // Export remains permission-governed server-side; interpretation narrows UX only.
      $interpretation = [
        'tone' => $tone,
        'surface' => $context->surface->value,
        'evaluations' => array_map(static fn (MelStateEvaluation $e): string => $e->value, $evaluations),
        'grouped' => $grouped,
        'primary_lifecycle_id' => $primary_lifecycle,
        'trust_indicator_ids' => $trust_visible,
        'feature_eligibility_ids' => $features,
        'readiness_summaries' => $readiness_summaries,
        'cta_governance' => $cta_governance,
        'accessibility' => [
          'polite_status_attributes' => $this->stateAccessibilityHelper->politeStatusRegionAttributes(),
          'assertive_status_attributes' => $this->stateAccessibilityHelper->assertiveStatusRegionAttributes(),
          'reduced_motion_data' => $this->stateAccessibilityHelper->reducedMotionDataAttribute(),
        ],
        '#cache' => [
          'contexts' => ['route', 'user', 'user.permissions', 'languages:language_interface'],
          'tags' => [],
        ],
      ];

      if ($context->account->isAuthenticated()) {
        $interpretation['#cache']['tags'][] = 'user:' . $context->account->id();
      }

      return $interpretation;
    }
    catch (\Throwable $e) {
      $this->logger->error('MEL state interpretation failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [
        'tone' => 'unknown',
        'surface' => '',
        'evaluations' => [],
        'grouped' => [],
        'primary_lifecycle_id' => NULL,
        'trust_indicator_ids' => [],
        'feature_eligibility_ids' => [],
        'readiness_summaries' => [],
        'cta_governance' => [
          'defer_workflow_primary' => FALSE,
          'elevate_stripe_cta' => FALSE,
          'hide_publish_cta' => TRUE,
          'hide_boost_cta' => TRUE,
          'hide_export_cta' => TRUE,
        ],
        'accessibility' => [
          'polite_status_attributes' => $this->stateAccessibilityHelper->politeStatusRegionAttributes(),
          'assertive_status_attributes' => $this->stateAccessibilityHelper->assertiveStatusRegionAttributes(),
          'reduced_motion_data' => $this->stateAccessibilityHelper->reducedMotionDataAttribute(),
        ],
        '#cache' => [
          'contexts' => ['route', 'user'],
          'max-age' => 0,
        ],
      ];
    }
  }

  /**
   * Workflow primary CTA deferral — interpretation only (Commerce/checkout untouched).
   */
  public function shouldSuppressPrimaryWorkflowCta(MelWorkflowContext $context): bool {
    $merged = $this->resolver->mergeDomainSignals($context);
    return $this->shouldDeferWorkflowPrimaryFromMerged($merged);
  }

  /**
   * @param array<string, bool|int|string> $merged
   */
  private function shouldDeferWorkflowPrimaryFromMerged(array $merged): bool {
    if (!empty($merged['checkout.state.payment_processing']) && !empty($merged['route.commerce_checkout'])) {
      return TRUE;
    }
    if (!empty($merged['event.lifecycle.moderation_hold'])) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * @param array<string, bool|int|string> $merged
   * @param array<string, \Drupal\myeventlane_surface\MelStateEvaluation> $evaluations
   */
  private function shouldElevateStripeCta(MelWorkflowContext $context, array $merged, array $evaluations): bool {
    if ($context->surface !== MelSurfaceId::Vendor) {
      return FALSE;
    }
    if (empty($merged['vendor.guidance.needs_resume'])) {
      return FALSE;
    }
    return ($evaluations['stripe_connected'] ?? NULL) === MelStateEvaluation::Unsatisfied;
  }

  private function surfaceTone(MelSurfaceId $surface): string {
    return match ($surface) {
      MelSurfaceId::Customer => 'friendly_reassuring',
      MelSurfaceId::Vendor => 'operational_revenue',
      MelSurfaceId::Staff => 'administrative_diagnostic',
      MelSurfaceId::Public, MelSurfaceId::Auth => 'minimal_trust',
    };
  }

}
