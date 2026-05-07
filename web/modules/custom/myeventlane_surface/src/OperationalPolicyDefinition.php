<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Canonical operational policy interpretation contract.
 *
 * Drupal permissions, moderation enforcement, Commerce, and entity access remain
 * authoritative; this object documents how surfaces may interpret signals.
 */
final readonly class OperationalPolicyDefinition {

  /**
   * @param list<MelPolicyActivationRule> $activationRules
   *   Policy is active when ANY rule fully matches.
   * @param list<string> $sourceSystems
   * @param list<string> $suppressionImplications
   *   Logical suppression keys implied when this policy is active.
   * @param list<string> $ctaImplications
   * @param list<string> $escalationImplications
   * @param list<string> $communicationImplications
   */
  public function __construct(
    public string $id,
    public MelOperationalPolicyCategory $category,
    public array $sourceSystems,
    public string $interpretationRuleSummary,
    public array $activationRules,
    public array $suppressionImplications,
    public array $ctaImplications,
    public array $escalationImplications,
    public array $communicationImplications,
    public string $privacyBoundaries,
    public string $accessibilityNotes,
  ) {}

}
