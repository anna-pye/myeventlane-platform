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

  /**
   * Per-request cache: building the full Event Studio form is expensive.
   *
   * @var array<string, array<string, mixed>>
   */
  private array $baselineMelByNodeRevision = [];

  public function __construct(
    private readonly FormBuilderInterface $formBuilder,
    private readonly EntityAutocompleteMelNormalizer $entityAutocompleteMelNormalizer,
  ) {}

  /**
   * @return array<string, mixed>
   */
  public function getBaselineMel(NodeInterface $node): array {
    $cacheKey = (string) $node->id() . ':' . (string) $node->getRevisionId();
    if (array_key_exists($cacheKey, $this->baselineMelByNodeRevision)) {
      return $this->baselineMelByNodeRevision[$cacheKey];
    }

    $form = $this->formBuilder->getForm(EventStudioForm::class, $node);
    $mel = $form['mel'] ?? [];
    if (!is_array($mel)) {
      $this->baselineMelByNodeRevision[$cacheKey] = [];
      return [];
    }

    $baseline = $this->extractDefaultsRecursive($mel);
    if (!is_array($baseline)) {
      $this->baselineMelByNodeRevision[$cacheKey] = [];
      return [];
    }

    $normalized = $this->normalizeBaselineMel($baseline);
    $this->baselineMelByNodeRevision[$cacheKey] = $normalized;
    return $normalized;
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

  /**
   * @param array<string, mixed> $mel
   *
   * @return array<string, mixed>
   */
  private function normalizeBaselineMel(array $mel): array {
    $single = [
      'venue_saved' => 'myeventlane_venue',
      'field_product_target' => 'commerce_product',
    ];
    foreach ($single as $key => $entity_type_id) {
      if (!array_key_exists($key, $mel)) {
        continue;
      }
      $mel[$key] = $this->entityAutocompleteMelNormalizer->normalizeSingle($mel[$key], $entity_type_id, 'mel.' . $key);
    }

    $tags = [
      'field_category' => 'taxonomy_term',
      'field_tags' => 'taxonomy_term',
      'field_accessibility' => 'taxonomy_term',
    ];
    foreach ($tags as $key => $entity_type_id) {
      if (!array_key_exists($key, $mel)) {
        continue;
      }
      $mel[$key] = $this->entityAutocompleteMelNormalizer->normalizeTags($mel[$key], $entity_type_id, 'mel.' . $key);
    }

    return $mel;
  }

}
