<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\Component\Utility\Html;

/**
 * Canonical class hooks and modifiers for MEL-facing Form API output.
 */
final class MelFormHelper {

  /**
   * Root &lt;form&gt; classes following the shared contract.
   *
   * @return list<string>
   */
  public function formRootClasses(MelFormClassification $classification): array {
    return [
      'mel-form-system',
      'mel-form',
      'mel-form--' . $classification->value,
      $this->densityModifier($classification),
    ];
  }

  /**
   * Wrapper around each form item (form_element theme).
   *
   * @param array<string, mixed> $element
   *
   * @return list<string>
   */
  public function formElementWrapperClasses(array $element, MelFormClassification $classification): array {
    $type = (string) ($element['#type'] ?? '');
    $type_suffix = $type !== '' ? Html::cleanCssIdentifier($type, []) : 'element';
    $classes = [
      'mel-form-field',
      'mel-form-field--' . $type_suffix,
      'mel-form-field--ctx-' . $classification->value,
    ];

    if (!empty($element['#required'])) {
      $classes[] = 'mel-form-field--required';
    }

    $attrs = $element['#attributes'] ?? [];
    $disabled_attr = is_array($attrs) ? ($attrs['disabled'] ?? FALSE) : FALSE;
    if (!empty($element['#disabled']) || ($disabled_attr !== FALSE && $disabled_attr !== NULL)) {
      $classes[] = 'is-disabled';
      $classes[] = 'mel-state-disabled';
    }

    $errors = $element['#errors'] ?? [];
    if (is_array($errors) && $errors !== []) {
      $classes[] = 'mel-form-field--error';
      $classes[] = 'mel-state-error';
    }

    return $classes;
  }

  /**
   * Submit / button input classes (input__submit theme).
   *
   * @param array<string, mixed> $element
   *
   * @return list<string>
   */
  public function submitInputClasses(array $element): array {
    $classes = ['mel-btn'];
    $existing = $element['#attributes']['class'] ?? [];
    $flat = is_array($existing) ? implode(' ', $existing) : (string) $existing;

    if (str_contains($flat, 'button--danger') || str_contains($flat, 'button--destructive')) {
      return array_merge($classes, ['mel-btn--destructive']);
    }

    if (str_contains($flat, 'button--primary')
      || (!empty($element['#attributes']['class']) && in_array('button--primary', (array) $element['#attributes']['class'], TRUE))) {
      return array_merge($classes, ['mel-btn--primary']);
    }

    if (str_contains($flat, 'button--secondary')
      || (!empty($element['#attributes']['class']) && in_array('button--secondary', (array) $element['#attributes']['class'], TRUE))) {
      return array_merge($classes, ['mel-btn--secondary']);
    }

    // Alternate actions: low emphasis unless Commerce/module chose secondary.
    return array_merge($classes, ['mel-btn--ghost']);
  }

  private function densityModifier(MelFormClassification $classification): string {
    return match ($classification) {
      MelFormClassification::Vendor => 'mel-form--density-comfortable',
      MelFormClassification::Checkout => 'mel-form--density-trust',
      MelFormClassification::Auth => 'mel-form--density-minimal',
      default => 'mel-form--density-friendly',
    };
  }

}
