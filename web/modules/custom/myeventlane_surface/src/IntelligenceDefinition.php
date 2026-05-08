<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\myeventlane_core\MelSurfaceId;
use Drupal\myeventlane_core\MelStateEvaluation;

/**
 * Immutable intelligence orchestration contract (no business data, no scoring).
 */
final readonly class IntelligenceDefinition {

  /**
   * @param list<MelSurfaceId> $surfaceOwnership
   * @param list<string> $triggerRequireAllSignals
   * @param list<string> $triggerAnyOfSignals
   * @param list<string> $triggerBlockAnySignals
   * @param array<string, MelStateEvaluation> $stateExpectations
   * @param list<array<string, MelStateEvaluation>> $stateExpectationAlternativeGroups
   */
  public function __construct(
    public string $id,
    public MelIntelligenceContractCategory $category,
    public MelIntelligencePresentationLane $presentationLane,
    public array $surfaceOwnership,
    public bool $requiresAuthentication,
    public bool $excludeWhenCheckoutSensitive,
    public bool $checkoutMinimalLaneOnly,
    public array $triggerRequireAllSignals,
    public array $triggerAnyOfSignals,
    public array $triggerBlockAnySignals,
    public array $stateExpectations,
    public array $stateExpectationAlternativeGroups,
    public bool $requiresNonEmptyResume,
    public ?string $requiresRetentionTierMin,
    public int $priorityWeight,
    public int $priorityTier,
    public string $recommendationSourceKey,
    public string $ctaImplications,
    public string $retentionImplications,
    public string $explainabilityNotes,
    public string $accessibilityNotes,
    public string $privacyBoundaries,
    public MelEngagementOrchestrationKind $orchestrationKind,
  ) {}

}
