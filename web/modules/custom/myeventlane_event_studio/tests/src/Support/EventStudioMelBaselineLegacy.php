<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Support;

use Drupal\Core\Form\FormBuilderInterface;
use Drupal\myeventlane_event_studio\Form\EventStudioForm;
use Drupal\myeventlane_event_studio\Service\EntityAutocompleteMelNormalizer;
use Drupal\myeventlane_event_studio\Service\EventStudioMelDefaultsExtraction;
use Drupal\node\NodeInterface;

/**
 * Legacy baseline capture via full EventStudioForm (parity tests only).
 */
final class EventStudioMelBaselineLegacy {

  /**
   * @return array<string, mixed>
   */
  public static function capture(
    NodeInterface $node,
    FormBuilderInterface $formBuilder,
    EntityAutocompleteMelNormalizer $normalizer,
  ): array {
    $form = $formBuilder->getForm(EventStudioForm::class, $node);
    $mel = $form['mel'] ?? [];
    if (!is_array($mel)) {
      return [];
    }
    $baseline = EventStudioMelDefaultsExtraction::extractDefaultsRecursive($mel);
    if (!is_array($baseline)) {
      return [];
    }
    return $normalizer->normalizeValuesForForm($mel, $baseline, [
      'section' => 'baseline',
      'event_id' => (int) $node->id(),
      'uid' => NULL,
    ]);
  }

}
