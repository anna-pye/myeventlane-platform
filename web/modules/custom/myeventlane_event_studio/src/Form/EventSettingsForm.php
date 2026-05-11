<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;

/**
 * Isolated Event Studio form for publish/settings controls.
 */
final class EventSettingsForm extends EventStudioPublishForm {

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

}
