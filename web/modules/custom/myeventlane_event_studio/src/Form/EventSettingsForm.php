<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\myeventlane_event\Service\PublicEventVisibility;
use Drupal\node\NodeInterface;

/**
 * Isolated Event Studio form for publish/settings controls.
 */
class EventSettingsForm extends EventStudioPublishForm {

  public function getFormId(): string {
    return 'myeventlane_event_studio_settings_form';
  }

  protected function getNextRouteName(): string {
    return 'myeventlane_event_studio.workspace_settings';
  }

  protected function getPreviousRouteName(): ?string {
    return NULL;
  }

  protected function getCurrentStepId(): string {
    return 'settings';
  }

  protected function getContinueButtonLabel() {
    return $this->t('Save settings');
  }

  protected function onWizardStepSaveSuccess(NodeInterface $saved, FormStateInterface $form_state): void {
    $this->messenger()->addStatus($saved->isPublished() ? $this->t('Event settings saved.') : $this->t('Event saved as draft.'));
    $form_state->setRedirect('myeventlane_event_studio.workspace_settings', ['node' => $saved->id()]);
  }

  /**
   * {@inheritdoc}
   */
  protected function buildWizardStepContent(array &$form, FormStateInterface $form_state, NodeInterface $node, array $melDefaults): void {
    $form['mel']['#attributes']['class'][] = 'mel-event-settings';
    $this->buildVisibilityControls($form, $node, $melDefaults);
    parent::buildWizardStepContent($form, $form_state, $node, $melDefaults);

    $form['mel']['publish_action_card']['#attributes']['class'][] = 'mel-event-settings__publishing';
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $mel = $form_state->getValue('mel');
    if (!is_array($mel)) {
      return;
    }

    $visibility = trim((string) ($mel['field_event_visibility'] ?? ''));
    if ($visibility !== PublicEventVisibility::VISIBILITY_PASSCODE) {
      return;
    }

    $new_passcode = trim((string) ($mel['event_passcode'] ?? ''));
    if ($new_passcode !== '') {
      return;
    }

    $nid = (int) ($form_state->getValue('nid') ?? 0);
    if ($nid > 0) {
      $loaded = $this->entityTypeManager->getStorage('node')->load($nid);
      if ($loaded instanceof NodeInterface
        && $loaded->bundle() === 'event'
        && $loaded->hasField('field_event_passcode_hash')
        && !$loaded->get('field_event_passcode_hash')->isEmpty()
        && (string) $loaded->get('field_event_passcode_hash')->value !== '') {
        return;
      }
    }

    $form_state->setErrorByName(
      'mel][event_passcode',
      $this->t('A passcode is required when visibility is set to passcode protected.'),
    );
  }

  /**
   * Builds the visibility radio selector and passcode input.
   *
   * Shared by Settings and Launch Centre (visibility-only) forms.
   *
   * @param array<string, mixed> $melDefaults
   */
  protected function buildVisibilityControls(array &$form, NodeInterface $node, array $melDefaults): void {
    $current_visibility = '';
    if ($node->hasField('field_event_visibility') && !$node->get('field_event_visibility')->isEmpty()) {
      $current_visibility = (string) $node->get('field_event_visibility')->value;
    }
    if ($current_visibility === '') {
      $current_visibility = (string) ($melDefaults['field_event_visibility'] ?? PublicEventVisibility::VISIBILITY_PUBLIC);
    }

    $has_passcode = $node->hasField('field_event_passcode_hash')
      && !$node->get('field_event_passcode_hash')->isEmpty()
      && (string) $node->get('field_event_passcode_hash')->value !== '';

    $visibility_labels = [
      PublicEventVisibility::VISIBILITY_PUBLIC => $this->t('<span class="mel-visibility-option__title">Public</span> <span class="mel-visibility-option__description">Shown in MyEventLane listings, search, calendars and search engines.</span>'),
      PublicEventVisibility::VISIBILITY_UNLISTED => $this->t('<span class="mel-visibility-option__title">Unlisted</span> <span class="mel-visibility-option__description">Anyone with the direct link can view it, but it stays out of discovery and search engines.</span>'),
      PublicEventVisibility::VISIBILITY_PRIVATE => $this->t('<span class="mel-visibility-option__title">Private</span> <span class="mel-visibility-option__description">Only you, your event team, administrators and staff can view or book.</span>'),
      PublicEventVisibility::VISIBILITY_PASSCODE => $this->t('<span class="mel-visibility-option__title">Passcode protected</span> <span class="mel-visibility-option__description">Direct visitors must enter your passcode before they can view or book.</span>'),
    ];
    $visibility_names = [
      PublicEventVisibility::VISIBILITY_PUBLIC => $this->t('Public'),
      PublicEventVisibility::VISIBILITY_UNLISTED => $this->t('Unlisted'),
      PublicEventVisibility::VISIBILITY_PRIVATE => $this->t('Private'),
      PublicEventVisibility::VISIBILITY_PASSCODE => $this->t('Passcode protected'),
    ];

    $form['mel']['visibility_section'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-visibility-section'],
        'role' => 'region',
        'aria-labelledby' => 'mel-visibility-title',
      ],
      '#weight' => -10,
    ];

    $form['mel']['visibility_section']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' => $this->t('Visibility & access'),
      '#attributes' => [
        'id' => 'mel-visibility-title',
        'class' => ['mel-visibility-section__title'],
      ],
    ];

    $current_label = $visibility_names[$current_visibility] ?? $this->t('Public');
    $form['mel']['visibility_section']['current_state'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $this->t('Current: @label', ['@label' => $current_label]),
      '#attributes' => ['class' => ['mel-visibility-section__current']],
    ];

    $form['mel']['visibility_section']['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Choose how people find and access this event. You can change this at any time.'),
      '#attributes' => ['class' => ['mel-visibility-section__intro']],
    ];

    $form['mel']['field_event_visibility'] = [
      '#type' => 'radios',
      '#title' => $this->t('Who can see this event?'),
      '#options' => $visibility_labels,
      '#default_value' => $current_visibility,
      '#required' => TRUE,
      '#attributes' => ['class' => ['mel-visibility-radios']],
      '#weight' => -9,
    ];

    $passcode_description = $has_passcode
      ? (string) $this->t('Leave blank to keep the existing passcode, or enter a new passcode to replace it.')
      : (string) $this->t('Enter a passcode that visitors must provide to access this event.');

    $form['mel']['event_passcode'] = [
      '#type' => 'password',
      '#title' => $this->t('Set the event passcode'),
      '#description' => $passcode_description,
      '#attributes' => [
        'class' => ['mel-input', 'mel-visibility-passcode-input'],
        'autocomplete' => 'new-password',
      ],
      '#weight' => -7,
      '#states' => [
        'visible' => [
          ':input[name="mel[field_event_visibility]"]' => ['value' => PublicEventVisibility::VISIBILITY_PASSCODE],
        ],
      ],
    ];

    if ($has_passcode && $current_visibility === PublicEventVisibility::VISIBILITY_PASSCODE) {
      $form['mel']['passcode_status'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('This event currently has a passcode set.'),
        '#attributes' => ['class' => ['mel-visibility-passcode-status', 'messages', 'messages--status']],
        '#weight' => -6,
        '#states' => [
          'visible' => [
            ':input[name="mel[field_event_visibility]"]' => ['value' => PublicEventVisibility::VISIBILITY_PASSCODE],
          ],
        ],
      ];
    }
  }

}
