<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\Core\Template\Attribute;

/**
 * Central preprocess + rare render-element hooks for MELFormSystem.
 */
final class MelFormPreprocess {

  public function __construct(
    private readonly MelFormManager $formManager,
    private readonly MelFormHelper $formHelper,
    private readonly MelFormAccessibilityHelper $accessibilityHelper,
  ) {}

  /**
   * Optional path for RenderElement 'form' (#pre_render), mirrors preprocessForm.
   *
   * @param array<string, mixed> $element
   *
   * @return array<string, mixed>
   */
  public function alterRenderElementForm(array $element): array {
    if (!$this->formManager->shouldGovernForms()) {
      return $element;
    }
    $classification = $this->formManager->getClassification();
    $element['#attributes']['class'] = array_merge(
      (array) ($element['#attributes']['class'] ?? []),
      $this->formHelper->formRootClasses($classification)
    );
    $element['#attributes']['data-mel-form-classification'] = $classification->value;
    return $element;
  }

  /**
   * @param array<string, mixed> $variables
   */
  public function preprocessForm(array &$variables): void {
    if (!$this->formManager->shouldGovernForms()) {
      return;
    }
    if (!$this->normalizePreprocessAttributes($variables)) {
      return;
    }

    $classification = $this->formManager->getClassification();
    $variables['mel_form_classification'] = $classification->value;
    $variables['attributes']->addClass($this->formHelper->formRootClasses($classification));
    $variables['attributes']->setAttribute('data-mel-form-classification', $classification->value);
  }

  /**
   * @param array<string, mixed> $variables
   */
  public function preprocessFormElement(array &$variables): void {
    if (!$this->formManager->shouldGovernForms()) {
      return;
    }
    if (!$this->normalizePreprocessAttributes($variables)) {
      return;
    }

    $element = $variables['element'] ?? [];
    if (!is_array($element)) {
      return;
    }

    $classification = $this->formManager->getClassification();
    $variables['attributes']->addClass($this->formHelper->formElementWrapperClasses($element, $classification));
    $variables['mel_form_classification'] = $classification->value;
    $this->accessibilityHelper->exposeDescriptionMetadata($variables);
  }

  /**
   * @param array<string, mixed> $variables
   */
  public function preprocessFormElementLabel(array &$variables): void {
    if (!$this->formManager->shouldGovernForms()) {
      return;
    }
    if (!$this->normalizePreprocessAttributes($variables)) {
      return;
    }

    $variables['attributes']->addClass('mel-label');
    $element = $variables['element'] ?? [];
    if (is_array($element) && !empty($element['#required'])) {
      $variables['attributes']->addClass('mel-label-required');
    }
  }

  /**
   * @param array<string, mixed> $variables
   */
  public function preprocessInputSubmit(array &$variables): void {
    if (!$this->formManager->shouldGovernForms()) {
      return;
    }
    if (!$this->normalizePreprocessAttributes($variables)) {
      return;
    }

    $element = $variables['element'] ?? [];
    if (!is_array($element)) {
      return;
    }

    $variables['attributes']->addClass($this->formHelper->submitInputClasses($element));
    $variables['attributes']->setAttribute('data-mel-button-tier', $this->resolveButtonTier($element));
  }

  /**
   * @param array<string, mixed> $element
   */
  private function resolveButtonTier(array $element): string {
    $existing = $element['#attributes']['class'] ?? [];
    $flat = is_array($existing) ? implode(' ', $existing) : (string) $existing;
    if (str_contains($flat, 'button--danger') || str_contains($flat, 'button--destructive')) {
      return 'destructive';
    }
    if (str_contains($flat, 'button--primary')) {
      return 'primary';
    }
    if (str_contains($flat, 'button--secondary')) {
      return 'secondary';
    }
    return 'ghost';
  }

  /**
   * Theme preprocess often passes attributes as an array before Attribute conversion.
   *
   * @param array<string, mixed> $variables
   */
  private function normalizePreprocessAttributes(array &$variables): bool {
    if (!isset($variables['attributes'])) {
      return FALSE;
    }
    if ($variables['attributes'] instanceof Attribute) {
      return TRUE;
    }
    if (is_array($variables['attributes'])) {
      $variables['attributes'] = new Attribute($variables['attributes']);

      return TRUE;
    }

    return FALSE;
  }

}
