<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\myeventlane_core\MelSurfaceId;

/**
 * Canonical behavioural contract for a MEL workflow (UX orchestration only).
 *
 * Drupal workflows, Commerce checkout, and onboarding entities remain source
 * of truth for business state; this definition describes how the surface layer
 * should guide, sequence, and reassure.
 */
final readonly class WorkflowDefinition {

  /**
   * @param list<string> $surfaceOwnership
   *   Values must match \Drupal\myeventlane_core\MelSurfaceId::value.
   * @param list<string> $requiredSignals
   *   Signal keys that must read TRUE while the workflow is considered “in flight”.
   * @param list<string> $completionSignals
   *   When all are TRUE, completion UX should be shown and prompts suppressed.
   * @param list<string> $nextStepRecommendations
   *   Other workflow IDs that typically follow this one (hints for copy/links).
   * @param list<string> $activationRoutePrefixes
   * @param list<string> $activationPathPrefixes
   * @param list<string> $activationExactRoutes
   * @param list<string> $activationRequireAllSignals
   *   Additional gates — every listed signal must be TRUE for activation.
   * @param list<string> $completionExactRoutes
   *   When the current route matches any entry, treat completion UX as appropriate.
   */
  public function __construct(
    public string $id,
    public string $domain,
    public array $surfaceOwnership,
    public array $requiredSignals,
    public array $completionSignals,
    public array $nextStepRecommendations,
    public int $ctaPriority,
    public string $accessibilityNotes,
    public array $activationRoutePrefixes = [],
    public array $activationPathPrefixes = [],
    public array $activationExactRoutes = [],
    public array $activationRequireAllSignals = [],
    public array $completionExactRoutes = [],
    public bool $requireAuthentication = FALSE,
    public ?string $primaryCtaRouteName = NULL,
    public ?string $primaryCtaTitle = NULL,
    public bool $delegateVendorNextStepToOnboardingManager = FALSE,
  ) {}

}
