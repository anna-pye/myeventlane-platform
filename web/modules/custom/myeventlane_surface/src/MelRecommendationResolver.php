<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\myeventlane_core\MelSurfaceId;
use Drupal\myeventlane_core\MelStateEvaluation;

/**
 * Filters intelligence contracts using deterministic triggers (no scoring).
 */
final class MelRecommendationResolver {

  /**
   * @param array<string, IntelligenceDefinition> $definitions
   * @param array<string, string> $evaluations
   *   State id => MelStateEvaluation::value
   * @param array<string, mixed> $experienceAttachments
   *
   * @return list<IntelligenceDefinition>
   */
  public function resolveApplicable(
    array $definitions,
    MelWorkflowContext $context,
    array $merged,
    array $evaluations,
    array $experienceAttachments,
  ): array {
    $out = [];
    foreach ($definitions as $definition) {
      if (!$this->matchesSurface($definition, $context)) {
        continue;
      }
      if (!$this->matchesPresentationLane($definition, $context)) {
        continue;
      }
      if (!$this->matchesCheckoutRules($definition, $context)) {
        continue;
      }
      if ($definition->requiresAuthentication && !$context->account->isAuthenticated()) {
        continue;
      }
      if (!$this->signalsRequireAll($definition->triggerRequireAllSignals, $merged)) {
        continue;
      }
      if (!$this->signalsAnyOf($definition->triggerAnyOfSignals, $merged)) {
        continue;
      }
      if ($this->signalsBlocked($definition->triggerBlockAnySignals, $merged)) {
        continue;
      }
      if (!$this->stateMatches($definition, $evaluations)) {
        continue;
      }
      if ($definition->requiresNonEmptyResume && !$this->hasResumeCandidates($experienceAttachments)) {
        continue;
      }
      if (!$this->retentionSatisfied($definition, $experienceAttachments)) {
        continue;
      }
      $out[] = $definition;
    }
    return $out;
  }

  private function matchesSurface(IntelligenceDefinition $definition, MelWorkflowContext $context): bool {
    foreach ($definition->surfaceOwnership as $surface) {
      if ($surface === $context->surface) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function matchesPresentationLane(IntelligenceDefinition $definition, MelWorkflowContext $context): bool {
    if ($context->surface === MelSurfaceId::Staff) {
      return $definition->presentationLane === MelIntelligencePresentationLane::StaffDiagnostic;
    }
    return $definition->presentationLane !== MelIntelligencePresentationLane::StaffDiagnostic;
  }

  private function matchesCheckoutRules(IntelligenceDefinition $definition, MelWorkflowContext $context): bool {
    if ($context->checkoutSensitive) {
      if ($definition->excludeWhenCheckoutSensitive) {
        return FALSE;
      }
    }
    elseif ($definition->checkoutMinimalLaneOnly) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * @param list<string> $keys
   * @param array<string, bool|int|string> $merged
   */
  private function signalsRequireAll(array $keys, array $merged): bool {
    foreach ($keys as $key) {
      if (empty($merged[$key])) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * @param list<string> $keys
   * @param array<string, bool|int|string> $merged
   */
  private function signalsAnyOf(array $keys, array $merged): bool {
    if ($keys === []) {
      return TRUE;
    }
    foreach ($keys as $key) {
      if (!empty($merged[$key])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @param list<string> $keys
   * @param array<string, bool|int|string> $merged
   */
  private function signalsBlocked(array $keys, array $merged): bool {
    foreach ($keys as $key) {
      if (!empty($merged[$key])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @param array<string, string> $evaluations
   */
  private function stateMatches(IntelligenceDefinition $definition, array $evaluations): bool {
    foreach ($definition->stateExpectations as $stateId => $expected) {
      if (($evaluations[$stateId] ?? '') !== $expected->value) {
        return FALSE;
      }
    }
    if ($definition->stateExpectationAlternativeGroups === []) {
      return TRUE;
    }
    foreach ($definition->stateExpectationAlternativeGroups as $group) {
      $group_ok = TRUE;
      foreach ($group as $stateId => $expected) {
        if (($evaluations[$stateId] ?? '') !== $expected->value) {
          $group_ok = FALSE;
          break;
        }
      }
      if ($group_ok) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @param array<string, mixed> $experienceAttachments
   */
  private function hasResumeCandidates(array $experienceAttachments): bool {
    $resume = $experienceAttachments['resume_candidates'] ?? [];
    return is_array($resume) && $resume !== [];
  }

  /**
   * @param array<string, mixed> $experienceAttachments
   */
  private function retentionSatisfied(IntelligenceDefinition $definition, array $experienceAttachments): bool {
    if ($definition->requiresRetentionTierMin === NULL) {
      return TRUE;
    }
    $tier = (string) (($experienceAttachments['retention'] ?? [])['tier'] ?? MelRetentionTier::None->value);
    $current = $this->retentionRank($tier);
    $required = $this->retentionRank($definition->requiresRetentionTierMin);
    return $current >= $required;
  }

  private function retentionRank(string $tier): int {
    return match ($tier) {
      MelRetentionTier::Medium->value => 2,
      MelRetentionTier::Low->value => 1,
      default => 0,
    };
  }

}
