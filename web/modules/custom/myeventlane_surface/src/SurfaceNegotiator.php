<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\myeventlane_core\MelSurfaceId;

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\MelReadinessHelper;

/**
 * Applies resolved surface metadata to render variables (shell negotiation).
 */
final class SurfaceNegotiator {

  /**
   * Per-request memo for state interpretation (page + html preprocess).
   *
   * @var array<string, mixed>|null
   */
  private ?array $melStatePageInterpretation = NULL;

  /**
   * Per-request memo for experience continuity (page + html preprocess).
   *
   * @var array<string, mixed>|null
   */
  private ?array $melExperiencePageAttachments = NULL;

  /**
   * Per-request memo for MELIntelligenceSystem (page + html preprocess).
   *
   * @var array<string, mixed>|null
   */
  private ?array $melIntelligencePagePayload = NULL;

  /**
   * Per-request memo for MELOperationalPolicySystem (page + html preprocess).
   *
   * @var array<string, mixed>|null
   */
  private ?array $melOperationalPolicyPageInterpretation = NULL;

  public function __construct(
    private readonly SurfaceResolver $resolver,
    private readonly SurfaceManager $manager,
    private readonly MelFormManager $formManager,
    private readonly MelComponentManager $componentManager,
    private readonly MelNavigationManager $navigationManager,
    private readonly MelWorkflowManager $workflowManager,
    private readonly MelDataPresentationManager $dataPresentationManager,
    private readonly MelInteractionManager $interactionManager,
    private readonly MelStateManager $stateManager,
    private readonly MelExperienceManager $experienceManager,
    private readonly MelIntelligenceManager $intelligenceManager,
    private readonly MelOperationalPolicyManager $operationalPolicyManager,
    private readonly MelObservabilityManager $observabilityManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly MelObservabilityDiagnosticsAccess $observabilityDiagnosticsAccess,
    private readonly RouteMatchInterface $routeMatch,
    private readonly MelGovernanceDebugAccess $governanceDebugAccess,
    private readonly MelGovernancePayloadInspector $governancePayloadInspector,
    private readonly MelGovernancePresentationSanitizer $governancePresentationSanitizer,
    private readonly SurfaceAccessHelper $surfaceAccessHelper,
    private readonly MelReadinessHelper $readinessHelper,
  ) {}

