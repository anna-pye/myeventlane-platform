<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\myeventlane_core\MelSurfaceId;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Canonical explainability / observability governance for MEL orchestration.
 *
 * Drupal logging, monitoring, analytics, and enforcement remain authoritative;
 * this manager composes privacy-safe traces from existing MEL payloads only.
 */
final class MelObservabilityManager {

  use StringTranslationTrait;

  public function __construct(
    private readonly MelObservabilityRegistry $observabilityRegistry,
    private readonly MelTraceResolver $traceResolver,
    private readonly MelSuppressionTraceHelper $suppressionTraceHelper,
    private readonly MelStateTraceHelper $stateTraceHelper,
    private readonly MelExperienceTraceHelper $experienceTraceHelper,
    private readonly MelIntelligenceTraceHelper $intelligenceTraceHelper,
    private readonly MelPolicyTraceHelper $policyTraceHelper,
    private readonly MelTelemetryBoundaryHelper $telemetryBoundaryHelper,
    private readonly MelObservabilityAccessibilityHelper $observabilityAccessibilityHelper,
    private readonly MelWorkflowResolver $workflowResolver,
    private readonly MelStateResolver $stateResolver,
    private readonly MelStateRegistry $stateRegistry,
    private readonly MelObservabilityDiagnosticsAccess $diagnosticsAccess,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LoggerChannelInterface $logger,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Portable observability payload for preprocess (Twig + staff panel).
   *
   * @param array<string, mixed> $state
   * @param array<string, mixed> $workflow
   * @param array<string, mixed> $experience
   * @param array<string, mixed> $intelligence
   * @param array<string, mixed> $policy
   *
   * @return array<string, mixed>
   */
  public function buildPagePayload(
    array $state,
    array $workflow,
    array $experience,
    array $intelligence,
    array $policy,
  ): array {
    try {
      $context = $this->workflowResolver->buildContext();
      if (!$this->diagnosticsAccess->mayViewDiagnostics($context->surface, $context->account)) {
        return $this->restrictedObservabilityPayload($context);
      }

      $merged = $this->stateResolver->mergeDomainSignals($context);
      $evaluations = [];
      foreach ($this->stateRegistry->all() as $id => $definition) {
        $evaluations[$id] = $this->stateResolver->evaluate($definition, $context, $merged)->value;
      }

      $tier = $this->traceResolver->maximumTierForSurface($context->surface);

      $state_data = $this->withoutCache($state);
      $workflow_data = $this->withoutCache($workflow);
      $experience_data = $this->withoutCache($experience);
      $intelligence_data = $this->withoutCache($intelligence);
      $policy_data = $this->withoutCache($policy);

      $traces = [];
      $traces = array_merge($traces, $this->stateTraceHelper->buildTraces($tier, $context->surface, $state_data));
      $traces = array_merge($traces, $this->buildWorkflowTraces($tier, $context->surface, $workflow_data, $experience_data));
      $traces = array_merge($traces, $this->experienceTraceHelper->buildTraces($tier, $context->surface, $experience_data));
      $traces = array_merge($traces, $this->intelligenceTraceHelper->buildTraces($tier, $context->surface, $intelligence_data));
      $traces = array_merge($traces, $this->suppressionTraceHelper->buildTraces($tier, $context->surface, $intelligence_data, $policy_data));
      $traces = array_merge($traces, $this->policyTraceHelper->buildTraces($tier, $context->surface, $policy_data));
      $traces = array_merge($traces, $this->telemetryBoundaryHelper->buildTraces($tier, $context->surface));

      $applicable = $this->traceResolver->applicableDefinitions($context->surface, $tier);
      $registry_index = array_map(static fn (ObservabilityDefinition $d): string => $d->id, $applicable);

      $payload = [
        'visibility_tier' => strtolower($tier->name),
        'visibility_tier_value' => $tier->value,
        'surface' => $context->surface->value,
        'registry_contract_ids' => $registry_index,
        'traces' => $this->sortTraces($traces),
        'telemetry_boundary_reference' => ($tier === MelObservabilityVisibilityLevel::Diagnostic && $context->surface === MelSurfaceId::Staff)
          ? $this->telemetryBoundaryHelper->boundaryReference()
          : [],
        'accessibility' => [
          'diagnostic_region_attributes' => $this->observabilityAccessibilityHelper->diagnosticRegionAttributes(),
          'polite_trace_list_attributes' => $this->observabilityAccessibilityHelper->politeTraceListAttributes(),
        ],
        'privacy' => [
          'no_cross_account' => TRUE,
          'staff_diagnostics_only_on_staff_surface' => $context->surface === MelSurfaceId::Staff,
          'commerce_safe' => TRUE,
        ],
        'explainability' => [
          'framework' => (string) $this->t('Traces are categorical, deterministic, and derived from existing MEL managers only.'),
          'deterministic_ordering' => TRUE,
        ],
        'registry_meta' => ($tier === MelObservabilityVisibilityLevel::Diagnostic && $context->surface === MelSurfaceId::Staff)
          ? $this->registryMetaForTier($tier, $context->surface)
          : [],
        '#cache' => $this->mergeCacheMeta($state, $workflow, $experience, $intelligence, $policy),
      ];

      if ($context->account->isAuthenticated()) {
        $payload['#cache']['tags'][] = 'user:' . $context->account->id();
      }

      // ModuleHandler::alter() accepts only two context arguments after $payload.
      $merged['state_evaluations'] = $evaluations;
      $this->moduleHandler->alter('mel_observability_page_payload', $payload, $context, $merged);

      $payload['traces'] = MelObservabilityTraceSanitizer::sanitizeRows(is_array($payload['traces'] ?? NULL) ? $payload['traces'] : []);
      $payload['registry_meta'] = MelObservabilityTraceSanitizer::sanitizeRegistryMeta(
        is_array($payload['registry_meta'] ?? NULL) ? $payload['registry_meta'] : [],
      );
      $payload['telemetry_boundary_reference'] = MelObservabilityTraceSanitizer::sanitizeTelemetryReference(
        is_array($payload['telemetry_boundary_reference'] ?? NULL) ? $payload['telemetry_boundary_reference'] : [],
      );

      return $payload;
    }
    catch (\Throwable $e) {
      $this->logger->error('MEL observability governance failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [
        'visibility_tier' => strtolower(MelObservabilityVisibilityLevel::None->name),
        'visibility_tier_value' => MelObservabilityVisibilityLevel::None->value,
        'surface' => '',
        'registry_contract_ids' => [],
        'traces' => [],
        'telemetry_boundary_reference' => [],
        'accessibility' => [
          'diagnostic_region_attributes' => $this->observabilityAccessibilityHelper->diagnosticRegionAttributes(),
          'polite_trace_list_attributes' => $this->observabilityAccessibilityHelper->politeTraceListAttributes(),
        ],
        'privacy' => [
          'no_cross_account' => TRUE,
          'staff_diagnostics_only_on_staff_surface' => FALSE,
          'commerce_safe' => TRUE,
        ],
        'explainability' => [
          'framework' => 'unavailable',
          'deterministic_ordering' => TRUE,
        ],
        'registry_meta' => [],
        '#cache' => [
          'contexts' => ['route', 'user'],
          'max-age' => 0,
        ],
      ];
    }
  }

  /**
   * @param array<string, mixed> $workflow
   * @param array<string, mixed> $experience
   *
   * @return list<array<string, mixed>>
   */
  private function buildWorkflowTraces(
    MelObservabilityVisibilityLevel $tier,
    MelSurfaceId $surface,
    array $workflow,
    array $experience,
  ): array {
    if ($tier->value < MelObservabilityVisibilityLevel::Operational->value) {
      return [];
    }

    $rows = [];
    $active = $workflow['active_ids'] ?? [];
    if (is_array($active)) {
      $ids = array_values(array_filter($active, 'is_string'));
      sort($ids);
      foreach ($ids as $wid) {
        $rows[] = [
          'observability_id' => 'mel.obs.workflow.activation',
          'code' => 'active_' . $wid,
          'message' => (string) $this->t('Workflow contract active: @id.', ['@id' => $wid]),
          'deterministic_key' => MelObservabilityDeterministicKey::format('mel.obs.workflow.activation', 'active', $wid),
        ];
      }
    }

    $completion = $workflow['completion_ids'] ?? [];
    if (is_array($completion) && $completion !== []) {
      $cids = array_values(array_filter($completion, 'is_string'));
      sort($cids);
      foreach ($cids as $cid) {
        $rows[] = [
          'observability_id' => 'mel.obs.workflow.activation',
          'code' => 'completion_' . $cid,
          'message' => (string) $this->t('Completion moment recognised for workflow @id.', ['@id' => $cid]),
          'deterministic_key' => MelObservabilityDeterministicKey::format('mel.obs.workflow.activation', 'completion', $cid),
        ];
      }
    }

    $progress = $workflow['region_progress'] ?? [];
    if (is_array($progress) && $progress !== []) {
      $rows[] = [
        'observability_id' => 'mel.obs.workflow.onboarding_progression',
        'code' => 'progress_region_present',
        'message' => (string) $this->t('A workflow progress checklist region is available for this page.'),
        'deterministic_key' => MelObservabilityDeterministicKey::format(
          'mel.obs.workflow.onboarding_progression',
          'progress_region_present',
        ),
      ];
    }

    $continuation = $experience['continuation'] ?? [];
    $edges = is_array($continuation) ? ($continuation['workflow_edges'] ?? []) : [];
    if (is_array($edges) && $edges !== []) {
      $edge_keys = [];
      foreach ($edges as $edge) {
        if (!is_array($edge)) {
          continue;
        }
        $from = (string) ($edge['from_workflow_id'] ?? '');
        $to = (string) ($edge['to_workflow_id'] ?? '');
        $exp = (string) ($edge['experience_id'] ?? '');
        if ($from === '' || $to === '') {
          continue;
        }
        $edge_keys[] = $from . '>' . $to . '@' . $exp;
      }
      sort($edge_keys);
      foreach ($edge_keys as $key) {
        $safe = preg_replace('/[^a-z0-9_>@.-]/i', '_', $key) ?: 'edge';
        $rows[] = [
          'observability_id' => 'mel.obs.workflow.continuity_activation',
          'code' => 'edge_' . $safe,
          'message' => (string) $this->t('Continuity maps a guided edge between workflow steps (@edge).', ['@edge' => $key]),
          'deterministic_key' => MelObservabilityDeterministicKey::format(
            'mel.obs.workflow.continuity_activation',
            'edge',
            $safe,
          ),
        ];
      }
    }

    if ($surface === MelSurfaceId::Customer && $rows !== []) {
      return array_slice($rows, 0, 3);
    }

    return $rows;
  }

  /**
   * @param list<array<string, mixed>> $traces
   *
   * @return list<array<string, mixed>>
   */
  private function sortTraces(array $traces): array {
    usort($traces, static function (array $a, array $b): int {
      return strcmp((string) ($a['deterministic_key'] ?? ''), (string) ($b['deterministic_key'] ?? ''));
    });
    return array_values($traces);
  }

  /**
   * @return list<array<string, string>>
   */
  private function registryMetaForTier(MelObservabilityVisibilityLevel $tier, MelSurfaceId $surface): array {
    $out = [];
    foreach ($this->observabilityRegistry->all() as $def) {
      if (!$this->traceResolver->isContractApplicable($def, $surface, $tier)) {
        continue;
      }
      $out[] = [
        'id' => $def->id,
        'category' => $def->category->value,
        'sources' => implode(', ', $def->sourceSystems),
        'privacy' => $def->privacyBoundaries,
        'accessibility' => $def->accessibilityNotes,
      ];
    }
    usort($out, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
    return $out;
  }

  /**
   * @param array<string, mixed> $data
   *
   * @return array<string, mixed>
   */
  private function withoutCache(array $data): array {
    unset($data['#cache']);
    return $data;
  }

  /**
   * @param array<string, mixed> $state
   * @param array<string, mixed> $workflow
   * @param array<string, mixed> $experience
   * @param array<string, mixed> $intelligence
   * @param array<string, mixed> $policy
   *
   * @return array<string, mixed>
   */
  /**
   * Empty observability for non-staff or staff without diagnostics permission.
   *
   * @return array<string, mixed>
   */
  private function restrictedObservabilityPayload(MelWorkflowContext $context): array {
    $cache = [
      'contexts' => ['route', 'user', 'user.permissions', 'languages:language_interface'],
      'tags' => [],
    ];
    if ($context->account->isAuthenticated()) {
      $cache['tags'][] = 'user:' . $context->account->id();
    }
    return [
      'visibility_tier' => strtolower(MelObservabilityVisibilityLevel::None->name),
      'visibility_tier_value' => MelObservabilityVisibilityLevel::None->value,
      'surface' => $context->surface->value,
      'registry_contract_ids' => [],
      'traces' => [],
      'telemetry_boundary_reference' => [],
      'accessibility' => [
        'diagnostic_region_attributes' => $this->observabilityAccessibilityHelper->diagnosticRegionAttributes(),
        'polite_trace_list_attributes' => $this->observabilityAccessibilityHelper->politeTraceListAttributes(),
      ],
      'privacy' => [
        'no_cross_account' => TRUE,
        'staff_diagnostics_only_on_staff_surface' => FALSE,
        'commerce_safe' => TRUE,
        'diagnostics_permission_required' => TRUE,
      ],
      'explainability' => [
        'framework' => (string) $this->t('Observability diagnostics are disabled for this surface or account.'),
        'deterministic_ordering' => TRUE,
      ],
      'registry_meta' => [],
      '#cache' => $cache,
    ];
  }

  private function mergeCacheMeta(
    array $state,
    array $workflow,
    array $experience,
    array $intelligence,
    array $policy,
  ): array {
    $contexts = ['route', 'user', 'user.permissions', 'languages:language_interface'];
    $tags = [];
    foreach ([$state, $workflow, $experience, $intelligence, $policy] as $chunk) {
      $cache = $chunk['#cache'] ?? [];
      if (!is_array($cache)) {
        continue;
      }
      foreach ($cache['tags'] ?? [] as $tag) {
        if (is_string($tag) && $tag !== '') {
          $tags[] = $tag;
        }
      }
      foreach ($cache['contexts'] ?? [] as $ctx) {
        if (is_string($ctx) && $ctx !== '' && !in_array($ctx, $contexts, TRUE)) {
          $contexts[] = $ctx;
        }
      }
    }
    return [
      'contexts' => array_values(array_unique($contexts)),
      'tags' => array_values(array_unique($tags)),
    ];
  }

}
