<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * WCAG 2.1 AA-oriented hints for operational policy presentation.
 */
final class MelPolicyAccessibilityHelper {

  /**
   * @return array<string, string>
   */
  public function politePolicyRegionAttributes(): array {
    return [
      'role' => 'status',
      'aria-live' => 'polite',
      'aria-relevant' => 'additions text',
    ];
  }

  /**
   * @return array<string, string>
   */
  public function reducedMotionDataAttribute(): array {
    return ['data-mel-reduced-motion' => 'respect'];
  }

  /**
   * @return list<string>
   */
  public function wcagNotes(): array {
    return [
      'Policy-driven suppression must not remove focus order or skip headings.',
      'Pair trust-sensitive states with visible text, not colour alone.',
      'Prefer polite live regions for reassurance; reserve assertive for blocking errors owned by Commerce.',
    ];
  }

}
