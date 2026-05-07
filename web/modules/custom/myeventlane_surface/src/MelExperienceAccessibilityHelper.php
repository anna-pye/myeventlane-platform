<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Accessibility hints for continuity regions (WCAG 2.1 AA oriented).
 */
final class MelExperienceAccessibilityHelper {

  /**
   * Attributes for a landmark wrapping continuity / resume messaging.
   *
   * @return array<string, string>
   */
  public function continuationLandmarkAttributes(string $label_id): array {
    return [
      'role' => 'region',
      'aria-labelledby' => $label_id,
      'data-mel-experience-continuation' => 'true',
    ];
  }

  /**
   * Polite live region for non-critical continuity updates.
   *
   * @return array<string, string>
   */
  public function politeContinuationLiveAttributes(): array {
    return [
      'role' => 'status',
      'aria-live' => 'polite',
      'aria-relevant' => 'additions text',
      'data-mel-experience-live' => 'polite',
      'data-mel-aria-live' => 'polite',
    ];
  }

  /**
   * Reduced motion pairing for continuity animations (CSS consumes).
   *
   * @return array<string, string>
   */
  public function reducedMotionDataAttribute(): array {
    return ['data-mel-reduced-motion' => 'respect'];
  }

}
