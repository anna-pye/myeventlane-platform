<?php

/**
 * @file
 * Hooks provided by the MyEventLane surface governance module.
 */

use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_surface\MelWorkflowContext;

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alter normalized workflow signals used for UX orchestration.
 *
 * Never use this hook as an access bypass. Signals are interpretation inputs
 * for MELWorkflowSystem only; permissions and entity access remain authoritative.
 *
 * Invoked from MelWorkflowResolver::buildContext() before MelWorkflowContext is
 * constructed. Route and path are delivered in a context bag because Drupal 11
 * ModuleHandler::alter() supports at most two context arguments after $data.
 *
 * @param array<string, bool|int|string> $signals
 *   Normalized workflow signals (by reference).
 * @param \Drupal\Core\Session\AccountInterface $account
 *   The current user account.
 * @param array{route_name: string, path: string} $workflow_context
 *   Request route machine name and path info (by reference).
 *
 * @see \Drupal\myeventlane_surface\MelWorkflowResolver::buildContext()
 * @see \Drupal\Core\Extension\ModuleHandlerInterface::alter()
 */
function hook_mel_workflow_signals_alter(
  array &$signals,
  AccountInterface $account,
  array &$workflow_context,
): void {
}

/**
 * Provide domain facts keyed by MELStateRegistry signal contracts.
 *
 * Interpretation only — do not use as an access bypass. Enforce entity and route
 * access in your module before setting facts.
 *
 * @param array<string, bool|int|string> $facts
 *   Domain facts to merge (by reference). Starts empty.
 * @param \Drupal\myeventlane_surface\MelWorkflowContext $context
 *   Resolved workflow context for the current request.
 *
 * @see \Drupal\myeventlane_surface\MelStateResolver::mergeDomainSignals()
 */
function hook_mel_state_domain_facts_alter(array &$facts, MelWorkflowContext $context): void {
}

/**
 * Alter resolved applicable experience definitions.
 *
 * Continuity contracts only; does not replace workflows, Commerce, or onboarding.
 *
 * @param list<\Drupal\myeventlane_surface\ExperienceDefinition> $experiences
 *   Applicable experience definitions (by reference).
 * @param \Drupal\myeventlane_surface\MelWorkflowContext $context
 *   Current workflow context.
 * @param array<string, bool|int|string> $merged_signals
 *   Workflow signals merged with domain facts.
 *
 * @see \Drupal\myeventlane_surface\MelExperienceManager::buildPageAttachments()
 */
function hook_mel_experience_applicable_alter(
  array &$experiences,
  MelWorkflowContext $context,
  array &$merged_signals,
): void {
}

/**
 * Alter request-scoped continuation memory (non-sensitive keys only).
 *
 * Opt-in via request attribute `mel_experience_memory` (array) before alter.
 *
 * @param array<string, mixed> $memory
 *   Continuation memory (by reference).
 * @param \Drupal\myeventlane_surface\MelWorkflowContext $context
 *   Current workflow context.
 * @param array<string, bool|int|string> $merged_signals
 *   Workflow signals merged with domain facts.
 *
 * @see \Drupal\myeventlane_surface\MelExperienceManager::buildPageAttachments()
 */
function hook_mel_experience_memory_alter(
  array &$memory,
  MelWorkflowContext $context,
  array &$merged_signals,
): void {
}

/**
 * Alter the final page continuity payload.
 *
 * @param array<string, mixed> $attachments
 *   Experience attachments for preprocess (by reference).
 * @param \Drupal\myeventlane_surface\MelWorkflowContext $context
 *   Current workflow context.
 * @param array<string, bool|int|string> $merged_signals
 *   Workflow signals merged with domain facts.
 *
 * @see \Drupal\myeventlane_surface\MelExperienceManager::buildPageAttachments()
 */
function hook_mel_experience_attachments_alter(
  array &$attachments,
  MelWorkflowContext $context,
  array &$merged_signals,
): void {
}

/**
 * Alter the final intelligence orchestration payload.
 *
 * @param array<string, mixed> $payload
 *   Intelligence payload (by reference).
 * @param \Drupal\myeventlane_surface\MelWorkflowContext $context
 *   Current workflow context.
 * @param array<string, bool|int|string> $merged_signals
 *   Workflow signals merged with domain facts.
 *
 * @see \Drupal\myeventlane_surface\MelIntelligenceManager::buildPagePayload()
 */
function hook_mel_intelligence_page_payload_alter(
  array &$payload,
  MelWorkflowContext $context,
  array &$merged_signals,
): void {
}

/**
 * Alter operational policy interpretation payload.
 *
 * Suppression, trust thresholds, explainability, and tone hints only.
 * Enforcement remains in Drupal, Commerce, and moderation systems.
 *
 * State evaluations are embedded in $merged_signals['state_evaluations'] as
 * state id => evaluation value strings (MelStateEvaluation::value), because
 * ModuleHandler::alter() cannot deliver a fourth hook argument.
 *
 * @param array<string, mixed> $interpretation
 *   Policy interpretation payload (by reference).
 * @param \Drupal\myeventlane_surface\MelWorkflowContext $context
 *   Current workflow context.
 * @param array<string, bool|int|string> $merged_signals
 *   Workflow signals merged with domain facts. Includes `state_evaluations`.
 *
 * @see \Drupal\myeventlane_surface\MelOperationalPolicyManager::buildPageInterpretation()
 */
function hook_mel_operational_policy_interpretation_alter(
  array &$interpretation,
  MelWorkflowContext $context,
  array &$merged_signals,
): void {
}

/**
 * Alter the final MELObservabilitySystem payload.
 *
 * Traces, registry index, and telemetry boundary reference only. No new
 * surveillance or cross-account data.
 *
 * State evaluations are embedded in $merged_signals['state_evaluations'] as
 * state id => evaluation value strings (MelStateEvaluation::value), because
 * ModuleHandler::alter() cannot deliver a fourth hook argument.
 *
 * @param array<string, mixed> $payload
 *   Observability payload (by reference). Sanitized after all alters run.
 * @param \Drupal\myeventlane_surface\MelWorkflowContext $context
 *   Current workflow context.
 * @param array<string, bool|int|string> $merged_signals
 *   Workflow signals merged with domain facts. Includes `state_evaluations`.
 *
 * @see \Drupal\myeventlane_surface\MelObservabilityManager::buildPagePayload()
 */
function hook_mel_observability_page_payload_alter(
  array &$payload,
  MelWorkflowContext $context,
  array &$merged_signals,
): void {
}

/**
 * @} End of "addtogroup hooks".
 */
