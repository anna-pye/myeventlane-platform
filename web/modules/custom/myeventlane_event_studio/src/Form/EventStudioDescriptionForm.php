<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;

/**
 * Wizard step: description, discovery, and policies.
 */
final class EventStudioDescriptionForm extends EventStudioBaseForm {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mel_event_studio_wizard_description';
  }

  /**
   * {@inheritdoc}
   */
  protected function getNextRouteName(): string {
    return 'myeventlane_event_studio.edit_preview';
  }

  /**
   * {@inheritdoc}
   */
  protected function getPreviousRouteName(): ?string {
    return 'myeventlane_event_studio.edit_tickets';
  }

  /**
   * {@inheritdoc}
   */
  protected function getCurrentStepId(): string {
    return 'description';
  }

  /**
   * {@inheritdoc}
   */
  protected function buildWizardStepContent(array &$form, FormStateInterface $form_state, NodeInterface $node, array $melDefaults): void {
    $items_state = '[]';
    if (isset($melDefaults['event_highlights']) && is_array($melDefaults['event_highlights'])) {
      $items_state = $melDefaults['event_highlights']['items_state'] ?? '[]';
    }

    $form['mel']['body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('About the event'),
      '#default_value' => $melDefaults['body'] ?? '',
      '#attributes' => ['class' => ['mel-input', 'mel-input--body']],
    ];

    $form['mel']['field_event_intro'] = [
      '#type' => 'textarea',
      '#title' => $this->t('What to expect'),
      '#default_value' => $melDefaults['field_event_intro'] ?? '',
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['event_highlights'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-section--card', 'mel-event-highlights-editor']],
      'items_state' => [
        '#type' => 'hidden',
        '#default_value' => is_string($items_state) ? $items_state : '[]',
        '#attributes' => [
          'id' => 'mel-event-highlights-json',
          'data-mel-highlights-state' => '1',
        ],
      ],
    ];

    $form['mel']['field_tags'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Tags'),
      '#target_type' => 'taxonomy_term',
      '#tags' => TRUE,
      '#selection_handler' => 'default',
      '#selection_settings' => [
        'target_bundles' => ['tags' => 'tags'],
      ],
      '#default_value' => $melDefaults['field_tags'] ?? [],
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['field_sales_start'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Ticket sales start'),
      '#default_value' => $melDefaults['field_sales_start'] ?? NULL,
      '#date_increment' => 15,
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['field_sales_end'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Ticket sales end'),
      '#default_value' => $melDefaults['field_sales_end'] ?? NULL,
      '#date_increment' => 15,
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['field_contact_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Contact email'),
      '#default_value' => $melDefaults['field_contact_email'] ?? '',
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['field_contact_phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Contact phone'),
      '#default_value' => $melDefaults['field_contact_phone'] ?? '',
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['field_age_policy'] = [
      '#type' => 'select',
      '#title' => $this->t('Age policy'),
      '#options' => $this->listStringFieldOptions('field_age_policy') ?: [
        'all_ages' => $this->t('All ages'),
        '18_plus' => $this->t('18+'),
        '16_plus' => $this->t('16+'),
        'under_18_with_guardian' => $this->t('Under 18 with guardian'),
        'custom' => $this->t('Custom'),
      ],
      '#default_value' => $melDefaults['field_age_policy'] ?? 'all_ages',
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['field_age_policy_note'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Age policy note'),
      '#default_value' => $melDefaults['field_age_policy_note'] ?? '',
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['field_age_restriction'] = [
      '#type' => 'select',
      '#title' => $this->t('Age suitability'),
      '#options' => $this->listStringFieldOptions('field_age_restriction'),
      '#empty_option' => $this->t('- None -'),
      '#empty_value' => '',
      '#default_value' => $melDefaults['field_age_restriction'] ?? '',
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['field_refund_policy'] = [
      '#type' => 'select',
      '#title' => $this->t('Refund policy'),
      '#options' => $this->listStringFieldOptions('field_refund_policy'),
      '#empty_option' => $this->t('- Not specified -'),
      '#empty_value' => '',
      '#default_value' => $melDefaults['field_refund_policy'] ?? '',
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['field_accessibility'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Accessibility features'),
      '#target_type' => 'taxonomy_term',
      '#tags' => TRUE,
      '#selection_handler' => 'default',
      '#selection_settings' => [
        'target_bundles' => ['accessibility' => 'accessibility'],
      ],
      '#default_value' => $melDefaults['field_accessibility'] ?? [],
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['field_accessibility_contact'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Accessibility contact'),
      '#default_value' => $melDefaults['field_accessibility_contact'] ?? '',
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['field_accessibility_directions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Accessible directions'),
      '#default_value' => $melDefaults['field_accessibility_directions'] ?? '',
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['field_accessibility_entry'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Entry and access'),
      '#default_value' => $melDefaults['field_accessibility_entry'] ?? '',
      '#attributes' => ['class' => ['mel-input']],
    ];

    $form['mel']['field_accessibility_parking'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Accessible parking'),
      '#default_value' => $melDefaults['field_accessibility_parking'] ?? '',
      '#attributes' => ['class' => ['mel-input']],
    ];
  }

}
