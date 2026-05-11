<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;

/**
 * Isolated Event Studio form for public event content.
 */
final class EventContentForm extends EventStudioDescriptionForm {

  public function getFormId(): string {
    return 'myeventlane_event_studio_content_form';
  }

  protected function getNextRouteName(): string {
    return 'myeventlane_event_studio.workspace_content';
  }

  protected function getPreviousRouteName(): ?string {
    return NULL;
  }

  protected function getCurrentStepId(): string {
    return 'content';
  }

  protected function getContinueButtonLabel() {
    return $this->t('Save content');
  }

  protected function onWizardStepSaveSuccess(NodeInterface $saved, FormStateInterface $form_state): void {
    $this->messenger()->addStatus($this->t('Content saved.'));
    $form_state->setRedirect('myeventlane_event_studio.workspace_content', ['node' => $saved->id()]);
  }

}
