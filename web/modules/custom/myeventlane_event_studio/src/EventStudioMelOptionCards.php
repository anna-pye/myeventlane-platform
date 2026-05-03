<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;

/**
 * Applies card-style wrappers to selected radios/checkboxes in Event Studio forms.
 *
 * Controlled per element via {@see \Drupal\Core\Render\Element\Radios} properties:
 * - #mel_option_cards
 * - #mel_option_descriptions (optional keyed by option value).
 * - #mel_option_cards_tickets_layout (optional radios group horizontal layout marker).
 *
 * Checkboxes pass #mel_option_card.
 */
final class EventStudioMelOptionCards {

  /**
   * Augments radios children after core {@see Radios::processRadios()} has run.
   */
  public static function processRadios(array &$element, FormStateInterface $form_state, array &$complete_form): array {
    if (empty($element['#mel_option_cards'])) {
      return $element;
    }

    /** @var array<string, mixed> $descriptions */
    $descriptions = $element['#mel_option_descriptions'] ?? [];

    foreach (Element::children($element) as $key) {
      if (($element[$key]['#type'] ?? '') !== 'radio') {
        continue;
      }

      $element[$key]['#mel_option_card'] = TRUE;
      $element[$key]['#wrapper_attributes'] ??= [];
      $element[$key]['#wrapper_attributes'] += ['class' => []];
      $element[$key]['#wrapper_attributes']['class'][] = 'mel-option-card';

      if (array_key_exists($key, $descriptions) && $descriptions[$key] !== NULL && $descriptions[$key] !== '') {
        $element[$key]['#description'] = $descriptions[$key];
        $element[$key]['#description_display'] = 'after';
      }
    }

    return $element;
  }

  /**
   * Adds a card wrapper class to checkboxes that opt in.
   */
  public static function processCheckbox(array &$element, FormStateInterface $form_state, array &$complete_form): array {
    if (empty($element['#mel_option_card'])) {
      return $element;
    }

    $element['#wrapper_attributes'] ??= [];
    $element['#wrapper_attributes'] += ['class' => []];
    $element['#wrapper_attributes']['class'][] = 'mel-option-card';

    return $element;
  }

}
