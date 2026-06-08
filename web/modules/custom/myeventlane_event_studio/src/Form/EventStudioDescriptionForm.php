<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;

/**
 * Wizard step: description, discovery, and policies.
 */
class EventStudioDescriptionForm extends EventStudioBaseForm {

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
    return 'myeventlane_event_studio.workspace_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getPreviousRouteName(): ?string {
    return 'myeventlane_event_studio.workspace_tickets';
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
    $highlights_json = $this->eventHighlightsFormBuilder->resolveItemsStateJson($node, $melDefaults);

    $form['mel']['body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('About the event'),
      '#default_value' => $melDefaults['body'] ?? '',
      '#attributes' => ['class' => ['mel-input', 'mel-input--body']],
      '#prefix' => '<section class="mel-es-field-group mel-es-field-group--content" aria-labelledby="mel-es-content-title"><header class="mel-es-field-group__header"><h3 class="mel-es-field-group__title" id="mel-es-content-title">' . $this->t('Content') . '</h3><p class="mel-es-field-group__hint">' . $this->t('Shape the story guests see before they commit to attending.') . '</p></header><div class="mel-es-field-group__body">',
    ];

    $form['mel']['field_event_intro'] = [
      '#type' => 'textarea',
      '#title' => $this->t('What to expect'),
      '#default_value' => $melDefaults['field_event_intro'] ?? '',
      '#attributes' => ['class' => ['mel-input']],
      '#suffix' => '</div></section>',
    ];

    $form['mel']['event_highlights'] = $this->eventHighlightsFormBuilder->buildEditorElement($highlights_json);

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
      '#prefix' => '<section class="mel-es-field-group mel-es-field-group--accessibility" aria-labelledby="mel-es-accessibility-title"><header class="mel-es-field-group__header"><h3 class="mel-es-field-group__title" id="mel-es-accessibility-title">' . $this->t('Accessibility') . '</h3><p class="mel-es-field-group__hint">' . $this->t('Help guests understand access, entry, and support before they arrive.') . '</p></header><div class="mel-es-field-group__body">',
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
      '#suffix' => '</div></section>',
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
      '#prefix' => '<section class="mel-es-field-group mel-es-field-group--visibility" aria-labelledby="mel-es-content-visibility-title"><header class="mel-es-field-group__header"><h3 class="mel-es-field-group__title" id="mel-es-content-visibility-title">' . $this->t('Visibility') . '</h3><p class="mel-es-field-group__hint">' . $this->t('Tune discovery tags and ticket sales timing without changing checkout logic.') . '</p></header><div class="mel-es-field-group__body">',
    ];

    $form['mel']['field_social_proof_visibility'] = [
      '#type' => 'select',
      '#title' => $this->t('Social Proof Visibility'),
      '#description' => $this->t('Control whether attendee activity and popularity indicators are shown on event listings and event pages.'),
      '#options' => $this->listStringFieldOptions('field_social_proof_visibility') ?: [
        'auto' => $this->t('Auto'),
        'show' => $this->t('Show'),
        'hide' => $this->t('Hide'),
      ],
      '#default_value' => $melDefaults['field_social_proof_visibility'] ?? 'auto',
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
      '#suffix' => '</div></section>',
    ];

    $form['mel']['field_contact_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Contact email'),
      '#default_value' => $melDefaults['field_contact_email'] ?? '',
      '#attributes' => ['class' => ['mel-input']],
      '#prefix' => '<section class="mel-es-field-group mel-es-field-group--contact" aria-labelledby="mel-es-contact-title"><header class="mel-es-field-group__header"><h3 class="mel-es-field-group__title" id="mel-es-contact-title">' . $this->t('Contact') . '</h3><p class="mel-es-field-group__hint">' . $this->t('Give attendees a clear path for questions before the event.') . '</p></header><div class="mel-es-field-group__body mel-es-field-group__body--two-column">',
    ];

    $form['mel']['field_contact_phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Contact phone'),
      '#default_value' => $melDefaults['field_contact_phone'] ?? '',
      '#attributes' => ['class' => ['mel-input']],
      '#suffix' => '</div></section>',
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
      '#prefix' => '<section class="mel-es-field-group mel-es-field-group--policies" aria-labelledby="mel-es-policies-title"><header class="mel-es-field-group__header"><h3 class="mel-es-field-group__title" id="mel-es-policies-title">' . $this->t('Guest policies') . '</h3><p class="mel-es-field-group__hint">' . $this->t('Set age and refund expectations in one readable group.') . '</p></header><div class="mel-es-field-group__body">',
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
      '#suffix' => '</div></section>',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $mel = $form_state->getValue('mel');
    if (is_array($mel)) {
      $this->eventHighlightsFormBuilder->validateMelHighlights($mel, $form_state);
    }
  }

}
