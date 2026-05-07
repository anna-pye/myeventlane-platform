<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Accessibility helpers for MELFormSystem — additive only (does not strip core ARIA).
 */
final class MelFormAccessibilityHelper {

  /**
   * Exposes a stable suffix for optional mel-form-help / aria hooks in Twig partials.
   *
   * Core form-element markup continues to own error association and labels.
   *
   * @param array<string, mixed> $variables
   *   Variables from preprocess_form_element.
   */
  public function exposeDescriptionMetadata(array &$variables): void {
    $element = $variables['element'] ?? [];
    if (!is_array($element)) {
      return;
    }

    $base = (string) ($element['#name'] ?? $element['#id'] ?? 'field');
    $safe = strtolower(preg_replace('/[^a-z0-9_-]+/i', '-', $base) ?: '');
    $variables['mel_form_description_suffix'] = $safe !== '' ? $safe : 'field';
  }

}
