<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;

/**
 * Isolated Event Studio form for event information.
 */
final class EventInformationForm extends EventStudioBaseForm {

  public function getFormId(): string {
    return 'myeventlane_event_studio_information_form';
  }

  protected function getNextRouteName(): string {
    return 'myeventlane_event_studio.workspace_information';
  }

  protected function getPreviousRouteName(): ?string {
    return NULL;
  }

  protected function getCurrentStepId(): string {
    return 'information';
  }

  protected function getContinueButtonLabel() {
    return $this->t('Save information');
  }

  protected function onWizardStepSaveSuccess(NodeInterface $saved, FormStateInterface $form_state): void {
    $this->messenger()->addStatus($this->t('Event information saved.'));
    $form_state->setRedirect('myeventlane_event_studio.workspace_information', ['node' => $saved->id()]);
  }

  protected function buildWizardStepContent(array &$form, FormStateInterface $form_state, NodeInterface $node, array $melDefaults): void {
    $form['mel']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Event title'),
      '#default_value' => $melDefaults['title'] ?? $node->label(),
      '#required' => TRUE,
      '#attributes' => ['class' => ['mel-input']],
      '#prefix' => '<section class="mel-es-field-group mel-es-field-group--basics" aria-labelledby="mel-es-basics-title"><header class="mel-es-field-group__header"><h3 class="mel-es-field-group__title" id="mel-es-basics-title">' . $this->t('Basics') . '</h3><p class="mel-es-field-group__hint">' . $this->t('Give guests the core event details they need to recognise this listing.') . '</p></header><div class="mel-es-field-group__body">',
    ];

    $form['mel']['summary'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Summary'),
      '#default_value' => $melDefaults['summary'] ?? '',
      '#attributes' => ['class' => ['mel-input']],
      '#suffix' => '</div></section>',
    ];

    $form['mel']['start_date'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Start'),
      '#default_value' => $melDefaults['start_date'] ?? NULL,
      '#date_increment' => 15,
      '#attributes' => ['class' => ['mel-input']],
      '#prefix' => '<section class="mel-es-field-group mel-es-field-group--timing" aria-labelledby="mel-es-timing-title"><header class="mel-es-field-group__header"><h3 class="mel-es-field-group__title" id="mel-es-timing-title">' . $this->t('Timing') . '</h3><p class="mel-es-field-group__hint">' . $this->t('Keep date and time controls together so the public schedule is easy to review.') . '</p></header><div class="mel-es-field-group__body mel-es-field-group__body--datetime">',
    ];

    $form['mel']['end_date'] = [
      '#type' => 'datetime',
      '#title' => $this->t('End'),
      '#default_value' => $melDefaults['end_date'] ?? NULL,
      '#date_increment' => 15,
      '#attributes' => ['class' => ['mel-input']],
      '#suffix' => '</div></section>',
    ];

    $form['mel']['field_category'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Categories'),
      '#target_type' => 'taxonomy_term',
      '#tags' => TRUE,
      '#selection_handler' => 'default',
      '#selection_settings' => [
        'target_bundles' => ['categories' => 'categories'],
      ],
      '#default_value' => $melDefaults['field_category'] ?? [],
      '#attributes' => ['class' => ['mel-input']],
      '#prefix' => '<section class="mel-es-field-group mel-es-field-group--visibility" aria-labelledby="mel-es-visibility-title"><header class="mel-es-field-group__header"><h3 class="mel-es-field-group__title" id="mel-es-visibility-title">' . $this->t('Visibility') . '</h3><p class="mel-es-field-group__hint">' . $this->t('Help MEL place this event in the right discovery paths.') . '</p></header><div class="mel-es-field-group__body">',
      '#suffix' => '</div></section>',
    ];

    $form['mel']['venue_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Location'),
      '#options' => [
        'saved' => $this->t('Use saved venue'),
        'create' => $this->t('Create new venue'),
        'one_off' => $this->t('One-off address'),
      ],
      '#default_value' => $melDefaults['venue_mode'] ?? 'one_off',
      '#prefix' => '<section class="mel-es-field-group mel-es-field-group--location" aria-labelledby="mel-es-location-title"><header class="mel-es-field-group__header"><h3 class="mel-es-field-group__title" id="mel-es-location-title">' . $this->t('Location') . '</h3><p class="mel-es-field-group__hint">' . $this->t('Choose a saved venue, create a venue, or add a one-off address for this event.') . '</p></header><div class="mel-es-field-group__body">',
    ];

    $form['mel']['venue_saved'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Search your venues'),
      '#target_type' => 'myeventlane_venue',
      '#default_value' => $melDefaults['venue_saved'] ?? NULL,
      '#attributes' => ['class' => ['mel-input']],
      '#states' => [
        'visible' => [
          ':input[name="mel[venue_mode]"]' => ['value' => 'saved'],
        ],
      ],
    ];

    $form['mel']['venue_create_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Venue name'),
      '#default_value' => $melDefaults['venue_create_name'] ?? '',
      '#attributes' => ['class' => ['mel-input']],
      '#states' => [
        'visible' => [
          ':input[name="mel[venue_mode]"]' => ['value' => 'create'],
        ],
      ],
    ];

    $form['mel']['location_search'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search address'),
      '#attributes' => [
        'class' => ['mel-location-search', 'mel-input'],
        'data-mel-location' => 'true',
      ],
      '#states' => [
        'visible' => [
          'or' => [
            [':input[name="mel[venue_mode]"]' => ['value' => 'one_off']],
            [':input[name="mel[venue_mode]"]' => ['value' => 'create']],
          ],
        ],
      ],
    ];

    $form['mel']['field_location'] = [
      '#type' => 'hidden',
      '#default_value' => is_string($melDefaults['field_location'] ?? NULL) ? $melDefaults['field_location'] : '',
    ];

    $form['mel']['field_location_latitude'] = [
      '#type' => 'hidden',
      '#default_value' => $melDefaults['field_location_latitude'] ?? '',
    ];

    $form['mel']['field_location_longitude'] = [
      '#type' => 'hidden',
      '#default_value' => $melDefaults['field_location_longitude'] ?? '',
      '#suffix' => '</div></section>',
    ];
  }

}
