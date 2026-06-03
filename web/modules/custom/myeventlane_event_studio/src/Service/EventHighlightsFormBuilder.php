<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\node\NodeInterface;

/**
 * Shared Event Highlights editor form element for legacy and workspace Studio.
 */
final class EventHighlightsFormBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly EventHighlightHelper $eventHighlightHelper,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Builds the reusable highlights editor subtree for $form['mel']['event_highlights'].
   *
   * @return array<string, mixed>
   */
  public function buildEditorElement(string $items_state_json): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-section--card', 'mel-event-highlights-editor']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('What makes your event special?'),
        '#attributes' => ['class' => ['mel-section__title']],
      ],
      'hint' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Add up to 6 highlights that help people decide to attend.'),
        '#attributes' => ['class' => ['mel-section__hint']],
      ],
      'errors' => [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'mel-highlights-editor-errors',
          'class' => ['mel-highlights-editor__errors', 'messages', 'messages--error'],
          'role' => 'alert',
          'aria-live' => 'polite',
          'hidden' => 'hidden',
          'data-mel-highlights-errors' => '1',
          'tabindex' => '-1',
        ],
        'text' => [
          '#markup' => '<p class="mel-highlights-editor__errors-text"></p>',
        ],
      ],
      'items_state' => [
        '#type' => 'hidden',
        '#default_value' => $items_state_json,
        '#attributes' => [
          'id' => 'mel-event-highlights-json',
          'data-mel-highlights-state' => '1',
        ],
      ],
      'builder' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-highlights-builder'],
          'data-mel-highlights-builder' => '1',
        ],
        'table' => [
          '#type' => 'table',
          '#header' => [
            $this->t('Icon'),
            $this->t('Highlight'),
            $this->t('Order'),
            $this->t('Actions'),
          ],
          '#rows' => [],
          '#empty' => $this->t('No highlights yet.'),
          '#attributes' => [
            'class' => ['mel-highlights-builder-table'],
            'data-mel-highlights-table' => '1',
          ],
        ],
        'add' => [
          '#type' => 'button',
          '#value' => $this->t('Add highlight'),
          '#attributes' => [
            'class' => ['mel-btn', 'mel-btn--secondary', 'mel-btn--touch', 'button'],
            'type' => 'button',
            'id' => 'mel-add-event-highlight',
            'aria-label' => (string) $this->t('Add highlight row'),
          ],
        ],
      ],
    ];
  }

  /**
   * Resolves items_state JSON from mel defaults or an event node.
   */
  public function resolveItemsStateJson(NodeInterface $node, array $melDefaults): string {
    if (isset($melDefaults['event_highlights']) && is_array($melDefaults['event_highlights'])) {
      $items_state = $melDefaults['event_highlights']['items_state'] ?? '[]';
      if (is_string($items_state) && $items_state !== '') {
        return $items_state;
      }
    }
    return $this->eventHighlightHelper->encodeItemsStateJsonForNode($node);
  }

  /**
   * Attaches highlights JS and drupalSettings used by the builder.
   *
   * @param array<string, mixed> $form
   */
  public function attachLibraryAndSettings(array &$form): void {
    $form['#attached']['library'][] = 'myeventlane_event_studio/mel_event_studio_highlights';
    $existing = $form['#attached']['drupalSettings']['melEventStudio'] ?? [];
    if (!is_array($existing)) {
      $existing = [];
    }
    $form['#attached']['drupalSettings']['melEventStudio'] = array_merge(
      $existing,
      $this->getDrupalSettings(),
    );
  }

  /**
   * @return array<string, mixed>
   */
  public function getDrupalSettings(): array {
    return [
      'highlightIconOptions' => $this->eventHighlightHelper->getIconOptionsForJs(),
      'highlightIconPicker' => $this->eventHighlightHelper->getHighlightIconPickerItems(),
      'highlightErrors' => [
        'max' => (string) $this->t('You can add at most 6 highlights.'),
        'iconNoText' => (string) $this->t('Add text for each highlight that has an icon.'),
        'json' => (string) $this->t('Highlights data could not be read. Reset the list or reload the page.'),
      ],
    ];
  }

  /**
   * Validates submitted mel highlights; sets form errors when invalid.
   *
   * @param array<string, mixed> $mel
   */
  public function validateMelHighlights(array $mel, FormStateInterface $form_state): void {
    if (!isset($mel['event_highlights']) || !is_array($mel['event_highlights'])) {
      return;
    }
    $raw = (string) ($mel['event_highlights']['items_state'] ?? '');
    if ($raw === '') {
      return;
    }
    try {
      json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      $form_state->setErrorByName(
        'mel][event_highlights][items_state',
        $this->t('Highlights data could not be read. Please refresh and try again.'),
      );
      return;
    }
    foreach ($this->eventHighlightHelper->validateHighlightItemsStateJson($raw) as $message) {
      $form_state->setErrorByName('mel][event_highlights][items_state', $message);
      return;
    }
  }

}
