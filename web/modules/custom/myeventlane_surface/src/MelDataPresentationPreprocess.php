<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\Core\Template\Attribute;

/**
 * Central preprocess normalisation for MELDataPresentationSystem theme hooks.
 */
final class MelDataPresentationPreprocess {

  public function __construct(
    private readonly SurfaceResolver $surfaceResolver,
    private readonly MelDataPresentationManager $dataPresentationManager,
    private readonly MelDataTableHelper $dataTableHelper,
    private readonly MelMetricHelper $metricHelper,
    private readonly MelAnalyticsHelper $analyticsHelper,
    private readonly MelDataAccessibilityHelper $dataAccessibilityHelper,
  ) {}

  /**
   * @param array<string, mixed> $variables
   */
  public function preprocessForHook(string $hook, array &$variables): void {
    match ($hook) {
      'mel_data_table' => $this->preprocessDataTable($variables),
      'mel_table_toolbar',
      'mel_table_empty',
      'mel_table_pagination',
      'mel_table_actions',
      'mel_table_status' => $this->preprocessTableFragment($hook, $variables),
      'mel_metric_card' => $this->preprocessMetricCard($variables),
      'mel_metric_grid',
      'mel_stat_panel',
      'mel_summary_bar' => $this->preprocessMetricFamily($hook, $variables),
      'mel_analytics_frame' => $this->preprocessAnalyticsFrame($variables),
      'mel_activity_feed' => $this->preprocessActivityFeed($variables),
      'mel_activity_item',
      'mel_timeline',
      'mel_event_log' => $this->preprocessActivityFragment($hook, $variables),
      'mel_export_panel',
      'mel_export_notice' => $this->preprocessExportFamily($hook, $variables),
      default => NULL,
    };
  }

  /**
   * @param array<string, mixed> $variables
   */
  private function preprocessDataTable(array &$variables): void {
    $surface = $this->surfaceResolver->resolve();
    $responsive_stack = (bool) ($variables['responsive_stack'] ?? FALSE);

    $variables += [
      'attributes' => new Attribute(),
      'scroll_attributes' => new Attribute(),
      'content' => '',
      'toolbar' => NULL,
      'status' => NULL,
      'actions' => NULL,
      'empty' => NULL,
      'pagination' => NULL,
      'contract' => NULL,
      'responsive_stack' => FALSE,
      'scroll_region_label' => '',
      'caption_id' => '',
    ];

    if (!$variables['attributes'] instanceof Attribute) {
      $variables['attributes'] = new Attribute($variables['attributes'] ?? []);
    }
    if (!$variables['scroll_attributes'] instanceof Attribute) {
      $variables['scroll_attributes'] = new Attribute($variables['scroll_attributes'] ?? []);
    }

    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->addClass('mel-data-table');
    $attributes->addClass('mel-component');
    $attributes->setAttribute('data-mel-component', 'mel_data_table');
    $attributes->setAttribute('data-mel-surface', $surface->value);
    $attributes->setAttribute('data-mel-density', $this->dataPresentationManager->getPageContext()['density'] ?? 'comfortable');

    $contract = $variables['contract'];
    if (is_string($contract) && $contract !== '') {
      $attributes->setAttribute('data-mel-data-contract', $contract);
    }

    foreach ($this->dataTableHelper->getTableModifierClasses($surface, $responsive_stack) as $class) {
      $attributes->addClass($class);
    }

    /** @var \Drupal\Core\Template\Attribute $scroll_attributes */
    $scroll_attributes = $variables['scroll_attributes'];
    $scroll_attributes->addClass('mel-data-table__scroll');

    $caption_id = (string) $variables['caption_id'];
    $scroll_label = (string) $variables['scroll_region_label'];
    if ($caption_id !== '' && $scroll_label !== '') {
      $this->dataAccessibilityHelper->applyTableScrollRegion($variables, $caption_id, $scroll_label);
    }
  }

