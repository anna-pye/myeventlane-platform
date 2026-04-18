<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Render\Element;
use Drupal\myeventlane_event_studio\Form\EventStudioForm;
use Drupal\node\NodeInterface;

/**
 * Derives default `mel` values from the canonical Event Studio form (single source of truth).
 */
final class EventStudioWizardMelBaseline {

  public function __construct(
    private readonly FormBuilderInterface $formBuilder,
  ) {}

  /**
   * @return array<string, mixed>
   */
  public function getBaselineMel(NodeInterface $node): array {
    $form = $this->formBuilder->getForm(EventStudioForm::class, $node);
    $mel = $form['mel'] ?? [];
    if (!is_array($mel)) {
      return [];
    }

    return $this->extractDefaultsRecursive($mel);
  }

  /**
   * @param array<string, mixed> $element
   *
   * @return mixed
   */
  private function extractDefaultsRecursive(array $element): mixed {
    if (array_key_exists('#default_value', $element)) {
      return $element['#default_value'];
    }
    $children = Element::children($element);
    if ($children === []) {
      return NULL;
    }
    $out = [];
    foreach ($children as $key) {
      $child = $element[$key];
      if (!is_array($child)) {
        continue;
      }
      $out[$key] = $this->extractDefaultsRecursive($child);
    }
    return $out;
  }

}
