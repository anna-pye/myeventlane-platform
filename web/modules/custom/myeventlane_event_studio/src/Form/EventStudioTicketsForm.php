<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;

/**
 * Wizard step: booking mode and canonical ticket management link.
 */
final class EventStudioTicketsForm extends EventStudioBaseForm {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mel_event_studio_wizard_tickets';
  }

  /**
   * {@inheritdoc}
   */
  protected function getNextRouteName(): string {
    return 'myeventlane_event_studio.workspace_content';
  }

  /**
   * {@inheritdoc}
   */
  protected function getPreviousRouteName(): ?string {
    return 'myeventlane_event_studio.workspace_information';
  }

  /**
   * {@inheritdoc}
   */
  protected function getCurrentStepId(): string {
    return 'tickets';
  }

  /**
   * {@inheritdoc}
   */
  protected function buildWizardStepContent(array &$form, FormStateInterface $form_state, NodeInterface $node, array $melDefaults): void {
    $form['mel']['tickets_intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('How will people join?'),
      '#attributes' => ['class' => ['mel-tickets-intro']],
    ];

    $form['mel']['field_event_type'] = [
      '#type' => 'radios',
      '#title' => '',
      '#mel_option_cards' => TRUE,
      '#mel_option_cards_tickets_layout' => TRUE,
      '#mel_option_descriptions' => [
        'rsvp' => $this->t('Collect RSVPs without taking payment.'),
        'paid' => $this->t('Sell tickets through MyEventLane.'),
        'external' => $this->t('Send guests to Humanitix, Eventbrite, or your site.'),
      ],
      '#options' => [
        'rsvp' => $this->t('Free RSVP'),
        'paid' => $this->t('Paid tickets'),
        'external' => $this->t('External link'),
      ],
      '#default_value' => $melDefaults['field_event_type'] ?? 'rsvp',
    ];

    $form['mel']['rsvp_capacity'] = [
      '#type' => 'number',
      '#title' => $this->t('RSVP capacity'),
      '#min' => 0,
      '#default_value' => $melDefaults['rsvp_capacity'] ?? '',
      '#attributes' => ['class' => ['mel-input']],
      '#states' => [
        'visible' => [
          ':input[name="mel[field_event_type]"]' => ['value' => 'rsvp'],
        ],
      ],
    ];

    $form['mel']['field_product_target'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Ticket product'),
      '#target_type' => 'commerce_product',
      '#selection_handler' => 'default',
      '#selection_settings' => [
        'target_bundles' => ['ticket' => 'ticket'],
      ],
      '#default_value' => $melDefaults['field_product_target'] ?? NULL,
      '#attributes' => ['class' => ['mel-input']],
      '#states' => [
        'visible' => [
          ':input[name="mel[field_event_type]"]' => ['value' => 'paid'],
        ],
      ],
    ];

    $form['mel']['external_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Booking or registration URL'),
      '#default_value' => $melDefaults['external_url'] ?? '',
      '#attributes' => ['class' => ['mel-input']],
      '#states' => [
        'visible' => [
          ':input[name="mel[field_event_type]"]' => ['value' => 'external'],
        ],
      ],
    ];

    $form['mel']['collect_attendee_questions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Collect extra attendee details'),
      '#description' => $this->t('Gather information per guest (beyond name and email).'),
      '#default_value' => !empty($melDefaults['collect_attendee_questions']),
      '#mel_option_card' => TRUE,
    ];
  }

}