  /**
   * Exposes surface metadata to page templates and downstream preprocess hooks.
   *
   * @param array<string, mixed> $variables
   */
  public function attachPageMetadata(array &$variables): void {
    $surface = $this->resolver->resolve();
    $definition = $this->manager->get($surface);

    $variables['mel_surface'] = $surface->value;
    $variables['mel_surface_label'] = $definition->label;
    $variables['mel_surface_definition'] = $definition;
    $variables['mel_surface_auth_minimal'] = $surface === MelSurfaceId::Auth;

    if ($this->formManager->shouldGovernForms()) {
      $variables['mel_form_classification'] = $this->formManager->getClassification()->value;
    }
    else {
      $variables['mel_form_classification'] = NULL;
    }

    $variables['mel_component_context'] = $this->componentManager->getPageContext();
    $variables['mel_navigation_contract'] = $this->navigationManager->getContract();
    $variables['mel_data_presentation_context'] = $this->dataPresentationManager->getPageContext();
    $variables['mel_interaction_context'] = $this->interactionManager->getPageContext();

    $variables += ['#attached' => []];
    $variables['#attached'] += ['library' => []];
    $variables['#attached']['library'][] = 'myeventlane_surface/interactions';
    $variables['#attached']['library'][] = 'myeventlane_surface/experience';
    // Buyer-facing surfaces do not ship intelligence panel JS/CSS on public theme pages.
    if (!in_array($surface, [
      MelSurfaceId::Public,
      MelSurfaceId::Auth,
      MelSurfaceId::Customer,
    ], TRUE)) {
      $variables['#attached']['library'][] = 'myeventlane_surface/intelligence';
    }
    $variables['#attached']['library'][] = 'myeventlane_surface/operational_policy';

    $workflow_with_cache = $this->workflowManager->buildPageAttachments();
    $workflow_cache = $workflow_with_cache['#cache'] ?? [];
    $workflow_attachments = $workflow_with_cache;
    unset($workflow_attachments['#cache']);

    $mel_state_raw = $this->getMelStateInterpretation();
    $state_cache = $mel_state_raw['#cache'] ?? [];
    $mel_state = $mel_state_raw;
    unset($mel_state['#cache']);
    $variables['mel_state'] = $mel_state;

    $variables['mel_workflow'] = $workflow_attachments;
    $variables['mel_workflow_region_primary'] = !empty($workflow_attachments['region_primary'])
      ? $workflow_attachments['region_primary']
      : NULL;
    $variables['mel_workflow_region_progress'] = !empty($workflow_attachments['region_progress'])
      ? $workflow_attachments['region_progress']
      : NULL;

    $mel_operational_policy_raw = $this->getMelOperationalPolicyInterpretation();
    $policy_cache = $mel_operational_policy_raw['#cache'] ?? [];
    $mel_operational_policy = $mel_operational_policy_raw;
    unset($mel_operational_policy['#cache']);
    $variables['mel_operational_policy'] = $mel_operational_policy;

    $experience_raw = $this->getMelExperienceAttachments();
    $experience_cache = $experience_raw['#cache'] ?? [];
    $experience_attachments = $experience_raw;
    unset($experience_attachments['#cache']);
    $variables['mel_experience'] = $experience_attachments;

    $intelligence_raw = $this->getMelIntelligencePayload();
    $intelligence_cache = $intelligence_raw['#cache'] ?? [];
    $intelligence_for_theme = $this->governancePresentationSanitizer->sanitizeIntelligencePayloadForPresentation(
      $intelligence_raw,
      $surface,
    );
    $intelligence_payload = $intelligence_for_theme;
    unset($intelligence_payload['#cache']);
    $variables['mel_intelligence'] = $intelligence_payload;
    $variables['mel_intelligence_panel'] = $this->buildIntelligencePanelRenderArray(
      $intelligence_for_theme,
      $intelligence_cache,
      $surface,
    );

    // Public marketplace, login/register, and customer hub: no intelligence panel chrome.
    // Vendor console and staff tooling keep the panel where Vendor/Staff surfaces apply.
    if (in_array($surface, [
      MelSurfaceId::Public,
      MelSurfaceId::Auth,
      MelSurfaceId::Customer,
    ], TRUE)) {
      $variables['mel_intelligence_panel'] = NULL;
    }

    $mel_observability_raw = $this->observabilityManager->buildPagePayload(
      $mel_state_raw,
      $workflow_with_cache,
      $experience_raw,
      $intelligence_raw,
      $mel_operational_policy_raw,
    );
    $observability_cache = $mel_observability_raw['#cache'] ?? [];
    $mel_observability = $mel_observability_raw;
    unset($mel_observability['#cache']);
    $variables['mel_observability'] = $mel_observability;
    $variables['mel_observability_panel'] = $this->buildObservabilityPanelRenderArray(
      $mel_observability_raw,
      $observability_cache,
    );

    $primary_experience = $experience_attachments['primary_experience_id'] ?? NULL;
    if (is_string($primary_experience) && $primary_experience !== '') {
      $variables['#attached']['drupalSettings']['melExperience']['primaryId'] = $primary_experience;
    }

    $variables['#attached']['drupalSettings']['melOperationalPolicy'] = [
      'surfaceProfile' => (string) ($mel_operational_policy['surface_policy_profile'] ?? ''),
      'toneHints' => $mel_operational_policy['communication_hints'] ?? [],
      'automation' => $mel_operational_policy['automation'] ?? [],
      'lifecycleSafety' => $mel_operational_policy['lifecycle_safety'] ?? [],
    ];

    if (
      $this->observabilityDiagnosticsAccess->mayViewDiagnostics($surface, $this->currentUser)
      && is_array($mel_observability['traces'] ?? NULL)
      && $mel_observability['traces'] !== []
    ) {
      $variables['#attached']['library'][] = 'myeventlane_surface/observability';
      $variables['#attached']['drupalSettings']['melObservability'] = [
        'enabled' => TRUE,
        'tier' => (string) ($mel_observability['visibility_tier'] ?? ''),
      ];
    }

    if ($this->governanceDebugAccess->mayViewDebug($surface, $this->currentUser->getAccount())) {
      $variables['#attached']['library'][] = 'myeventlane_surface/governance_debug';
      $intel_debug = $intelligence_raw;
      unset($intel_debug['#cache']);
      $variables['#attached']['drupalSettings']['melGovernanceDebug'] = $this->governancePayloadInspector->buildStaffDebugSummary(
        (string) ($this->routeMatch->getRouteName() ?? ''),
        $surface,
        $mel_operational_policy,
        $intel_debug,
        $experience_attachments,
      );
    }

    $variables += ['#cache' => []];
    $variables['#cache'] += ['contexts' => [], 'tags' => []];

    $workflow_bubble = ['#cache' => $workflow_cache];
    $state_bubble = ['#cache' => $state_cache];
    $policy_bubble = ['#cache' => $policy_cache];
    $experience_bubble = ['#cache' => $experience_cache];
    $intelligence_bubble = ['#cache' => $intelligence_cache];
    $observability_bubble = ['#cache' => $observability_cache];
    $meta = BubbleableMetadata::createFromRenderArray($variables);
    $meta->merge(BubbleableMetadata::createFromRenderArray($workflow_bubble));
    $meta->merge(BubbleableMetadata::createFromRenderArray($state_bubble));
    $meta->merge(BubbleableMetadata::createFromRenderArray($policy_bubble));
    $meta->merge(BubbleableMetadata::createFromRenderArray($experience_bubble));
    $meta->merge(BubbleableMetadata::createFromRenderArray($intelligence_bubble));
    $meta->merge(BubbleableMetadata::createFromRenderArray($observability_bubble));
    $meta->applyTo($variables);

    // Public and auth shells must remain Dynamic Page Cache eligible. Bare
    // 'user' / 'session' on the page root match renderer auto-placeholdering
    // conditions, so Dynamic Page Cache marks the response
    // UNCACHEABLE (poor cacheability). Personalised fragments belong in
    // placeholders / lazy builders; governance payloads on public Twig are
    // settings-oriented, not per-uid chrome.
    // @see \Drupal\dynamic_page_cache\EventSubscriber\DynamicPageCacheSubscriber::shouldCacheResponse()
    $shared_shell = in_array($surface, [MelSurfaceId::Public, MelSurfaceId::Auth], TRUE);
    if ($shared_shell) {
      $this->neutraliseHighCardinalityPageContexts($variables);
    }

    $required_contexts = $shared_shell
      ? ['route', 'user.roles:authenticated', 'user.permissions', 'languages:language_interface']
      : ['route', 'user', 'user.permissions', 'languages:language_interface'];
    foreach ($required_contexts as $context) {
      if (!in_array($context, $variables['#cache']['contexts'], TRUE)) {
        $variables['#cache']['contexts'][] = $context;
      }
    }
  }

