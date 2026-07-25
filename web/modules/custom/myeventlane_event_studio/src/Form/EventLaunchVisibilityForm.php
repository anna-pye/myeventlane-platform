<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;

/**
 * Launch Centre visibility-only form (no publish controls).
 *
 * Hero owns publish/unpublish. This form answers "Who can find this?" only.
 * Saves as draft so EventStudioSaveService never applies mel[status].
 */
final class EventLaunchVisibilityForm extends EventSettingsForm {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_event_studio_launch_visibility_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getNextRouteName(): string {
    return 'myeventlane_event_studio.workspace_publishing';
  }

  /**
   * {@inheritdoc}
   */
  protected function getCurrentStepId(): string {
    return 'launch_visibility';
  }

  /**
   * {@inheritdoc}
   *
   * Visibility must never run the publish-form non-draft save path.
   */
  protected function isDraftWizardSave(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  protected function getContinueButtonLabel() {
    return $this->t('Save visibility');
  }

  /**
   * {@inheritdoc}
   */
  protected function onWizardStepSaveSuccess(NodeInterface $saved, FormStateInterface $form_state): void {
    $this->messenger()->addStatus($this->t('Visibility saved.'));
    $form_state->setRedirect('myeventlane_event_studio.workspace_publishing', ['node' => $saved->id()]);
  }

  /**
   * {@inheritdoc}
   *
   * Strip any publish flag before merge/save — Hero owns live state.
   */
  protected function persistWizardMel(FormStateInterface $form_state, bool $draft): ?array {
    $mel = $form_state->getValue('mel');
    if (is_array($mel)) {
      unset($mel['status']);
      $form_state->setValue('mel', $mel);
    }
    // Force draft regardless of caller — never mutate publish via this form.
    return parent::persistWizardMel($form_state, TRUE);
  }

  /**
   * {@inheritdoc}
   *
   * Visibility controls only — never the publish action card or mel[status].
   */
  protected function buildWizardStepContent(array &$form, FormStateInterface $form_state, NodeInterface $node, array $melDefaults): void {
    $this->buildVisibilityControls($form, $node, $melDefaults);
    // Launch Centre summary already labels this band — avoid a second H3.
    if (isset($form['mel']['visibility_section']['title'])) {
      $form['mel']['visibility_section']['title']['#access'] = FALSE;
    }
  }

}
