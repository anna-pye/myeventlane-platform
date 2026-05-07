<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\Core\Template\Attribute;

/**
 * WCAG 2.1 AA presentation semantics for data tables, metrics, and chart shells.
 */
final class MelDataAccessibilityHelper {

  /**
   * Relates a scrollable table region to its caption for screen readers.
   *
   * @param array<string, mixed> $variables
   */
  public function applyTableScrollRegion(array &$variables, string $caption_id, string $label): void {
    if ($caption_id === '' || $label === '') {
      return;
    }
    if (!isset($variables['scroll_attributes']) || !$variables['scroll_attributes'] instanceof Attribute) {
      return;
    }
    /** @var \Drupal\Core\Template\Attribute $scroll */
    $scroll = $variables['scroll_attributes'];
    $scroll->setAttribute('role', 'region');
    $scroll->setAttribute('aria-labelledby', $caption_id);
    $scroll->setAttribute('tabindex', '0');
  }

  /**
   * Metric live region for polite updates (dashboard tiles).
   *
   * @param array<string, mixed> $variables
   */
  public function applyMetricLiveRegion(array &$variables): void {
    if (!isset($variables['attributes']) || !$variables['attributes'] instanceof Attribute) {
      return;
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->setAttribute('role', 'status');
    $attributes->setAttribute('aria-live', 'polite');
    $attributes->setAttribute('aria-atomic', 'true');
  }

  /**
   * Chart summary association — prefer visible caption + describedby for canvas/SVG hosts.
   *
   * @param array<string, mixed> $variables
   */
  public function relateChartToSummary(array &$variables, string $summary_id): void {
    if ($summary_id === '' || !isset($variables['attributes']) || !$variables['attributes'] instanceof Attribute) {
      return;
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->setAttribute('aria-describedby', $summary_id);
  }

  /**
   * Landmark region for activity feeds (static lists — not aria feed pattern).
   *
   * @param array<string, mixed> $variables
   */
  public function applyActivityFeedRegion(array &$variables, string $label): void {
    if ($label === '' || !isset($variables['attributes']) || !$variables['attributes'] instanceof Attribute) {
      return;
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->setAttribute('role', 'region');
    $attributes->setAttribute('aria-label', $label);
  }

  /**
   * Busy metric tile while upstream data resolves.
   *
   * @param array<string, mixed> $variables
   */
  public function applyLoadingBusyState(array &$variables): void {
    if (!isset($variables['attributes']) || !$variables['attributes'] instanceof Attribute) {
      return;
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->setAttribute('role', 'status');
    $attributes->setAttribute('aria-live', 'polite');
    $attributes->setAttribute('aria-busy', 'true');
  }

  /**
   * Export / privacy notice semantics (non-blocking).
   *
   * @param array<string, mixed> $variables
   */
  public function applyExportNoticeSemantics(array &$variables): void {
    if (!isset($variables['attributes']) || !$variables['attributes'] instanceof Attribute) {
      return;
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->setAttribute('role', 'status');
    $attributes->setAttribute('aria-live', 'polite');
  }

}
