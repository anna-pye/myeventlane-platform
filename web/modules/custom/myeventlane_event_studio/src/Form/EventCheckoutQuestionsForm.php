<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;

/**
 * Isolated Event Studio form for checkout question settings.
 */
final class EventCheckoutQuestionsForm extends EventStudioBaseForm {

  public function getFormId(): string {
    return 'myeventlane_event_studio_checkout_questions_form';
  }

  protected function getNextRouteName(): string {
    return 'myeventlane_event_studio.workspace_questions';
  }

  protected function getPreviousRouteName(): ?string {
    return NULL;
  }

  protected function getCurrentStepId(): string {
    return 'questions';
  }

  protected function getContinueButtonLabel() {
    return $this->t('Save checkout questions');
  }

  protected function onWizardStepSaveSuccess(NodeInterface $saved, FormStateInterface $form_state): void {
    $this->messenger()->addStatus($this->t('Checkout question settings saved.'));
    $form_state->setRedirect('myeventlane_event_studio.workspace_questions', ['node' => $saved->id()]);
  }

  protected function buildWizardStepContent(array &$form, FormStateInterface $form_state, NodeInterface $node, array $melDefaults): void {
    $form['mel']['collect_attendee_questions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Collect extra attendee details'),
      '#description' => $this->t('Gather information per guest beyond name and email. Detailed question-library editing remains service-backed and will not use nested entity widgets here.'),
      '#default_value' => !empty($melDefaults['collect_attendee_questions']),
      '#mel_option_card' => TRUE,
    ];

    $form['mel']['attendee_questions_editor'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      'items_state' => [
        '#type' => 'hidden',
        '#default_value' => is_string(($melDefaults['attendee_questions_editor']['items_state'] ?? NULL))
          ? $melDefaults['attendee_questions_editor']['items_state']
          : '[]',
      ],
    ];
  }

}
