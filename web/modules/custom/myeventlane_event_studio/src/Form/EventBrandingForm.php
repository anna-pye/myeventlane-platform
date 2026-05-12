<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;

/**
 * Isolated Event Studio form for event branding.
 */
final class EventBrandingForm extends EventStudioBaseForm {

  public function getFormId(): string {
    return 'myeventlane_event_studio_branding_form';
  }

  protected function getNextRouteName(): string {
    return 'myeventlane_event_studio.workspace_branding';
  }

  protected function getPreviousRouteName(): ?string {
    return NULL;
  }

  protected function getCurrentStepId(): string {
    return 'branding';
  }

  protected function getContinueButtonLabel() {
    return $this->t('Save branding');
  }

  protected function onWizardStepSaveSuccess(NodeInterface $saved, FormStateInterface $form_state): void {
    $this->messenger()->addStatus($this->t('Branding saved.'));
    $form_state->setRedirect('myeventlane_event_studio.workspace_branding', ['node' => $saved->id()]);
  }

  protected function buildWizardStepContent(array &$form, FormStateInterface $form_state, NodeInterface $node, array $melDefaults): void {
    $form['mel']['field_event_image'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Hero image'),
      '#upload_location' => 'public://events',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'png gif jpg jpeg webp'],
        'FileSizeLimit' => ['fileLimit' => 5 * 1024 * 1024],
      ],
      '#default_value' => $melDefaults['field_event_image'] ?? [],
      '#attributes' => ['class' => ['mel-input-file']],
      '#prefix' => '<section class="mel-es-field-group mel-es-field-group--branding" aria-labelledby="mel-es-branding-title"><header class="mel-es-field-group__header"><h3 class="mel-es-field-group__title" id="mel-es-branding-title">' . $this->t('Branding') . '</h3><p class="mel-es-field-group__hint">' . $this->t('Add a clear hero image and accessible alt text for the public event page.') . '</p></header><div class="mel-es-field-group__body">',
    ];

    $form['mel']['field_event_image_alt'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Image alt text'),
      '#default_value' => $melDefaults['field_event_image_alt'] ?? '',
      '#attributes' => ['class' => ['mel-input']],
      '#suffix' => '</div></section>',
    ];

    $form['unavailable'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-studio-section__placeholder']],
      'copy' => [
        '#markup' => '<p>' . $this->t('Additional branding controls will appear here when their event fields are configured. No parallel branding storage is created.') . '</p>',
      ],
    ];
  }

}
