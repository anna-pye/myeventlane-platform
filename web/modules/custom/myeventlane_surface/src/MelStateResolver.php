<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\myeventlane_core\MelStateEvaluation;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Merges workflow signals with domain facts and evaluates interpretation contracts.
 *
 * Domain facts MUST be contributed by owning modules via hook_mel_state_domain_facts_alter()
 * (see myeventlane_surface.api.php). This resolver may apply thin aliases from existing
 * workflow signals to canonical keys — interpretation glue only, not new business rules.
 */
final class MelStateResolver {

  public function __construct(
    private readonly MelWorkflowResolver $workflowResolver,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Builds workflow context for the current request.
   */
  public function buildWorkflowContext(): MelWorkflowContext {
    return $this->workflowResolver->buildContext();
  }

  /**
   * Workflow signals plus domain facts plus documented aliases.
   *
   * @return array<string, bool|int|string>
   */
  public function mergeDomainSignals(MelWorkflowContext $context): array {
    $facts = [];
    try {
      $this->moduleHandler->alter('mel_state_domain_facts', $facts, $context);
    }
    catch (\Throwable $e) {
      $this->logger->warning('mel_state_domain_facts alter failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    $merged = array_merge($context->signals, $facts);
    return $this->applyInterpretationAliases($merged);
  }

  /**
   * @param array<string, bool|int|string> $mergedSignals
   */
  public function evaluate(StateDefinition $definition, MelWorkflowContext $context, array $mergedSignals): MelStateEvaluation {
    if ($definition->requiresAuthentication && !$context->account->isAuthenticated()) {
      return MelStateEvaluation::Unknown;
    }

    if ($definition->surfaceOwnership !== []) {
      $allowed = in_array($context->surface->value, $definition->surfaceOwnership, TRUE);
      if (!$allowed) {
        return MelStateEvaluation::Unknown;
      }
    }

    foreach ($definition->blockingSignals as $key) {
      if (!empty($mergedSignals[$key])) {
        return MelStateEvaluation::Unsatisfied;
      }
    }

    if ($definition->requiredPositiveSignals === []) {
      return MelStateEvaluation::Unknown;
    }

    foreach ($definition->requiredPositiveSignals as $key) {
      if (empty($mergedSignals[$key])) {
        return MelStateEvaluation::Unsatisfied;
      }
    }

    return MelStateEvaluation::Satisfied;
  }

  /**
   * @param array<string, bool|int|string> $merged
   * @return array<string, bool|int|string>
   */
  private function applyInterpretationAliases(array $merged): array {
    // Order confirmation UX on the canonical order detail route.
    if (!empty($merged['route.order_detail'])) {
      $merged['checkout.state.order_confirmed'] = $merged['checkout.state.order_confirmed'] ?? TRUE;
    }

    // Workflow escalation signal maps to trust interpretation for staff/vendor shells.
    if (!empty($merged['support.flags.escalation'])) {
      $merged['trust.escalation_review'] = $merged['trust.escalation_review'] ?? TRUE;
    }

    return $merged;
  }

}