  /**
   * Remaps page-root cache contexts that poison Dynamic Page Cache.
   *
   * @param array<string, mixed> $variables
   *   Page preprocess variables (modified in place).
   */
  private function neutraliseHighCardinalityPageContexts(array &$variables): void {
    $contexts = $variables['#cache']['contexts'] ?? [];
    if (!is_array($contexts)) {
      $contexts = [];
    }
    $contexts = array_values(array_filter(
      $contexts,
      static fn(mixed $context): bool => is_string($context)
        && $context !== 'user'
        && $context !== 'session',
    ));
    if (!in_array('user.roles:authenticated', $contexts, TRUE)) {
      $contexts[] = 'user.roles:authenticated';
    }
    $variables['#cache']['contexts'] = $contexts;

    // max-age 0 on the page root is also treated as poor cacheability.
    // Uncacheable forms/cart chrome must stay in placeholders, not here.
    if (array_key_exists('max-age', $variables['#cache']) && (int) $variables['#cache']['max-age'] === 0) {
      unset($variables['#cache']['max-age']);
    }
  }

  /**
   * Adds portable shell hints on the HTML document for styling/testing.
   *
   * Sets data attributes on both html_attributes (<html>) and attributes (<body>)
   * when present — either may still be a plain array during preprocess_html.
   *
   * @param array<string, mixed> $variables
   */
  public function attachHtmlMetadata(array &$variables): void {
    $surface = $this->resolver->resolve();
    $profile = $this->dataPresentationManager->getLayoutProfile($surface);
    $surface_value = $surface->value;
    $interaction_ctx = $this->interactionManager->getPageContext();
    $interaction_profile = (string) ($interaction_ctx['profile'] ?? '');
    $checkout_trust = !empty($interaction_ctx['checkout_trust_flow']);

    $state_tone = '';
    try {
      $state_tone = (string) ($this->getMelStateInterpretation()['tone'] ?? '');
    }
    catch (\Throwable) {
      $state_tone = '';
    }

    $experience_primary = '';
    try {
      $pid = $this->getMelExperienceAttachments()['primary_experience_id'] ?? NULL;
      $experience_primary = is_string($pid) ? $pid : '';
    }
    catch (\Throwable) {
      $experience_primary = '';
    }

    $policy_profile = '';
    try {
      $policy_profile = (string) ($this->getMelOperationalPolicyInterpretation()['surface_policy_profile'] ?? '');
    }
    catch (\Throwable) {
      $policy_profile = '';
    }

    foreach (['html_attributes', 'attributes'] as $key) {
      if (!isset($variables[$key])) {
        continue;
      }
      $this->mergeShellDataHints(
        $variables,
        $key,
        $surface_value,
        $profile,
        $interaction_profile,
        $checkout_trust,
        $state_tone,
        $experience_primary,
        $policy_profile,
      );
    }
  }

