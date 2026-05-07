<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Sequencing and density governance for intelligence (orchestration hints only).
 */
final class MelEngagementOrchestrator {

  /**
   * @param list<IntelligenceDefinition> $ranked
   * @param array<string, string> $adaptiveProfiles
   *
   * @return array<string, mixed>
   */
  public function orchestrate(
    MelWorkflowContext $context,
    array $ranked,
    MelIntelligenceShellProfile $shell,
    array $adaptiveProfiles,
  ): array {
    $max = $this->maxVisible($shell);
    $deduped = $this->dedupeOnboardingContinuation($ranked);
    $trimmed = array_slice($deduped, 0, $max);

    $messaging_hints = [];
    foreach ($trimmed as $definition) {
      if ($definition->orchestrationKind === MelEngagementOrchestrationKind::None) {
        continue;
      }
      $messaging_hints[] = [
        'intelligence_id' => $definition->id,
        'kind' => $definition->orchestrationKind->value,
        'note' => 'Owning messaging systems must send; this row is a non-binding hint only.',
      ];
    }

    return [
      'visible_cap' => $max,
      'visible_ids' => array_map(static fn (IntelligenceDefinition $d): string => $d->id, $trimmed),
      'suppressed_ids' => array_map(static fn (IntelligenceDefinition $d): string => $d->id, array_slice($deduped, $max)),
      'density_policy' => $this->densityPolicyLabel($shell),
      'adaptive_profiles' => $adaptiveProfiles,
      'messaging_hints' => $messaging_hints,
    ];
  }

  private function maxVisible(MelIntelligenceShellProfile $shell): int {
    return match ($shell) {
      MelIntelligenceShellProfile::Checkout => 1,
      MelIntelligenceShellProfile::PublicSurface => 2,
      MelIntelligenceShellProfile::StaffSurface => 6,
      MelIntelligenceShellProfile::AuthShell => 1,
      default => 4,
    };
  }

  /**
   * Keeps the highest-priority onboarding continuation item only (deterministic de-dupe).
   *
   * @param list<IntelligenceDefinition> $ranked
   *
   * @return list<IntelligenceDefinition>
   */
  private function dedupeOnboardingContinuation(array $ranked): array {
    $out = [];
    $onboarding_kept = FALSE;
    foreach ($ranked as $definition) {
      if ($definition->orchestrationKind === MelEngagementOrchestrationKind::OnboardingContinuation) {
        if ($onboarding_kept) {
          continue;
        }
        $onboarding_kept = TRUE;
      }
      $out[] = $definition;
    }
    return $out;
  }

  private function densityPolicyLabel(MelIntelligenceShellProfile $shell): string {
    return match ($shell) {
      MelIntelligenceShellProfile::Checkout => 'checkout_minimal_single_primary',
      MelIntelligenceShellProfile::PublicSurface => 'public_non_invasive_cap2',
      MelIntelligenceShellProfile::VendorShell => 'vendor_operational_cap4',
      MelIntelligenceShellProfile::CustomerShell => 'customer_supportive_cap4',
      MelIntelligenceShellProfile::StaffSurface => 'staff_diagnostic_cap6',
      MelIntelligenceShellProfile::AuthShell => 'auth_minimal_cap1',
    };
  }

}