  /**
   * @param array<string, mixed> $variables
   */
  private function preprocessTableFragment(string $hook, array &$variables): void {
    $surface = $this->surfaceResolver->resolve();
    $variables += ['attributes' => new Attribute(), 'content' => ''];
    if (!$variables['attributes'] instanceof Attribute) {
      $variables['attributes'] = new Attribute($variables['attributes'] ?? []);
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->addClass('mel-component');
    $attributes->setAttribute('data-mel-component', $hook);
    $attributes->setAttribute('data-mel-surface', $surface->value);

    match ($hook) {
      'mel_table_toolbar' => $attributes->addClass(...$this->dataTableHelper->getToolbarClasses($surface)),
      'mel_table_empty' => $attributes->addClass('mel-table-empty'),
      'mel_table_pagination' => $attributes->addClass('mel-table-pagination'),
      'mel_table_actions' => $attributes->addClass('mel-table-actions'),
      'mel_table_status' => $attributes->addClass('mel-table-status'),
      default => NULL,
    };
  }

  /**
   * @param array<string, mixed> $variables
   */
  private function preprocessMetricCard(array &$variables): void {
    $surface = $this->surfaceResolver->resolve();
    $variables += [
      'attributes' => new Attribute(),
      'label' => '',
      'value' => '',
      'delta' => NULL,
      'trend' => NULL,
      'status_variant' => NULL,
      'loading' => FALSE,
      'empty_message' => NULL,
      'contract' => NULL,
    ];
    if (!$variables['attributes'] instanceof Attribute) {
      $variables['attributes'] = new Attribute($variables['attributes'] ?? []);
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->addClass('mel-metric-card');
    $attributes->addClass('mel-component');
    $attributes->addClass($this->metricHelper->getMetricCardModifier($surface));
    $attributes->setAttribute('data-mel-component', 'mel_metric_card');
    $attributes->setAttribute('data-mel-surface', $surface->value);
    $attributes->setAttribute('data-mel-density', $this->dataPresentationManager->getPageContext()['density'] ?? 'comfortable');

    $contract = $variables['contract'];
    if (is_string($contract) && $contract !== '') {
      $attributes->setAttribute('data-mel-data-contract', $contract);
    }

    if (!empty($variables['loading'])) {
      $this->dataAccessibilityHelper->applyLoadingBusyState($variables);
    }
    else {
      $this->dataAccessibilityHelper->applyMetricLiveRegion($variables);
    }
  }

  /**
   * @param array<string, mixed> $variables
   */
  private function preprocessMetricFamily(string $hook, array &$variables): void {
    $surface = $this->surfaceResolver->resolve();
    $variables += ['attributes' => new Attribute(), 'content' => ''];
    if (!$variables['attributes'] instanceof Attribute) {
      $variables['attributes'] = new Attribute($variables['attributes'] ?? []);
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->addClass('mel-component');
    $attributes->setAttribute('data-mel-component', $hook);
    $attributes->setAttribute('data-mel-surface', $surface->value);
    $attributes->setAttribute('data-mel-density', $this->dataPresentationManager->getPageContext()['density'] ?? 'comfortable');

    match ($hook) {
      'mel_metric_grid' => $attributes->addClass('mel-metric-grid'),
      'mel_stat_panel' => $attributes->addClass('mel-stat-panel'),
      'mel_summary_bar' => $attributes->addClass('mel-summary-bar'),
      default => NULL,
    };
  }

  /**
   * @param array<string, mixed> $variables
   */
  private function preprocessAnalyticsFrame(array &$variables): void {
    $surface = $this->surfaceResolver->resolve();
    $variables += [
      'attributes' => new Attribute(),
      'summary_attributes' => new Attribute(),
      'content' => '',
      'summary' => '',
      'empty' => NULL,
      'loading' => NULL,
      'legend' => NULL,
      'contract' => NULL,
      'engine' => NULL,
      'summary_id' => '',
    ];
    if (!$variables['attributes'] instanceof Attribute) {
      $variables['attributes'] = new Attribute($variables['attributes'] ?? []);
    }
    if (!$variables['summary_attributes'] instanceof Attribute) {
      $variables['summary_attributes'] = new Attribute($variables['summary_attributes'] ?? []);
    }

    /** @var \Drupal\Core\Template\Attribute $summary_attributes_init */
    $summary_attributes_init = $variables['summary_attributes'];
    $summary_attributes_init->addClass('mel-analytics-frame__summary');

    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->addClass('mel-component');
    $attributes->setAttribute('data-mel-component', 'mel_analytics_frame');
    $attributes->setAttribute('data-mel-surface', $surface->value);
    $attributes->setAttribute('data-mel-density', $this->dataPresentationManager->getPageContext()['density'] ?? 'comfortable');

    $engine = isset($variables['engine']) && is_string($variables['engine']) ? $variables['engine'] : NULL;
    foreach ($this->analyticsHelper->getFrameClasses($surface, $engine) as $class) {
      $attributes->addClass($class);
    }

    $contract = $variables['contract'];
    if (is_string($contract) && $contract !== '') {
      $attributes->setAttribute('data-mel-data-contract', $contract);
    }

    $summary_id = (string) $variables['summary_id'];
    if ($summary_id !== '') {
      /** @var \Drupal\Core\Template\Attribute $summary_attributes */
      $summary_attributes = $variables['summary_attributes'];
      $summary_attributes->setAttribute('id', $summary_id);
      $this->dataAccessibilityHelper->relateChartToSummary($variables, $summary_id);
    }
  }

  /**
   * @param array<string, mixed> $variables
   */
  private function preprocessActivityFeed(array &$variables): void {
    $surface = $this->surfaceResolver->resolve();
    $variables += [
      'attributes' => new Attribute(),
      'content' => '',
      'contract' => NULL,
      'feed_label' => '',
    ];
    if (!$variables['attributes'] instanceof Attribute) {
      $variables['attributes'] = new Attribute($variables['attributes'] ?? []);
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->addClass('mel-activity-feed');
    $attributes->addClass('mel-component');
    $attributes->setAttribute('data-mel-component', 'mel_activity_feed');
    $attributes->setAttribute('data-mel-surface', $surface->value);

    $contract = $variables['contract'];
    if (is_string($contract) && $contract !== '') {
      $attributes->setAttribute('data-mel-data-contract', $contract);
    }

    $label = (string) $variables['feed_label'];
    if ($label !== '') {
      $this->dataAccessibilityHelper->applyActivityFeedRegion($variables, $label);
    }
  }

  /**
   * @param array<string, mixed> $variables
   */
  private function preprocessActivityFragment(string $hook, array &$variables): void {
    $surface = $this->surfaceResolver->resolve();
    $variables += ['attributes' => new Attribute(), 'content' => ''];
    if (!$variables['attributes'] instanceof Attribute) {
      $variables['attributes'] = new Attribute($variables['attributes'] ?? []);
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->addClass('mel-component');
    $attributes->setAttribute('data-mel-component', $hook);
    $attributes->setAttribute('data-mel-surface', $surface->value);

    match ($hook) {
      'mel_activity_item' => $attributes->addClass('mel-activity-item'),
      'mel_timeline' => $attributes->addClass('mel-timeline'),
      'mel_event_log' => $attributes->addClass('mel-event-log'),
      default => NULL,
    };
  }

  /**
   * @param array<string, mixed> $variables
   */
  private function preprocessExportFamily(string $hook, array &$variables): void {
    $surface = $this->surfaceResolver->resolve();
    $variables += ['attributes' => new Attribute(), 'content' => '', 'contract' => NULL];
    if (!$variables['attributes'] instanceof Attribute) {
      $variables['attributes'] = new Attribute($variables['attributes'] ?? []);
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->addClass('mel-component');
    $attributes->setAttribute('data-mel-component', $hook);
    $attributes->setAttribute('data-mel-surface', $surface->value);

    match ($hook) {
      'mel_export_panel' => $attributes->addClass('mel-export-panel'),
      'mel_export_notice' => $attributes->addClass(['mel-export-notice', 'mel-alert', 'mel-alert--warning']),
      default => NULL,
    };

    $contract = $variables['contract'];
    if (is_string($contract) && $contract !== '') {
      $attributes->setAttribute('data-mel-data-contract', $contract);
    }

    if ($hook === 'mel_export_notice') {
      $this->dataAccessibilityHelper->applyExportNoticeSemantics($variables);
    }
  }

}
