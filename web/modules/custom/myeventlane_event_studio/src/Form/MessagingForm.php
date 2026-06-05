<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;

/**
 * Isolated Event Studio form for the messaging section (formerly "promotions").
 */
final class MessagingForm extends EventStudioBaseForm {

  public function getFormId(): string {
    return 'myeventlane_event_studio_messaging_form';
  }

  protected function getNextRouteName(): string {
    return 'myeventlane_event_studio.workspace_messaging';
  }

  protected function getPreviousRouteName(): ?string {
    return NULL;
  }

  protected function getCurrentStepId(): string {
    return 'messaging';
  }

  protected function getContinueButtonLabel() {
    return $this->t('Continue');
  }

  protected function onWizardStepSaveSuccess(NodeInterface $saved, FormStateInterface $form_state): void {
    $this->messenger()->addStatus($this->t('Saved.'));
    $form_state->setRedirect('myeventlane_event_studio.workspace_messaging', ['node' => $saved->id()]);
  }

  protected function buildWizardStepContent(array &$form, FormStateInterface $form_state, NodeInterface $node, array $melDefaults): void {
    $form['notice'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-studio-section__placeholder']],
      'copy' => [
        '#markup' => '<p>' . $this->t('After you publish, Boost and Featured help more people discover your event on MyEventLane. Set those up from the Boost section.') . '</p>'
        . '<p>' . $this->t('Attendee Messaging is separate. Use it to send essential updates—such as time changes or cancellations—to people who already booked or RSVPed.') . '</p>'
        . '<p>' . $this->t('This section is informational only. Nothing is configured here.') . '</p>',
      ],
    ];
  }

}
