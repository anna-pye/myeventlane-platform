<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Resolves applicable experience continuity contracts for the current request.
 *
 * Uses merged workflow + domain interpretation signals (never entity payloads).
 */
final class MelJourneyResolver {

  /**
   * @param array<string, bool|int|string> $merged_signals
   *   Output of MelStateResolver::mergeDomainSignals().
   *
   * @return list<\Drupal\myeventlane_surface\ExperienceDefinition>
   */
  public function resolveApplicable(MelExperienceRegistry $registry, MelWorkflowContext $context, array $merged_signals): array {
    $active = [];
    foreach ($registry->all() as $definition) {
      if ($this->isApplicable($definition, $context, $merged_signals)) {
        $active[] = $definition;
      }
    }
    return $active;
  }

  /**
   * @param array<string, bool|int|string> $merged_signals
   */
  private function isApplicable(ExperienceDefinition $definition, MelWorkflowContext $context, array $merged_signals): bool {
    if ($definition->requireAuthentication && !$context->account->isAuthenticated()) {
      return FALSE;
    }

    $surface_ok = in_array($context->surface->value, $definition->surfaceOwnership, TRUE);
    if (!$surface_ok && $definition->activateUnderCheckoutSensitiveSurface && $context->checkoutSensitive) {
      $surface_ok = TRUE;
    }
    if (!$surface_ok) {
      return FALSE;
    }

    foreach ($definition->continuityTriggerSignalsAll as $key) {
      if (empty($merged_signals[$key])) {
        return FALSE;
      }
    }

    if ($definition->continuityTriggerSignalsAny !== []) {
      $hit = FALSE;
      foreach ($definition->continuityTriggerSignalsAny as $key) {
        if (!empty($merged_signals[$key])) {
          $hit = TRUE;
          break;
        }
      }
      if (!$hit) {
        return FALSE;
      }
    }

    $has_route_criteria = $definition->activationExactRoutes !== []
      || $definition->activationRoutePrefixes !== []
      || $definition->activationPathPrefixes !== [];

    if (!$has_route_criteria) {
      return TRUE;
    }

    if (in_array($context->routeName, $definition->activationExactRoutes, TRUE)) {
      return TRUE;
    }
    foreach ($definition->activationRoutePrefixes as $prefix) {
      if ($context->routeName !== '' && str_starts_with($context->routeName, $prefix)) {
        return TRUE;
      }
    }
    foreach ($definition->activationPathPrefixes as $prefix) {
      if (str_starts_with($context->path, $prefix)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