  /**
   * @param array<string, mixed> $variables
   */
  private function mergeShellDataHints(
    array &$variables,
    string $key,
    string $surface_value,
    string $profile,
    string $interaction_profile,
    bool $checkout_trust,
    string $state_tone,
    string $experience_primary,
    string $policy_profile,
  ): void {
    $attrs = $variables[$key];
    if (is_array($attrs)) {
      $variables[$key]['data-mel-surface'] = $surface_value;
      $variables[$key]['data-mel-layout-profile'] = $profile;
      if ($interaction_profile !== '') {
        $variables[$key]['data-mel-interaction-profile'] = $interaction_profile;
      }
      $variables[$key]['data-mel-checkout-trust'] = $checkout_trust ? 'true' : 'false';
      if ($state_tone !== '') {
        $variables[$key]['data-mel-state-tone'] = $state_tone;
      }
      if ($experience_primary !== '') {
        $variables[$key]['data-mel-experience-primary'] = $experience_primary;
      }
      if ($policy_profile !== '') {
        $variables[$key]['data-mel-policy-profile'] = $policy_profile;
      }

      return;
    }

    if (is_object($attrs) && method_exists($attrs, 'setAttribute')) {
      $attrs->setAttribute('data-mel-surface', $surface_value);
      $attrs->setAttribute('data-mel-layout-profile', $profile);
      if ($interaction_profile !== '') {
        $attrs->setAttribute('data-mel-interaction-profile', $interaction_profile);
      }
      $attrs->setAttribute('data-mel-checkout-trust', $checkout_trust ? 'true' : 'false');
      if ($state_tone !== '') {
        $attrs->setAttribute('data-mel-state-tone', $state_tone);
      }
      if ($experience_primary !== '') {
        $attrs->setAttribute('data-mel-experience-primary', $experience_primary);
      }
      if ($policy_profile !== '') {
        $attrs->setAttribute('data-mel-policy-profile', $policy_profile);
      }
    }
  }

  /**
   * @return array<string, mixed>
   */
  private function getMelStateInterpretation(): array {
    if ($this->melStatePageInterpretation === NULL) {
      $this->melStatePageInterpretation = $this->stateManager->buildPageInterpretation();
    }
    return $this->melStatePageInterpretation;
  }

  /**
   * @return array<string, mixed>
   */
  private function getMelOperationalPolicyInterpretation(): array {
    if ($this->melOperationalPolicyPageInterpretation === NULL) {
      $this->melOperationalPolicyPageInterpretation = $this->operationalPolicyManager->buildPageInterpretation();
    }
    return $this->melOperationalPolicyPageInterpretation;
  }

  /**
   * @return array<string, mixed>
   */
  private function getMelExperienceAttachments(): array {
    if ($this->melExperiencePageAttachments === NULL) {
      $this->melExperiencePageAttachments = $this->experienceManager->buildPageAttachments(
        $this->getMelOperationalPolicyInterpretation(),
      );
    }
    return $this->melExperiencePageAttachments;
  }

  /**
   * @return array<string, mixed>
   */
  private function getMelIntelligencePayload(): array {
    if ($this->melIntelligencePagePayload === NULL) {
      $this->melIntelligencePagePayload = $this->intelligenceManager->buildPagePayload(
        $this->getMelExperienceAttachments(),
        $this->getMelOperationalPolicyInterpretation(),
      );
    }
    return $this->melIntelligencePagePayload;
  }

