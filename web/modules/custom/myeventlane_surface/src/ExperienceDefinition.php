<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Canonical UX continuity contract (orchestration hints only).
 *
 * Drupal workflows, Commerce checkout, onboarding entities, permissions, and
 * moderation remain authoritative; this definition coordinates continuity,
 * sequencing memory, and privacy-safe messaging boundaries across surfaces.
 */
final readonly class ExperienceDefinition {

  /**
   * @param list<string> $surfaceOwnership
   *   Values must match \Drupal\myeventlane_surface\MelSurfaceId::value.
   * @param list<string> $linkedWorkflowIds
   *   References \Drupal\myeventlane_surface\MelWorkflowRegistry ids.
   * @param list<string> $continuityTriggerSignalsAll
   *   When non-empty, every listed workflow/domain signal must be truthy.
   * @param list<string> $continuityTriggerSignalsAny
   *   When non-empty, at least one listed signal must be truthy.
   * @param list<string> $resumeGateSignalsAll
   *   When non-empty and all truthy, resume-state orchestration may elevate this experience.
   * @param list<string> $crossSurfaceTransitions
   *   Hints such as "auth_to_customer"; templates consume as opaque labels only.
   * @param list<string> $activationRoutePrefixes
   * @param list<string> $activationPathPrefixes
   * @param list<string> $activationExactRoutes
   */
  public function __construct(
    public string $id,
    public MelExperienceContractCategory $category,
    public array $surfaceOwnership,
    public array $linkedWorkflowIds,
    public array $continuityTriggerSignalsAll,
    public array $continuityTriggerSignalsAny,
    public array $resumeGateSignalsAll,
    public int $continuityPriority,
    public MelRetentionTier $retentionTier,
    public string $retentionImplications,
    public string $ctaSequencingNotes,
    public string $accessibilityNotes,
    public string $privacyBoundaries,
    public array $crossSurfaceTransitions,
    public array $activationExactRoutes = [],
    public array $activationRoutePrefixes = [],
    public array $activationPathPrefixes = [],
    public bool $requireAuthentication = FALSE,
    public bool $activateUnderCheckoutSensitiveSurface = FALSE,
  ) {}

}
