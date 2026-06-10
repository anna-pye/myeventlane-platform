<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Render\Element;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_surface\MelObservabilityDiagnosticsAccess;
use Drupal\myeventlane_surface\MelExperienceManager;
use Drupal\myeventlane_surface\MelIntelligenceManager;
use Drupal\myeventlane_surface\MelObservabilityManager;
use Drupal\myeventlane_surface\MelGovernancePresentationSanitizer;
use Drupal\myeventlane_surface\MelOperationalPolicyManager;
use Drupal\myeventlane_surface\MelStateManager;
use Drupal\myeventlane_core\MelSurfaceId;
use Drupal\myeventlane_surface\MelWorkflowManager;
use Drupal\myeventlane_surface\SurfaceResolver;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Single facade: gathers MEL governance payloads for Event Studio (no new orchestration).
 *
 * Composes existing surface managers in the same order as SurfaceNegotiator memoisation.
 */
final class EventStudioGovernanceBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly ?MelWorkflowManager $workflowManager,
    private readonly ?MelStateManager $stateManager,
    private readonly ?MelOperationalPolicyManager $operationalPolicyManager,
    private readonly ?MelExperienceManager $experienceManager,
    private readonly ?MelIntelligenceManager $intelligenceManager,
    private readonly ?MelObservabilityManager $observabilityManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly ?MelObservabilityDiagnosticsAccess $observabilityDiagnosticsAccess,
    private readonly ?SurfaceResolver $surfaceResolver,
    private readonly ?MelGovernancePresentationSanitizer $governancePresentationSanitizer,
    private readonly LoggerInterface $logger,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  public function isEnabled(): bool {
    return $this->workflowManager instanceof MelWorkflowManager
      && $this->stateManager instanceof MelStateManager
      && $this->operationalPolicyManager instanceof MelOperationalPolicyManager
      && $this->experienceManager instanceof MelExperienceManager
      && $this->intelligenceManager instanceof MelIntelligenceManager
      && $this->observabilityManager instanceof MelObservabilityManager
      && $this->surfaceResolver instanceof SurfaceResolver
      && $this->governancePresentationSanitizer instanceof MelGovernancePresentationSanitizer;
  }

  /**
   * Alias for buildForEvent() — full page / form governance bundle.
   *
   * @return array<string, mixed>
   *   Keys: enabled, twig, js, #cache.
   */
  public function buildGovernancePayload(NodeInterface $event): array {
    return $this->buildForEvent($event);
  }

  /**
   * Incremental JSON-oriented bundle for AJAX refresh (no Twig).
   *
   * @return array<string, mixed>
   *   Keys: enabled, js, #cache.
   */
  public function buildIncrementalPayload(NodeInterface $event): array {
    $full = $this->buildForEvent($event);
    return [
      'enabled' => $full['enabled'],
      'js' => is_array($full['js'] ?? NULL) ? $full['js'] : ['enabled' => FALSE],
      '#cache' => $full['#cache'] ?? ['contexts' => ['user', 'route'], 'tags' => $this->nodeCacheTags($event)],
    ];
  }

  /**
   * Governance delta for Event Studio JS versus an optional client baseline snapshot.
   *
   * @param array<string, mixed>|null $baseline_js
   *   Prior merged melEventStudioGovernance-shaped object from the client.
   *
   * @return array<string, mixed>
   *   Keys: governance_enabled, unchanged, delta (array subset or full js).
   */
  public function buildDeltaPayload(NodeInterface $event, ?array $baseline_js): array {
    $incremental = $this->buildIncrementalPayload($event);
    $js = $incremental['js'];
    if (!is_array($js)) {
      $js = ['enabled' => FALSE];
    }
    if (!$incremental['enabled']) {
      return [
        'governance_enabled' => FALSE,
        'unchanged' => FALSE,
        'delta' => $js,
      ];
    }
    if ($baseline_js === NULL || $baseline_js === []) {
      return [
        'governance_enabled' => TRUE,
        'unchanged' => FALSE,
        'delta' => $js,
      ];
    }
    $delta = self::computeJsDelta($js, $baseline_js);
    return [
      'governance_enabled' => TRUE,
      'unchanged' => $delta === [],
      'delta' => $delta,
    ];
  }

  /**
   * Computes a shallow key-level delta for governance JS snapshots.
   *
   * @param array<string, mixed> $next_js
   * @param array<string, mixed> $baseline_js
   *
   * @return array<string, mixed>
   */
  public static function computeJsDelta(array $next_js, array $baseline_js): array {
    $delta = [];
    foreach ($next_js as $key => $value) {
      $prev = $baseline_js[$key] ?? NULL;
      if (json_encode($prev) !== json_encode($value)) {
        $delta[$key] = $value;
      }
    }
    return $delta;
  }

  /**
   * Build Twig variables, drupalSettings subset, and merged cache metadata.
   *
   * @return array<string, mixed>
   *   Keys: enabled, twig, js, #cache.
   */
  public function buildForEvent(NodeInterface $event): array {
    if (!$this->isEnabled()) {
      return $this->disabledBundle($event);
    }

    try {
      return $this->buildEnabledBundle($event);
    }
    catch (\Throwable $e) {
      $this->logger->error('Event Studio governance build failed for event @nid: @message', [
        '@nid' => (string) ($event->id() ?? 'new'),
        '@message' => $e->getMessage(),
      ]);
      return $this->disabledBundle($event);
    }
  }

  /**
   * @return array<string, mixed>
   */
  private function disabledBundle(NodeInterface $event): array {
    return [
      'enabled' => FALSE,
      'twig' => ['enabled' => FALSE],
      'js' => ['enabled' => FALSE],
      '#cache' => [
        'contexts' => ['user', 'route'],
        'tags' => $this->nodeCacheTags($event),
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildEnabledBundle(NodeInterface $event): array {
    if (!$this->workflowManager instanceof MelWorkflowManager
      || !$this->stateManager instanceof MelStateManager
      || !$this->operationalPolicyManager instanceof MelOperationalPolicyManager
      || !$this->experienceManager instanceof MelExperienceManager
      || !$this->intelligenceManager instanceof MelIntelligenceManager
      || !$this->observabilityManager instanceof MelObservabilityManager
      || !$this->surfaceResolver instanceof SurfaceResolver
      || !$this->governancePresentationSanitizer instanceof MelGovernancePresentationSanitizer) {
      return $this->disabledBundle($event);
    }

    $workflow = $this->workflowManager->buildPageAttachments();
    $state = $this->stateManager->buildPageInterpretation();
    $policy = $this->operationalPolicyManager->buildPageInterpretation();
    $experience = $this->experienceManager->buildPageAttachments($policy);
    $surface = $this->surfaceResolver->resolve();
    $intelligence = $this->intelligenceManager->buildPagePayload($experience, $policy);
    $observability = $this->observabilityManager->buildPagePayload(
      $state,
      $workflow,
      $experience,
      $intelligence,
      $policy,
    );
    $intelligence_presented = $this->governancePresentationSanitizer->sanitizeIntelligencePayloadForPresentation(
      $intelligence,
      $surface,
    );

    $meta = CacheableMetadata::createFromRenderArray(['#cache' => $workflow['#cache'] ?? []]);
    $meta->merge(CacheableMetadata::createFromRenderArray(['#cache' => $state['#cache'] ?? []]));
    $meta->merge(CacheableMetadata::createFromRenderArray(['#cache' => $policy['#cache'] ?? []]));
    $meta->merge(CacheableMetadata::createFromRenderArray(['#cache' => $experience['#cache'] ?? []]));
    $meta->merge(CacheableMetadata::createFromRenderArray(['#cache' => $intelligence['#cache'] ?? []]));
    $meta->merge(CacheableMetadata::createFromRenderArray(['#cache' => $observability['#cache'] ?? []]));
    foreach ($this->nodeCacheTags($event) as $tag) {
      $meta->addCacheTags([$tag]);
    }

    $cache = $meta->getCacheTags() !== [] || $meta->getCacheContexts() !== []
      ? [
        'contexts' => $meta->getCacheContexts(),
        'tags' => $meta->getCacheTags(),
        'max-age' => $meta->getCacheMaxAge(),
      ]
      : ['contexts' => ['user', 'route'], 'tags' => $this->nodeCacheTags($event)];

    $primary_cta = $this->resolvePrimaryCtaDescriptor($workflow, $intelligence_presented);

    $twig = [
      'enabled' => TRUE,
      'surface' => $surface->value,
      'state' => $this->stripCacheDeep($state),
      'workflow_primary' => $workflow['region_primary'] ?? [],
      'experience' => $this->experienceTwigSubset($experience),
      'intelligence' => $this->buildIntelligencePanelVars($intelligence_presented, $surface),
      'policy' => $this->policyTwigSubset($policy),
      'observability_panel' => $this->buildObservabilityPanelRenderArray($observability, $surface),
    ];

    $js_raw = $this->buildJsPayload(
      $state,
      $primary_cta,
      $intelligence_presented,
      $experience,
      $policy,
      $observability,
      $surface,
    );
    $js = $this->governancePresentationSanitizer
      ->sanitizeEventStudioGovernanceJs($js_raw, $surface);

    return [
      'enabled' => TRUE,
      'twig' => $twig,
      'js' => $js,
      '#cache' => $cache,
    ];
  }

  /**
   * @return list<string>
   */
  private function nodeCacheTags(NodeInterface $event): array {
    if ($event->isNew()) {
      return [];
    }
    return ['node:' . $event->id()];
  }

  /**
   * @param array<string, mixed> $workflow
   * @param array<string, mixed> $intelligence
   *
   * @return array<string, string>
   */
  private function resolvePrimaryCtaDescriptor(array $workflow, array $intelligence): array {
    $region = $workflow['region_primary'] ?? [];
    $link = is_array($region) && $region !== [] ? $this->findFirstLinkInRenderArray($region) : NULL;
    if ($link !== NULL) {
      return [
        'mode' => 'workflow_navigate',
        'label' => $link['title'],
        'href' => $link['url'],
      ];
    }

    $items = $intelligence['items'] ?? [];
    if (is_array($items) && $items !== []) {
      $first = $items[0];
      if (is_array($first)) {
        $headline = trim((string) ($first['headline'] ?? ''));
        if ($headline !== '') {
          return [
            'mode' => 'publish_step',
            'label' => (string) $this->t('Review publish'),
            'headline' => $headline,
          ];
        }
      }
    }

    return [
      'mode' => 'publish_step',
      'label' => (string) $this->t('Review publish'),
    ];
  }

  /**
   * @param array<string, mixed> $element
   *
   * @return array{title: string, url: string}|null
   */
  private function findFirstLinkInRenderArray(array $element): ?array {
    if (isset($element['#type']) && $element['#type'] === 'link' && isset($element['#url']) && $element['#url'] instanceof Url) {
      return [
        'title' => (string) ($element['#title'] ?? ''),
        'url' => $element['#url']->toString(),
      ];
    }
    foreach (Element::children($element) as $key) {
      $child = $element[$key];
      if (is_array($child)) {
        $found = $this->findFirstLinkInRenderArray($child);
        if ($found !== NULL) {
          return $found;
        }
      }
    }
    return NULL;
  }

  /**
   * @param array<string, mixed> $experience
   *
   * @return array<string, mixed>
   */
  private function experienceTwigSubset(array $experience): array {
    $resume = $experience['resume_candidates'] ?? [];
    $continuation = $experience['continuation'] ?? [];
    $escalation = $experience['escalation_safe'] ?? [];
    if (!is_array($resume)) {
      $resume = [];
    }
    if (!is_array($continuation)) {
      $continuation = [];
    }
    if (!is_array($escalation)) {
      $escalation = [];
    }
    $resume_lines = [];
    foreach ($resume as $row) {
      if (!is_array($row)) {
        continue;
      }
      $id = (string) ($row['experience_id'] ?? '');
      if ($id !== '') {
        $resume_lines[] = ucfirst(str_replace('_', ' ', $id));
      }
    }
    $sequencing = [];
    if (isset($continuation['cta_sequencing_notes']) && is_array($continuation['cta_sequencing_notes'])) {
      $sequencing = $continuation['cta_sequencing_notes'];
    }
    return [
      'resume_candidates' => $resume,
      'resume_lines' => $resume_lines,
      'continuation' => $continuation,
      'continuation_notes' => $sequencing,
      'escalation_safe' => $escalation,
      'primary_experience_id' => $experience['primary_experience_id'] ?? NULL,
    ];
  }

  /**
   * @param array<string, mixed> $policy
   *
   * @return array<string, mixed>
   */
  private function policyTwigSubset(array $policy): array {
    $hints = $policy['communication_hints'] ?? [];
    $lifecycle = $policy['lifecycle_safety'] ?? [];
    if (!is_array($hints)) {
      $hints = [];
    }
    if (!is_array($lifecycle)) {
      $lifecycle = [];
    }
    return [
      'surface_policy_profile' => (string) ($policy['surface_policy_profile'] ?? ''),
      'communication_hints' => $hints,
      'lifecycle_safety' => $lifecycle,
      'hint_lines' => $this->policyHintLines($hints),
      'lifecycle_lines' => $this->lifecycleSafetyLines($lifecycle),
    ];
  }

  /**
   * @param array<string, bool> $hints
   *
   * @return list<string>
   */
  private function policyHintLines(array $hints): array {
    $lines = [];
    if (!empty($hints['trust_sensitive_tone'])) {
      $lines[] = (string) $this->t('Trust-sensitive tone is active.');
    }
    if (!empty($hints['escalation_tone'])) {
      $lines[] = (string) $this->t('Escalation-aware messaging is active.');
    }
    if (!empty($hints['moderation_safe_tone'])) {
      $lines[] = (string) $this->t('Moderation-safe tone hints apply.');
    }
    if (!empty($hints['reduced_urgency_mode'])) {
      $lines[] = (string) $this->t('Urgency and promotional cadence are reduced.');
    }
    if (!empty($hints['reassurance_priority'])) {
      $lines[] = (string) $this->t('Reassurance is emphasised for this moment.');
    }
    return $lines;
  }

  /**
   * @param array<string, bool> $lifecycle
   *
   * @return list<string>
   */
  private function lifecycleSafetyLines(array $lifecycle): array {
    $lines = [];
    foreach ($lifecycle as $id => $active) {
      if (!$active) {
        continue;
      }
      $lines[] = match ($id) {
        'stale_draft_protection' => (string) $this->t('Draft lifecycle prompts favour completion.'),
        'abandoned_checkout_safety' => (string) $this->t('Checkout recovery tone may apply elsewhere in the product.'),
        'inactive_vendor_safeguard' => (string) $this->t('Vendor setup prompts stay focused until onboarding completes.'),
        'cancelled_event_suppression' => (string) $this->t('Growth prompts are reduced for cancelled events.'),
        default => '',
      };
    }
    return array_values(array_filter($lines, static fn (string $s): bool => $s !== ''));
  }

  /**
   * @param array<string, mixed> $intelligence
   *
   * @return array<string, mixed>
   */
  private function buildIntelligencePanelVars(array $intelligence, MelSurfaceId $surface): array {
    $items = $intelligence['items'] ?? [];
    $insights = $intelligence['insights'] ?? [];
    if (!is_array($items)) {
      $items = [];
    }
    if (!is_array($insights)) {
      $insights = [];
    }
    $region = $intelligence['accessibility']['region_attributes'] ?? [];
    $reduced = $intelligence['accessibility']['reduced_motion'] ?? [];
    if (!is_array($region)) {
      $region = [];
    }
    if (!is_array($reduced)) {
      $reduced = [];
    }
    $merged = array_merge($region, $reduced);
    $classes = $merged['class'] ?? [];
    if (is_string($classes)) {
      $classes = array_values(array_filter(preg_split('/\s+/', trim($classes)) ?: []));
    }
    elseif (!is_array($classes)) {
      $classes = [];
    }
    $classes[] = 'mel-intelligence-panel';
    $classes[] = 'mel-studio-intelligence-panel';
    $merged['class'] = array_values(array_unique(array_filter($classes)));

    return [
      'attributes' => $merged,
      'items' => $items,
      'insights' => $insights,
      'explainability_details' => $surface === MelSurfaceId::Staff,
    ];
  }

  /**
   * @param array<string, mixed> $payload
   * @param array<string, mixed> $cache_meta
   *
   * @return array<string, mixed>|null
   */
  private function buildObservabilityPanelRenderArray(array $payload, MelSurfaceId $surface): ?array {
    if (!$this->observabilityDiagnosticsAccess instanceof MelObservabilityDiagnosticsAccess) {
      return NULL;
    }
    if (!$this->observabilityDiagnosticsAccess->mayViewDiagnostics($surface, $this->currentUser)) {
      return NULL;
    }
    $traces = $payload['traces'] ?? [];
    if (!is_array($traces) || $traces === []) {
      return NULL;
    }
    $cache_meta = $payload['#cache'] ?? ['contexts' => ['user', 'route'], 'tags' => []];

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
    $classes[] = 'mel-studio-observability-panel';
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

  /**
   * @param array<string, mixed> $state
   * @param array<string, string> $primary_cta
   * @param array<string, mixed> $intelligence
   * @param array<string, mixed> $experience
   * @param array<string, mixed> $policy
   * @param array<string, mixed> $observability
   *
   * @return array<string, mixed>
   */
  private function buildJsPayload(
    array $state,
    array $primary_cta,
    array $intelligence,
    array $experience,
    array $policy,
    array $observability,
    MelSurfaceId $surface,
  ): array {
    $summaries = $state['readiness_summaries'] ?? [];
    if (!is_array($summaries)) {
      $summaries = [];
    }
    $summaries = array_values(array_filter(array_map(static fn ($s): string => is_string($s) ? trim($s) : '', $summaries)));

    $next_best = '';
    $items = $intelligence['items'] ?? [];
    if (is_array($items) && $items !== []) {
      $first = $items[0];
      if (is_array($first)) {
        $next_best = trim((string) ($first['headline'] ?? ''));
      }
    }
    if ($next_best === '') {
      $insights = $intelligence['insights'] ?? [];
      if (is_array($insights) && $insights !== []) {
        $ins = $insights[0];
        if (is_array($ins) && isset($ins['headline'])) {
          $next_best = trim((string) $ins['headline']);
        }
      }
    }

    $checklist = [];
    if (is_array($items)) {
      foreach ($items as $row) {
        if (!is_array($row)) {
          continue;
        }
        $checklist[] = [
          'kind' => 'governance_item',
          'id' => (string) ($row['id'] ?? ''),
          'text' => trim((string) ($row['headline'] ?? '')),
          'target' => '',
        ];
      }
    }
    $insights_rows = $intelligence['insights'] ?? [];
    if (is_array($insights_rows)) {
      foreach ($insights_rows as $row) {
        if (!is_array($row)) {
          continue;
        }
        $checklist[] = [
          'kind' => 'governance_insight',
          'id' => (string) ($row['id'] ?? ''),
          'text' => trim((string) ($row['headline'] ?? '')),
          'target' => '',
        ];
      }
    }

    $telemetry_tier = '';
    if ($this->observabilityDiagnosticsAccess instanceof MelObservabilityDiagnosticsAccess
      && $this->observabilityDiagnosticsAccess->mayViewDiagnostics($surface, $this->currentUser)) {
      $tr = $observability['traces'] ?? [];
      if (is_array($tr) && $tr !== []) {
        $telemetry_tier = (string) ($observability['visibility_tier'] ?? '');
      }
    }

    $experience_subset = $this->experienceTwigSubset($experience);
    $continuation_notes = $experience_subset['continuation_notes'] ?? [];
    if (!is_array($continuation_notes)) {
      $continuation_notes = [];
    }
    $continuity_lines = array_merge($experience_subset['resume_lines'], $continuation_notes);
    $continuity_lines = array_values(array_filter(array_map(
      static fn ($s): string => is_string($s) ? trim($s) : '',
      $continuity_lines,
    ), static fn (string $s): bool => $s !== ''));

    $policy_subset = $this->policyTwigSubset($policy);
    $policy_lines = array_merge($policy_subset['hint_lines'], $policy_subset['lifecycle_lines']);

    $escalation = $experience['escalation_safe'] ?? [];
    $escalation_active_ids = [];
    if (is_array($escalation)) {
      foreach ($escalation as $key => $active) {
        if (!empty($active)) {
          $escalation_active_ids[] = (string) $key;
        }
      }
    }
    sort($escalation_active_ids);

    $op_suppression = $intelligence['operational_policy']['suppression'] ?? [];
    $suppression_active_ids = [];
    if (is_array($op_suppression)) {
      foreach ($op_suppression as $key => $active) {
        if (!empty($active)) {
          $suppression_active_ids[] = (string) $key;
        }
      }
    }
    sort($suppression_active_ids);

    $orchestration = $intelligence['orchestration'] ?? [];
    $visible_ids = $orchestration['visible_ids'] ?? [];
    if (!is_array($visible_ids)) {
      $visible_ids = [];
    }
    $visible_ids = array_values(array_map(static fn ($id): string => (string) $id, $visible_ids));

    return [
      'enabled' => TRUE,
      'version' => 2,
      'primaryCta' => $primary_cta,
      'nextBestText' => $next_best,
      'publishReadinessLead' => $summaries !== [] ? implode(' ', $summaries) : '',
      'stateSummaryLines' => $summaries,
      'continuityLines' => $continuity_lines,
      'policyLines' => $policy_lines,
      'suppressionActiveIds' => $suppression_active_ids,
      'intelligenceVisibleIds' => $visible_ids,
      'checklist' => $checklist,
      'experience' => [
        'primary_experience_id' => $experience['primary_experience_id'] ?? NULL,
        'resume_count' => is_array($experience['resume_candidates'] ?? NULL) ? count($experience['resume_candidates']) : 0,
        'resume_labels' => $experience_subset['resume_lines'],
        'escalation_active_ids' => $escalation_active_ids,
      ],
      'policy' => [
        'surface_profile' => (string) ($policy['surface_policy_profile'] ?? ''),
        'reduced_urgency' => !empty(($policy['communication_hints'] ?? [])['reduced_urgency_mode']),
      ],
      'observabilityTier' => $telemetry_tier,
    ];
  }

  /**
   * @param array<string, mixed> $data
   *
   * @return array<string, mixed>
   */
  private function stripCacheDeep(array $data): array {
    unset($data['#cache']);
    foreach ($data as $k => $v) {
      if (is_array($v)) {
        $data[$k] = $this->stripCacheDeep($v);
      }
    }
    return $data;
  }

}
