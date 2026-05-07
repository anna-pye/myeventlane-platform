<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Resolves observability visibility tier and contract applicability per surface.
 */
final class MelTraceResolver {

  public function __construct(
    private readonly MelObservabilityRegistry $registry,
  ) {}

  /**
   * Product envelope for explainability (orthogonal to Drupal permission checks).
   */
  public function maximumTierForSurface(MelSurfaceId $surface): MelObservabilityVisibilityLevel {
    return match ($surface) {
      MelSurfaceId::Staff => MelObservabilityVisibilityLevel::Diagnostic,
      MelSurfaceId::Vendor => MelObservabilityVisibilityLevel::Operational,
      MelSurfaceId::Customer, MelSurfaceId::Auth => MelObservabilityVisibilityLevel::Minimal,
      MelSurfaceId::Public => MelObservabilityVisibilityLevel::None,
    };
  }

  /**
   * Whether a contract may emit rows for this surface and tier.
   */
  public function isContractApplicable(
    ObservabilityDefinition $definition,
    MelSurfaceId $surface,
    MelObservabilityVisibilityLevel $tier,
  ): bool {
    if ($tier === MelObservabilityVisibilityLevel::None) {
      return FALSE;
    }
    if ($tier->value < $definition->minimumVisibility->value) {
      return FALSE;
    }
    foreach ($definition->surfaceVisibility as $allowed) {
      if ($allowed === $surface) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @return list<ObservabilityDefinition>
   */
  public function applicableDefinitions(
    MelSurfaceId $surface,
    MelObservabilityVisibilityLevel $tier,
  ): array {
    $out = [];
    foreach ($this->registry->all() as $definition) {
      if ($this->isContractApplicable($definition, $surface, $tier)) {
        $out[] = $definition;
      }
    }
    usort($out, static function (ObservabilityDefinition $a, ObservabilityDefinition $b): int {
      if ($a->sortOrder !== $b->sortOrder) {
        return $a->sortOrder <=> $b->sortOrder;
      }
      return strcmp($a->id, $b->id);
    });
    return $out;
  }

}
