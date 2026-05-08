<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_core\MelReadinessHelper;
use Drupal\myeventlane_core\MelSurfaceId;

/**
 * Builds Event Studio governance component render arrays.
 *
 * This adapter only renders the payload produced by EventStudioGovernanceBuilder.
 * It does not recompute workflow, state, experience, policy, or intelligence.
 */
final class EventStudioGovernanceComponentBuilder {

  use StringTranslationTrait;

  public const COMPONENT_INTELLIGENCE = 'intelligence';
  public const COMPONENT_WORKFLOW = 'workflow';
  public const COMPONENT_STATE = 'state';
  public const COMPONENT_CONTINUITY = 'continuity';

  private const COMPONENTS = [
    self::COMPONENT_INTELLIGENCE,
    self::COMPONENT_WORKFLOW,
    self::COMPONENT_STATE,
    self::COMPONENT_CONTINUITY,
  ];

  public function __construct(
    TranslationInterface $string_translation,
    private readonly MelReadinessHelper $readinessHelper,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @return list<string>
   */
  public function allowedComponents(): array {
    return self::COMPONENTS;
  }

  public function isAllowedComponent(string $component): bool {
    return in_array($component, self::COMPONENTS, TRUE);
  }

  /**
   * @param array<string, mixed> $governance_bundle
   *
   * @return array<string, mixed>
   */
  public function buildAll(array $governance_bundle): array {
    $regions = [];
    foreach (self::COMPONENTS as $component) {
      $regions[$component] = $this->buildComponent($governance_bundle, $component);
    }
    return $regions;
  }

  /**
   * @param array<string, mixed> $governance_bundle
   *
   * @return array<string, mixed>
   */
  public function buildComponent(array $governance_bundle, string $component): array {
    if (!$this->isAllowedComponent($component)) {
      throw new \InvalidArgumentException(sprintf('Unsupported Event Studio governance component "%s".', $component));
    }

    $twig = is_array($governance_bundle['twig'] ?? NULL) ? $governance_bundle['twig'] : [];
    $js = is_array($governance_bundle['js'] ?? NULL) ? $governance_bundle['js'] : [];
    $cache = is_array($governance_bundle['#cache'] ?? NULL)
      ? $governance_bundle['#cache']
      : ['contexts' => ['user', 'route'], 'tags' => []];

    $build = match ($component) {
      self::COMPONENT_INTELLIGENCE => $this->buildIntelligence($twig, $js),
      self::COMPONENT_WORKFLOW => $this->buildWorkflow($twig, $js),
      self::COMPONENT_STATE => $this->buildState($twig, $js),
      self::COMPONENT_CONTINUITY => $this->buildContinuity($twig, $js),
      default => [],
    };

    CacheableMetadata::createFromRenderArray(['#cache' => $cache])->applyTo($build);
    return $build;
  }

  /**
   * @param array<string, mixed> $twig
   * @param array<string, mixed> $js
   *
   * @return array<string, mixed>
   */
  private function buildState(array $twig, array $js): array {
    $state = is_array($twig['state'] ?? NULL) ? $twig['state'] : [];
    $summaries = $this->stringList($state['readiness_summaries'] ?? $js['stateSummaryLines'] ?? []);
    $readiness = trim((string) ($js['publishReadinessLead'] ?? ''));
    if ($readiness === '' && $summaries !== []) {
      $readiness = implode(' ', $summaries);
    }

    $summary_list = $summaries !== []
      ? $this->itemList('mel-governance-state-summary-list', ['mel-state-summary__list'], $summaries, 'mel-state-summary__item')
      : [
        '#theme' => 'mel_empty_state',
        '#attributes' => ['class' => ['mel-state-summary__empty']],
        '#heading' => (string) $this->t('No readiness signals yet.'),
        '#heading_element' => 'p',
        '#why_empty' => (string) $this->t('Save your event details to refresh publishing readiness.'),
      ];

    $readiness_text = $readiness !== ''
      ? $readiness
      : (string) $this->t('Save your event details to refresh publishing readiness.');

    return [
      '#type' => 'inline_template',
      '#template' => '<section id="mel-state-summary" class="mel-studio-governance-region mel-state-summary" aria-label="{{ aria_label }}" role="region" aria-live="polite" data-mel-governance-component="state">{{ content }}</section>',
      '#context' => [
        'aria_label' => (string) $this->t('Account and publishing status'),
        'content' => [
          'heading' => [
            '#markup' => '<h3 class="mel-aside-card__title">' . (string) $this->t('Status') . '</h3>',
          ],
          'summary' => $summary_list,
          'readiness' => [
            '#theme' => 'mel_card',
            '#attributes' => [
              'class' => [
                'mel-publish-readiness-card',
                'mel-publish-card',
                'mel-publish-readiness-card--governance',
              ],
            ],
            '#title' => (string) $this->t('Publish readiness'),
            '#title_element' => 'h4',
            '#content' => [
              '#type' => 'container',
              '#attributes' => [
                'id' => 'mel-publish-readiness',
                'class' => ['mel-publish-readiness-body'],
                'data-mel-publish-readiness-host' => '1',
              ],
              'text' => ['#plain_text' => $readiness_text],
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * @param array<string, mixed> $twig
   * @param array<string, mixed> $js
   *
   * @return array<string, mixed>
   */
  private function buildWorkflow(array $twig, array $js): array {
    $primary_cta = is_array($js['primaryCta'] ?? NULL) ? $js['primaryCta'] : [];
    $primary_label = trim((string) ($primary_cta['label'] ?? ''));
    $next_best = trim((string) ($js['nextBestText'] ?? ''));
    $href = (string) ($primary_cta['mode'] ?? '') === 'workflow_navigate'
      ? trim((string) ($primary_cta['href'] ?? ''))
      : '';
    $workflow_primary = is_array($twig['workflow_primary'] ?? NULL) ? $twig['workflow_primary'] : [];

    return [
      '#type' => 'inline_template',
      '#template' => '<section id="mel-workflow-panel" class="mel-studio-governance-region mel-workflow-panel" aria-label="{{ aria_label }}" role="region" aria-live="polite" data-mel-governance-component="workflow">{{ content }}</section>',
      '#context' => [
        'aria_label' => (string) $this->t('Guided next step'),
        'content' => [
          'primary' => $workflow_primary !== [] ? [
            '#type' => 'container',
            '#attributes' => ['class' => ['mel-workflow-panel__surface-region']],
            'region' => $workflow_primary,
          ] : [],
          'next' => [
            '#theme' => 'mel_guided_next_step',
            '#attributes' => ['class' => ['mel-builder-next-best-block']],
            '#content' => [
              '#type' => 'inline_template',
              '#template' => '<p class="mel-builder-next-best-label">{{ label }}</p><p id="mel-builder-next-best" class="mel-builder-next-best" aria-live="polite">{{ next_best }}</p><button type="button" class="mel-btn mel-btn--primary mel-builder-primary-cta" id="mel-builder-primary-cta"{% if href %} data-mel-primary-cta-href="{{ href }}"{% endif %}>{{ cta_label }}</button>',
              '#context' => [
                'label' => (string) $this->t('Next best action'),
                'next_best' => $next_best,
                'cta_label' => $primary_label !== '' ? $primary_label : (string) $this->t('Review publish'),
                'href' => $href,
              ],
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * @param array<string, mixed> $twig
   * @param array<string, mixed> $js
   *
   * @return array<string, mixed>
   */
  private function buildContinuity(array $twig, array $js): array {
    $experience = is_array($twig['experience'] ?? NULL) ? $twig['experience'] : [];
    $resume_lines = $this->stringList($experience['resume_lines'] ?? []);
    $notes = $this->stringList($experience['continuation_notes'] ?? []);
    $lines = $this->stringList($js['continuityLines'] ?? array_merge($resume_lines, $notes));
    $has_lines = $lines !== [];

    return [
      '#type' => 'inline_template',
      '#template' => '<section id="mel-experience-panel" class="mel-studio-governance-region mel-experience-panel" aria-label="{{ aria_label }}" role="region" aria-live="polite" data-mel-governance-component="continuity">{{ content }}</section>',
      '#context' => [
        'aria_label' => (string) $this->t('Continuity'),
        'content' => [
          'heading' => [
            '#markup' => '<h3 class="mel-aside-card__title">' . (string) $this->t('Continuity') . '</h3>',
          ],
          'lines' => $has_lines
            ? $this->itemList('mel-governance-continuity-list', ['mel-experience-panel__list'], $lines)
            : [
              '#theme' => 'mel_empty_state',
              '#attributes' => [
                'id' => 'mel-governance-continuity-empty',
                'class' => ['mel-experience-panel__empty'],
              ],
              '#heading' => (string) $this->t('No continuity prompts for this moment.'),
              '#heading_element' => 'p',
            ],
          'notes_anchor' => [
            '#markup' => '<ul id="mel-governance-continuity-notes" class="mel-experience-panel__notes" hidden></ul>',
          ],
        ],
      ],
    ];
  }

  /**
   * @param array<string, mixed> $twig
   * @param array<string, mixed> $js
   *
   * @return array<string, mixed>
   */
  private function buildIntelligence(array $twig, array $js): array {
    $intelligence = is_array($twig['intelligence'] ?? NULL) ? $twig['intelligence'] : [];
    $items = is_array($intelligence['items'] ?? NULL) ? $intelligence['items'] : [];
    $insights = is_array($intelligence['insights'] ?? NULL) ? $intelligence['insights'] : [];
    $attributes = is_array($intelligence['attributes'] ?? NULL) ? $intelligence['attributes'] : [];
    $checklist = $this->checklistRows($js['checklist'] ?? []);

    $explainability_details = !empty($intelligence['explainability_details']);
    $surface_value = isset($twig['surface']) && is_string($twig['surface']) ? $twig['surface'] : MelSurfaceId::Vendor->value;
    $presentation_surface = MelSurfaceId::tryFrom($surface_value) ?? MelSurfaceId::Vendor;
    $presentation = $this->readinessHelper->intelligencePanelThemeVariables($presentation_surface);

    $panel = ($items !== [] || $insights !== [])
      ? [
        '#theme' => 'mel_intelligence_panel',
        '#attributes' => $attributes,
        '#items' => $items,
        '#insights' => $insights,
        '#explainability_details' => $explainability_details,
        '#intelligence_region_heading' => $presentation['intelligence_region_heading'],
        '#explainability_summary_label' => $presentation['explainability_summary_label'],
        '#explainability_signals_label' => $presentation['explainability_signals_label'],
        '#insights_aria_label' => $presentation['insights_aria_label'],
        '#dismiss_hint_template' => $presentation['dismiss_hint_template'],
      ]
      : [
        '#theme' => 'mel_empty_state',
        '#attributes' => ['class' => ['mel-intelligence-panel__empty']],
        '#heading' => $this->readinessHelper->eventStudioIntelligenceEmptyHeading(),
        '#heading_element' => 'p',
        '#why_empty' => $this->readinessHelper->eventStudioIntelligenceEmptyBody(),
      ];

    return [
      '#type' => 'inline_template',
      '#template' => '<section id="mel-intelligence-panel-region" class="mel-studio-governance-region mel-intelligence-panel-region" aria-label="{{ aria_label }}" role="region" aria-live="polite" data-mel-governance-component="intelligence">{{ content }}</section>',
      '#context' => [
        'aria_label' => (string) $this->t('Guidance suggestions'),
        'content' => [
          'panel' => $panel,
          'checklist' => [
            '#type' => 'inline_template',
            '#template' => '<details class="mel-builder-checklist-details mel-insights-card mel-insights-card--sidebar mel-insights-card--governed"><summary class="mel-builder-checklist-summary">{{ summary }}</summary><div id="mel-insights-list" class="mel-insights-checklist" role="list" data-mel-field-tips-list="1">{{ rows }}</div></details>',
            '#context' => [
              'summary' => (string) $this->t('Field tips (live)'),
              'rows' => $checklist !== []
                ? $this->buildChecklistRows($checklist)
                : [
                  '#theme' => 'mel_empty_state',
                  '#attributes' => ['class' => ['mel-insight-item', 'mel-insight-item--governance']],
                  '#heading' => $this->readinessHelper->eventStudioFieldTipsEmptyHeading(),
                  '#heading_element' => 'p',
                ],
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * @param mixed $rows
   *
   * @return list<array{text: string}>
   */
  private function checklistRows(mixed $rows): array {
    if (!is_array($rows)) {
      return [];
    }
    $out = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $text = trim((string) ($row['text'] ?? ''));
      if ($text !== '') {
        $out[] = ['text' => $text];
      }
    }
    return $out;
  }

  /**
   * @param list<array{text: string}> $rows
   *
   * @return array<string, mixed>
   */
  private function buildChecklistRows(array $rows): array {
    $items = [];
    foreach ($rows as $row) {
      $items[] = [
        '#type' => 'inline_template',
        '#template' => '<div class="mel-insight-item mel-insight-item--governance mel-insight-item--low" role="listitem"><span class="mel-insight-item__text">{{ text }}</span></div>',
        '#context' => ['text' => $row['text']],
      ];
    }
    return $items;
  }

  /**
   * @param mixed $raw
   *
   * @return list<string>
   */
  private function stringList(mixed $raw): array {
    if (!is_array($raw)) {
      return [];
    }
    $values = [];
    foreach ($raw as $value) {
      $value = trim((string) $value);
      if ($value !== '') {
        $values[] = $value;
      }
    }
    return $values;
  }

  /**
   * @param list<string> $classes
   * @param list<string> $items
   *
   * @return array<string, mixed>
   */
  private function itemList(string $id, array $classes, array $items, string $item_class = ''): array {
    $list_items = [];
    foreach ($items as $item) {
      $list_items[] = $item_class !== ''
        ? ['value' => ['#plain_text' => $item], 'attributes' => ['class' => [$item_class]]]
        : ['#plain_text' => $item];
    }

    return [
      '#theme' => 'item_list',
      '#list_type' => 'ul',
      '#items' => $list_items,
      '#attributes' => [
        'id' => $id,
        'class' => $classes,
      ],
    ];
  }

}
