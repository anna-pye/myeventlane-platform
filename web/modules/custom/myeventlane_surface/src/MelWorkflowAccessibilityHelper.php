<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Accessibility utilities for workflow orchestration (WCAG 2.1 AA aligned).
 */
final class MelWorkflowAccessibilityHelper {

  /**
   * Applies landmark semantics for workflow regions rendered as render arrays.
   *
   * @param array<string, mixed> $build
   */
  public function applyWorkflowRegionLandmark(array &$build, string $aria_label, ?string $heading_id = NULL): void {
    $build += ['#attributes' => []];
    $build['#attributes'] += [
      'role' => 'region',
      'aria-label' => $aria_label,
      'class' => [],
    ];
    if (!is_array($build['#attributes']['class'])) {
      $build['#attributes']['class'] = [$build['#attributes']['class']];
    }
    $build['#attributes']['class'][] = 'mel-workflow-region';
    if ($heading_id !== NULL && $heading_id !== '') {
      $build['#attributes']['aria-labelledby'] = $heading_id;
    }
  }

  /**
   * Documents workflow-specific guidance for manual QA (Twig may expose).
   */
  public function summarizeNotes(WorkflowDefinition $definition): string {
    return $definition->accessibilityNotes;
  }

}