  /**
   * @param array<string, mixed> $payload
   * @param array<string, mixed> $cache_meta
   *
   * @return array<string, mixed>|null
   *   Render array for mel_intelligence_panel, or NULL when nothing to show.
   */
  private function buildIntelligencePanelRenderArray(
    array $payload,
    array $cache_meta,
    MelSurfaceId $surface,
  ): ?array {
    $items = $payload['items'] ?? [];
    $insights = $payload['insights'] ?? [];
    if (!is_array($items) || !is_array($insights)) {
      return NULL;
    }
    if ($items === [] && $insights === []) {
      return NULL;
    }
    $region = $payload['accessibility']['region_attributes'] ?? [];
    if (!is_array($region)) {
      $region = [];
    }
    $reduced = $payload['accessibility']['reduced_motion'] ?? [];
    $merged_attrs = $region;
    if (is_array($reduced)) {
      $merged_attrs = array_merge($merged_attrs, $reduced);
    }
    $classes = $merged_attrs['class'] ?? [];
    if (is_string($classes)) {
      $classes = array_values(array_filter(preg_split('/\s+/', trim($classes)) ?: []));
    }
    elseif (!is_array($classes)) {
      $classes = [];
    }
    $classes[] = 'mel-intelligence-panel';
    $public_checkout_storytelling = $surface === MelSurfaceId::Public
      && $this->surfaceAccessHelper->isCheckoutSensitiveRequest();
    if ($public_checkout_storytelling) {
      $classes[] = 'mel-intelligence-panel--public-checkout-story';
    }
    $merged_attrs['class'] = array_values(array_unique(array_filter($classes)));
    // Theme variables for hooks with hook_theme 'variables' must use #name keys
    // so Element::children() does not treat them as nested render children.
    $presentation = $this->readinessHelper->intelligencePanelThemeVariables($surface, $public_checkout_storytelling);
    return [
      '#theme' => 'mel_intelligence_panel',
      '#attributes' => $merged_attrs,
      '#items' => $items,
      '#insights' => $insights,
      '#explainability_details' => $surface === MelSurfaceId::Staff,
      '#intelligence_region_heading' => $presentation['intelligence_region_heading'],
      '#explainability_summary_label' => $presentation['explainability_summary_label'],
      '#explainability_signals_label' => $presentation['explainability_signals_label'],
      '#insights_aria_label' => $presentation['insights_aria_label'],
      '#dismiss_hint_template' => $presentation['dismiss_hint_template'],
      '#cache' => $cache_meta,
    ];
  }

  /**
   * @param array<string, mixed> $payload
   * @param array<string, mixed> $cache_meta
   *
   * @return array<string, mixed>|null
   *   Render array for mel_observability_panel, or NULL when not staff / empty.
   */
  private function buildObservabilityPanelRenderArray(array $payload, array $cache_meta): ?array {
    $surface = $this->resolver->resolve();
    if (!$this->observabilityDiagnosticsAccess->mayViewDiagnostics($surface, $this->currentUser)) {
      return NULL;
    }
    $traces = $payload['traces'] ?? [];
    if (!is_array($traces) || $traces === []) {
      return NULL;
    }
    $panel_attrs = $payload['accessibility']['diagnostic_region_attributes'] ?? [];
    if (!is_array($panel_attrs)) {
      $panel_attrs = [];
    }
    $classes = $panel_attrs['class'] ?? [];
    if (is_string($classes)) {
      $classes = array_values(array_filter(preg_split('/\s+/', trim($classes)) ?: []));
    }
    elseif (!is_array($classes)) {
      $classes = [];
    }
    $classes[] = 'mel-observability-panel';
    $panel_attrs['class'] = array_values(array_unique(array_filter($classes)));

    $registry_meta = $payload['registry_meta'] ?? [];
    if (!is_array($registry_meta)) {
      $registry_meta = [];
    }
    $telemetry = $payload['telemetry_boundary_reference'] ?? [];
    if (!is_array($telemetry)) {
      $telemetry = [];
    }

    return [
      '#theme' => 'mel_observability_panel',
      '#attributes' => $panel_attrs,
      '#traces' => $traces,
      '#registry_meta' => $registry_meta,
      '#telemetry_boundary_reference' => $telemetry,
      '#cache' => $cache_meta,
    ];
  }

}
