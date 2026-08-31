<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_event_studio\Service\EventStudioWorkspacePresentation;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Isolated Event Studio form for event information.
 */
final class EventInformationForm extends EventStudioBaseForm {

  private ?EventStudioWorkspacePresentation $workspacePresentation = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->ensureInjectedServices();
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function ensureInjectedServices(): void {
    parent::ensureInjectedServices();
    if ($this->workspacePresentation instanceof EventStudioWorkspacePresentation) {
      return;
    }
    $this->workspacePresentation = \Drupal::getContainer()->get('myeventlane_event_studio.workspace_presentation');
  }

  public function getFormId(): string {
    return 'myeventlane_event_studio_information_form';
  }

  protected function getNextRouteName(): string {
    return $this->resolveStayRouteName();
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
    $has_location = ($saved->hasField('field_venue') && !$saved->get('field_venue')->isEmpty())
      || ($saved->hasField('field_location') && !$saved->get('field_location')->isEmpty());
    $this->messenger()->addStatus($has_location
      ? $this->t('Event information and venue saved.')
      : $this->t('Event information saved.'));

    $mel = $form_state->getValue('mel');
    $created_venue = is_array($mel) && ($mel['venue_mode'] ?? '') === 'create';
    if ($created_venue && $saved->hasField('field_venue') && !$saved->get('field_venue')->isEmpty()) {
      $venue_id = (int) $saved->get('field_venue')->target_id;
      if ($venue_id > 0) {
        $return_path = Url::fromRoute('myeventlane_event_studio.workspace_venue', [
          'node' => $saved->id(),
        ], [
          'fragment' => 'mel-es-venue-location',
        ])->toString();
        $this->messenger()->addStatus($this->t('Complete your new venue profile, then return to your event.'));
        $form_state->setRedirect('myeventlane_venue.vendor_venue_edit', [
          'myeventlane_venue' => $venue_id,
        ], [
          'query' => ['destination' => $return_path],
        ]);
        return;
      }
    }

    // Schedule / Venue / Details share this form — stay on the nav section
    // the organiser opened instead of always bouncing to Details.
    $stay_route = $this->resolveStayRouteName();
    $form_state->setRedirect($stay_route, ['node' => $saved->id()], [
      'fragment' => $this->resolveStayFragment($stay_route),
    ]);
  }

  /**
   * Returns the workspace route for the current Schedule/Venue/Details alias.
   */
  private function resolveStayRouteName(): string {
    $route = (string) ($this->getRouteMatch()->getRouteName() ?? '');
    return match ($route) {
      'myeventlane_event_studio.workspace_schedule' => 'myeventlane_event_studio.workspace_schedule',
      'myeventlane_event_studio.workspace_venue' => 'myeventlane_event_studio.workspace_venue',
      'myeventlane_event_studio.workspace_details' => 'myeventlane_event_studio.workspace_details',
      default => 'myeventlane_event_studio.workspace_information',
    };
  }

  /**
   * Returns the field-group anchor for the current shared information route.
   */
  private function resolveStayFragment(string $route): string {
    return match ($route) {
      'myeventlane_event_studio.workspace_schedule' => 'mel-es-schedule',
      'myeventlane_event_studio.workspace_venue' => 'mel-es-venue-location',
      default => 'mel-es-details',
    };
  }

  protected function buildWizardStepContent(array &$form, FormStateInterface $form_state, NodeInterface $node, array $melDefaults): void {
    $form['#attached']['library'][] = 'myeventlane_event_studio/mel_event_studio_workspace_location';

    $form['mel']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Event title'),
      '#default_value' => $melDefaults['title'] ?? $node->label(),
      '#required' => TRUE,
      '#attributes' => ['class' => ['mel-input']],
      '#prefix' => '<section class="mel-es-field-group mel-es-field-group--basics" id="mel-es-details" aria-labelledby="mel-es-basics-title"><header class="mel-es-field-group__header"><h3 class="mel-es-field-group__title" id="mel-es-basics-title">' . $this->t('Basics') . '</h3><p class="mel-es-field-group__hint">' . $this->t('Give guests the core event details they need to recognise this listing.') . '</p></header><div class="mel-es-field-group__body">',
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
      '#prefix' => '<section class="mel-es-field-group mel-es-field-group--timing" id="mel-es-schedule" aria-labelledby="mel-es-timing-title"><header class="mel-es-field-group__header"><h3 class="mel-es-field-group__title" id="mel-es-timing-title">' . $this->t('Schedule') . '</h3><p class="mel-es-field-group__hint">' . $this->t('Keep date and time controls together so the public schedule is easy to review.') . '</p></header><div class="mel-es-field-group__body mel-es-field-group__body--datetime">',
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
      '#title' => $this->t('Venue/Location'),
      '#options' => [
        'saved' => $this->t('Use saved venue'),
        'create' => $this->t('Create new venue'),
        'one_off' => $this->t('One-off address'),
      ],
      '#default_value' => $melDefaults['venue_mode'] ?? 'one_off',
      '#prefix' => '<section class="mel-es-field-group mel-es-field-group--location" id="mel-es-venue-location" aria-labelledby="mel-es-venue-location-title"><header class="mel-es-field-group__header"><h3 class="mel-es-field-group__title" id="mel-es-venue-location-title">' . $this->t('Venue/Location') . '</h3><p class="mel-es-field-group__hint">' . $this->t('Choose a saved venue, create a venue, or add a one-off address for this event.') . '</p></header><div class="mel-es-field-group__body">',
    ];

    $this->ensureInjectedServices();
    $form['mel']['location_saved_summary'] = $this->workspacePresentation->buildSavedLocationSummaryRenderArray($node);
    $form['mel']['location_saved_summary']['#weight'] = -20;

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

    $form['mel']['venue_one_off_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Venue or location name'),
      '#default_value' => $melDefaults['venue_one_off_name'] ?? '',
      '#maxlength' => 255,
      '#description' => $this->t('Shown to attendees on the event page.'),
      '#attributes' => ['class' => ['mel-input']],
      '#states' => [
        'visible' => [
          ':input[name="mel[venue_mode]"]' => ['value' => 'one_off'],
        ],
        'required' => [
          ':input[name="mel[venue_mode]"]' => ['value' => 'one_off'],
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

    $form['mel']['venue_reset'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => $this->t('Reset venue'),
      '#attributes' => [
        'type' => 'button',
        'class' => ['mel-btn', 'mel-btn--secondary'],
        'data-mel-reset-venue' => '1',
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
