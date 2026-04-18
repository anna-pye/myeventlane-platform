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
  protected function buildWizardStepContent(array &$form, FormStateInterface $form_state, NodeInterface $node, array $melDefaults): void {
    $form['mel']['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Published'),
      '#default_value' => !empty($melDefaults['status']),
      '#attributes' => ['class' => ['mel-checkbox-publish']],
    ];

    $form['actions']['continue']['#value'] = $this->t('Save and view event');
  }

  /**
   * {@inheritdoc}
   *
   * Final step: non-draft save so publish flag persists as in full Event Studio.
   */
  public function submitContinue(array &$form, FormStateInterface $form_state): void {
    $nid = (int) ($form_state->getValue('nid') ?? 0);
    if ($nid < 1) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $loaded = $storage->load($nid);
    if (!$loaded instanceof NodeInterface) {
      return;
    }
    $this->assertVendorEvent($loaded);

    $baseline = $this->wizardMelBaseline->getBaselineMel($loaded);
    $submitted = $form_state->getValue('mel') ?? [];
    if (!is_array($submitted)) {
      $submitted = [];
    }
    $merged = $this->mergeMel($baseline, $submitted);
    $form_state->setValue('mel', $merged);

    $payload = $this->melPayloadService->buildFromFormState($form_state, $this->entityTypeManager);
    $result = $this->saveService->save($payload, $loaded, $this->currentUser(), FALSE);

    if ($result['errors'] !== []) {
      foreach ($result['errors'] as $msg) {
        $this->messenger()->addError($msg);
      }
      return;
    }

    $saved = $result['node'];
    if (!$saved instanceof NodeInterface) {
      $this->messenger()->addError($this->t('Could not save the event.'));
      return;
    }

    $this->messenger()->addStatus($this->t('Event saved.'));
    $form_state->setRedirectUrl(Url::fromRoute('entity.node.canonical', ['node' => $saved->id()]));
  }

}
