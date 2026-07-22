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
      '#prefix' => '<section class="mel-es-field-group mel-es-field-group--booking" aria-labelledby="mel-es-booking-title"><header class="mel-es-field-group__header"><h3 class="mel-es-field-group__title" id="mel-es-booking-title">' . $this->t('Booking model') . '</h3><p class="mel-es-field-group__hint">' . $this->t('Choose the safest path for RSVPs, paid tickets, or external registration.') . '</p></header><div class="mel-es-field-group__body">',
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
      '#title' => $this->t('Ticket'),
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
      '#suffix' => '</div></section>',
    ];

    $form['mel']['rsvp_donation_guidance'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-rsvp-donation-guidance'],
        'role' => 'region',
        'aria-labelledby' => 'mel-rsvp-donation-guidance-title',
      ],
      '#states' => [
        'visible' => [
          ':input[name="mel[field_event_type]"]' => ['value' => 'rsvp'],
        ],
      ],
      'kicker' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Support free community events'),
        '#attributes' => ['class' => ['mel-rsvp-donation-guidance__kicker']],
      ],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Enable attendee donations'),
        '#attributes' => [
          'id' => 'mel-rsvp-donation-guidance-title',
          'class' => ['mel-rsvp-donation-guidance__title'],
        ],
      ],
      'intro' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Supporter donations are optional for attendees and reuse MEL’s existing RSVP donation checkout. They never block publishing.'),
        '#attributes' => ['class' => ['mel-rsvp-donation-guidance__intro']],
      ],
      'enable_donations' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Offer optional supporter donations on RSVP'),
        '#description' => $this->t('Attendees can RSVP for free and choose whether to add a contribution.'),
        '#default_value' => !empty($melDefaults['enable_donations']),
        '#parents' => ['mel', 'enable_donations'],
        '#mel_option_card' => TRUE,
      ],
      'donation_amount' => [
        '#type' => 'number',
        '#title' => $this->t('Suggested donation amount'),
        '#min' => 0,
        '#step' => 0.01,
        '#field_prefix' => '$',
        '#default_value' => $melDefaults['donation_amount'] ?? '',
        '#parents' => ['mel', 'donation_amount'],
        '#attributes' => ['class' => ['mel-input']],
        '#states' => [
          'visible' => [
            ':input[name="mel[enable_donations]"]' => ['checked' => TRUE],
          ],
        ],
      ],
      'donation_options' => [
        '#type' => 'textfield',
        '#title' => $this->t('Donation options'),
        '#description' => $this->t('Comma-separated amounts, for example 5,10,25,50.'),
        '#default_value' => $melDefaults['donation_options'] ?? '5,10,25,50',
        '#parents' => ['mel', 'donation_options'],
        '#attributes' => ['class' => ['mel-input']],
        '#states' => [
          'visible' => [
            ':input[name="mel[enable_donations]"]' => ['checked' => TRUE],
          ],
        ],
      ],
      'donation_label' => [
        '#type' => 'textfield',
        '#title' => $this->t('Donation label'),
        '#default_value' => $melDefaults['donation_label'] ?? (string) $this->t('Support this event'),
        '#parents' => ['mel', 'donation_label'],
        '#attributes' => ['class' => ['mel-input']],
        '#states' => [
          'visible' => [
            ':input[name="mel[enable_donations]"]' => ['checked' => TRUE],
          ],
        ],
      ],
      'assurance' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Recommendation only: RSVP donations are not required for publish readiness.'),
        '#attributes' => ['class' => ['mel-rsvp-donation-guidance__assurance']],
      ],
    ];
  }

}
