<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Wizard step: publishing (live / draft).
 */
final class EventStudioPublishForm extends EventStudioBaseForm {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mel_event_studio_wizard_publish';
  }

  /**
   * {@inheritdoc}
   */
  protected function getNextRouteName(): string {
    return 'entity.node.canonical';
  }

  /**
   * {@inheritdoc}
   */
  protected function getPreviousRouteName(): ?string {
    return 'myeventlane_event_studio.edit_preview';
  }

  /**
   * {@inheritdoc}
   */
  protected function getCurrentStepId(): string {
    return 'publish';
  }

  /**
   * {@inheritdoc}
   */
  protected function getContinueButtonLabel() {
    return $this->t('Save and view event');
  }

  /**
   * {@inheritdoc}
   *
   * Final step: non-draft save so publish flag persists as in full Event Studio.
   */
  protected function isDraftWizardSave(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  protected function onWizardStepSaveSuccess(NodeInterface $saved, FormStateInterface $form_state): void {
    $this->messenger()->addStatus($this->t('Event saved.'));
    $form_state->setRedirectUrl(Url::fromRoute('entity.node.canonical', ['node' => $saved->id()]));
  }

  /**
   * {@inheritdoc}
   */
  protected function buildWizardStepContent(array &$form, FormStateInterface $form_state, NodeInterface $node, array $melDefaults): void {
    $form['mel']['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Published'),
      '#default_value' => !empty($melDefaults['status']),
      '#attributes' => ['class' => ['mel-checkbox-publish']],
    ];
  }

}
