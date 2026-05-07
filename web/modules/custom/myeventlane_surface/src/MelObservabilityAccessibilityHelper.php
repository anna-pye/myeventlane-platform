<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Accessibility affordances for optional staff observability panels.
 */
final class MelObservabilityAccessibilityHelper {

  /**
   * Landmark attributes for the diagnostic region (WCAG 2.1 AA).
   *
   * @return array<string, mixed>
   */
  public function diagnosticRegionAttributes(): array {
    return [
      'role' => 'region',
      'aria-labelledby' => 'mel-observability-region-label',
      'data-mel-reduced-motion' => 'respect',
    ];
  }

  /**
   * Attributes for an inner list that may receive polite updates.
   *
   * @return array<string, mixed>
   */
  public function politeTraceListAttributes(): array {
    return [
      'aria-live' => 'polite',
      'aria-relevant' => 'additions text',
    ];
  }

}
