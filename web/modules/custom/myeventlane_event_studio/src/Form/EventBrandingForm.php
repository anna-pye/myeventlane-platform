<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Component\Utility\Html;
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
    $placeholder_src = Html::escape(myeventlane_event_studio_hero_placeholder_url());

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
      '#description' => $this->t('Optional, but recommended. A clear image helps attendees recognise your event.'),
      '#prefix' => '<section class="mel-es-field-group mel-es-field-group--branding" aria-labelledby="mel-es-branding-title"><header class="mel-es-field-group__header"><h3 class="mel-es-field-group__title" id="mel-es-branding-title">' . $this->t('Branding') . '</h3><p class="mel-es-field-group__hint">' . $this->t('Shape how your event appears across MyEventLane and social sharing.') . '</p><p class="mel-es-field-group__reassurance">' . $this->t('You can change visuals later. Accessibility text helps everyone understand the image.') . '</p></header><div class="mel-es-field-group__body"><div class="mel-identity-media mel-identity-media--compact"><div class="mel-cover-preview" id="mel-cover-preview" data-mel-cover-preview><img class="mel-cover-preview__img" id="mel-cover-preview-img" src="" alt="" width="1200" height="630" loading="lazy" hidden /><div class="mel-cover-preview__empty" id="mel-cover-preview-empty"><img class="mel-cover-preview__placeholder" src="' . $placeholder_src . '" width="800" height="450" loading="lazy" decoding="async" alt="" /><span class="mel-cover-preview__empty-icon" aria-hidden="true"></span><span class="mel-cover-preview__empty-text">' . $this->t('No cover image yet — upload a hero image so attendees recognise your event.') . '</span></div></div><div class="mel-identity-media__fields mel-builder-fields mel-builder-fields--stack">',
    ];

    $form['mel']['field_event_image_alt'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Image alt text'),
      '#default_value' => $melDefaults['field_event_image_alt'] ?? '',
      '#description' => $this->t('Briefly describe the image for screen readers.'),
      '#attributes' => ['class' => ['mel-input']],
      '#suffix' => '</div></div></div></section>',
    ];

    $form['unavailable'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-studio-section__placeholder']],
      'copy' => [
        '#markup' => '<p>' . $this->t('More brand controls will appear here only when they are ready for creators. For now, image and alt text are enough for most events.') . '</p>',
      ],
    ];
  }

}
