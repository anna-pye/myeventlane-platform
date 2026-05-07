<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Accessibility metadata for intelligence regions (WCAG 2.1 AA oriented).
 */
final class MelIntelligenceAccessibilityHelper {

  /**
   * @return array<string, string>
   */
  public function regionAttributes(): array {
    return [
      'role' => 'region',
      'aria-labelledby' => 'mel-intelligence-region-label',
      'data-mel-intelligence-region' => 'true',
    ];
  }

  /**
   * @return array<string, string>
   */
  public function politeLiveAttributes(): array {
    return [
      'aria-live' => 'polite',
      'aria-atomic' => 'false',
      'data-mel-intelligence-live' => 'polite',
    ];
  }

  /**
   * @return array<string, string>
   */
  public function reducedMotionDataAttribute(): array {
    return [
      'data-mel-reduced-motion' => 'respect',
    ];
  }

}
