<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\myeventlane_core\MelStateEvaluation;

/**
 * Resolves which operational policy contracts are active for the current request.
 */
final class MelPolicyResolver {

  public function __construct(
    private readonly MelOperationalPolicyRegistry $registry,
  ) {}

  /**
   * @param array<string, bool|int|string> $merged
   * @param array<string, string> $evaluations
   *   State evaluation value strings keyed by state id.
   *
   * @return list<string>
   */
  public function resolveActivePolicyIds(
    MelWorkflowContext $context,
    array $merged,
    array $evaluations,
  ): array {
    $active = [];
    foreach ($this->registry->all() as $id => $definition) {
      foreach ($definition->activationRules as $rule) {
        if ($this->ruleMatches($rule, $context, $merged, $evaluations)) {
          $active[] = $id;
          break;
        }
      }
    }
    return array_values(array_unique($active));
  }

  /**
   * @param array<string, bool|int|string> $merged
   * @param array<string, string> $evaluations
   */
  private function ruleMatches(
    MelPolicyActivationRule $rule,
    MelWorkflowContext $context,
    array $merged,
    array $evaluations,
  ): bool {
    if ($rule->requireAuthentication && !$context->account->isAuthenticated()) {
      return FALSE;
    }
    if ($rule->requireCheckoutSensitive && !$context->checkoutSensitive) {
      return FALSE;
    }
    if ($rule->surfacesAny !== []) {
      $ok = in_array($context->surface->value, $rule->surfacesAny, TRUE);
      if (!$ok) {
        return FALSE;
      }
    }
    foreach ($rule->noneOfStateSatisfied as $stateId) {
      if ($this->isSatisfied($evaluations, $stateId)) {
        return FALSE;
      }
    }
    if ($rule->anyOfStateSatisfied !== []) {
      $any = FALSE;
      foreach ($rule->anyOfStateSatisfied as $stateId) {
        if ($this->isSatisfied($evaluations, $stateId)) {
          $any = TRUE;
          break;
        }
      }
      if (!$any) {
        return FALSE;
      }
    }
    if ($rule->anyOfStateUnsatisfied !== []) {
      $any = FALSE;
      foreach ($rule->anyOfStateUnsatisfied as $stateId) {
        if ($this->isUnsatisfied($evaluations, $stateId)) {
          $any = TRUE;
          break;
        }
      }
      if (!$any) {
        return FALSE;
      }
    }
    if ($rule->allOfStateSatisfied !== []) {
      foreach ($rule->allOfStateSatisfied as $stateId) {
        if (!$this->isSatisfied($evaluations, $stateId)) {
          return FALSE;
        }
      }
    }
    if ($rule->anyOfMergedTruthy !== []) {
      $any = FALSE;
      foreach ($rule->anyOfMergedTruthy as $key) {
        if (!empty($merged[$key])) {
          $any = TRUE;
          break;
        }
      }
      if (!$any) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * @param array<string, string> $evaluations
   */
  private function isSatisfied(array $evaluations, string $stateId): bool {
    return ($evaluations[$stateId] ?? '') === MelStateEvaluation::Satisfied->value;
  }

  /**
   * @param array<string, string> $evaluations
   */
  private function isUnsatisfied(array $evaluations, string $stateId): bool {
    return ($evaluations[$stateId] ?? '') === MelStateEvaluation::Unsatisfied->value;
  }

}
