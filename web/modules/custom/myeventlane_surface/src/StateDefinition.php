<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Canonical interpretation contract for a MEL state facet.
 *
 * Drupal entities, Commerce, onboarding entities, and moderation systems remain
 * the sources of truth; this definition documents how surfaces may interpret
 * merged workflow signals and optional domain facts contributed via alter hooks.
 */
final readonly class StateDefinition {

  /**
   * @param list<string> $surfaceOwnership
   *   MelSurfaceId enum values; empty list means any surface may evaluate.
   * @param list<string> $sourceSystems
   *   Logical owners (e.g. onboarding, commerce_order, moderation).
   * @param list<string> $requiredPositiveSignals
   *   Keys that must all be truthy for Satisfied when no blocking signal fires.
   * @param list<string> $blockingSignals
   *   Any truthy key forces Unsatisfied for this contract when interpreting
   *   publish/readiness adjacency (e.g. moderation hold vs publishable).
   */
  public function __construct(
    public string $id,
    public MelStateContractCategory $category,
    public array $surfaceOwnership,
    public array $sourceSystems,
    public string $interpretationRuleSummary,
    public array $requiredPositiveSignals,
    public array $blockingSignals,
    public MelStateSeverity $severity,
    public string $uxImplications,
    public string $ctaImplications,
    public string $accessibilityNotes,
    public bool $requiresAuthentication = FALSE,
    public bool $publicSafe = FALSE,
  ) {}

}
