<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * WCAG-oriented hints for state presentation (semantic HTML remains in templates).
 */
final class MelStateAccessibilityHelper {

  /**
   * Attributes for a polite status region summarising interpreted state changes.
   *
   * @return array<string, string>
   */
  public function politeStatusRegionAttributes(): array {
    return [
      'role' => 'status',
      'aria-live' => 'polite',
      'aria-relevant' => 'additions text',
    ];
  }

  /**
   * Attributes when operational risk requires assertive announcement (staff/vendor).
   *
   * @return array<string, string>
   */
  public function assertiveStatusRegionAttributes(): array {
    return [
      'role' => 'status',
      'aria-live' => 'assertive',
      'aria-relevant' => 'additions text',
    ];
  }

  /**
   * Present when animations should respect reduced motion (paired with CSS).
   *
   * @return array<string, string>
   */
  public function reducedMotionDataAttribute(): array {
    return ['data-mel-reduced-motion' => 'respect'];
  }

}
