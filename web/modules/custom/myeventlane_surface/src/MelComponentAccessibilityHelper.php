<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\Core\Template\Attribute;

/**
 * Accessibility helpers for MELComponentSystem — additive WCAG 2.1 AA patterns.
 */
final class MelComponentAccessibilityHelper {

  /**
   * Ensures a landmark navigation region has a discernible label.
   *
   * @param array<string, mixed> $variables
   */
  public function applyNavigationLandmark(array &$variables): void {
    if (!isset($variables['attributes']) || !$variables['attributes'] instanceof Attribute) {
      return;
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    if (!$attributes->offsetExists('aria-label') && !empty($variables['aria_label'])) {
      $attributes->setAttribute('aria-label', (string) $variables['aria_label']);
    }
  }

  /**
   * Applies alert role semantics for inline feedback.
   *
   * @param array<string, mixed> $variables
   */
  public function applyAlertSemantics(array &$variables, string $variant): void {
    if (!isset($variables['attributes']) || !$variables['attributes'] instanceof Attribute) {
      return;
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    if ($variant === 'error') {
      $attributes->setAttribute('role', 'alert');
      $attributes->setAttribute('aria-live', 'assertive');
    }
    else {
      $attributes->setAttribute('role', 'status');
      $attributes->setAttribute('aria-live', 'polite');
    }
  }

  /**
   * Applies live-region semantics for loading indicators.
   *
   * @param array<string, mixed> $variables
   */
  public function applyLoadingSemantics(array &$variables): void {
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
   * Applies non-interruptive semantics for informational empty summaries.
   *
   * @param array<string, mixed> $variables
   */
  public function applyEmptyStateSemantics(array &$variables): void {
    if (!isset($variables['attributes']) || !$variables['attributes'] instanceof Attribute) {
      return;
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    if (!$attributes->offsetExists('role')) {
      $attributes->setAttribute('role', 'status');
    }
    if (!$attributes->offsetExists('aria-live')) {
      $attributes->setAttribute('aria-live', 'polite');
    }
  }

  /**
   * Relates a panel region to its heading for screen readers.
   *
   * @param array<string, mixed> $variables
   */
  public function relatePanelToHeading(array &$variables, string $heading_id): void {
    if ($heading_id === '' || !isset($variables['attributes']) || !$variables['attributes'] instanceof Attribute) {
      return;
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->setAttribute('aria-labelledby', $heading_id);
  }

}
