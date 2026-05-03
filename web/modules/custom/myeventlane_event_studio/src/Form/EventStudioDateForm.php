<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormStateInterface;
use Drupal\myeventlane_location\Service\LocationProviderManager;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Wizard step: date/time + location.
 */
final class EventStudioDateForm extends EventStudioBaseForm {

  protected ?LocationProviderManager $locationProvider = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    if ($container->has('myeventlane_location.provider_manager')) {
      $instance->locationProvider = $container->get('myeventlane_location.provider_manager');
    }
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mel_event_studio_wizard_datetime';
  }

  /**
   * {@inheritdoc}
   */
  protected function getNextRouteName(): string {
    return 'myeventlane_event_studio.edit_tickets';
  }

  /**
   * {@inheritdoc}
   */
  protected function getPreviousRouteName(): ?string {
    return 'myeventlane_event_studio.edit_basic';
  }

  /**
   * {@inheritdoc}
   */
  protected function getCurrentStepId(): string {
    return 'datetime';
  }

  /**
   * {@inheritdoc}
   */
  protected function buildWizardStepContent(array &$form, FormStateInterface $form_state, NodeInterface $node, array $melDefaults): void {
    if ($this->locationProvider instanceof LocationProviderManager) {
      $form['#attached']['drupalSettings']['myeventlaneLocation'] = $this->locationProvider->getFrontendSettings();
    }

    $venue_mode_default = 'one_off';
    if ($node->hasField('field_venue') && !$node->get('field_venue')->isEmpty()) {
      $venue_mode_default = 'saved';
    }
    $venue_default = $node->hasField('field_venue') && !$node->get('field_venue')->isEmpty()
      ? $node->get('field_venue')->entity
      : NULL;

    $location_json_default = $melDefaults['field_location'] ?? '';
    $lat_default = $melDefaults['field_location_latitude'] ?? '';
    $lng_default = $melDefaults['field_location_longitude'] ?? '';

    $form['mel']['start_date'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Start'),
      '#default_value' => $melDefaults['start_date'] ?? (
        $node->hasField('field_event_start') && !$node->get('field_event_start')->isEmpty()
          ? new DrupalDateTime($node->get('field_event_start')->value)
          : NULL
      ),
      '#date_increment' => 15,
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['end_date'] = [
      '#type' => 'datetime',
      '#title' => $this->t('End'),
      '#default_value' => $melDefaults['end_date'] ?? (
        $node->hasField('field_event_end') && !$node->get('field_event_end')->isEmpty()
          ? new DrupalDateTime($node->get('field_event_end')->value)
          : NULL
      ),
      '#date_increment' => 15,
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['venue_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Location'),
      '#mel_option_cards' => TRUE,
      '#mel_option_descriptions' => [
        'saved' => $this->t('Pick from venues under your organizer account.'),
        'create' => $this->t('Save a reusable venue while you prepare this event.'),
        'one_off' => $this->t('Use an address once without saving a venue profile.'),
      ],
      '#options' => [
        'saved' => $this->t('Use saved venue'),
        'create' => $this->t('Create new venue'),
        'one_off' => $this->t('One-off address'),
      ],
      '#default_value' => $form_state->getValue(['mel', 'venue_mode'], $melDefaults['venue_mode'] ?? $venue_mode_default),
    ];

    $form['mel']['venue_saved'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Search your venues'),
      '#target_type' => 'myeventlane_venue',
      '#default_value' => $melDefaults['venue_saved'] ?? $venue_default,
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
      '#default_value' => $location_json_default,
    ];

    $form['mel']['field_location_latitude'] = [
      '#type' => 'hidden',
      '#default_value' => $lat_default,
    ];

    $form['mel']['field_location_longitude'] = [
      '#type' => 'hidden',
      '#default_value' => $lng_default,
    ];
  }

}
