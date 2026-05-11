<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Wizard step: publishing (live / draft).
 */
class EventStudioPublishForm extends EventStudioBaseForm {

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
    return 'myeventlane_event_studio.workspace_content';
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
    $snapshot = (bool) $form_state->get('mel_was_published_snapshot');
    $just_went_live = $saved->isPublished() && !$snapshot;
    if ($just_went_live) {
      $this->messenger()->addStatus($this->t('Your event is live'));
      $form_state->setRedirectUrl(Url::fromRoute('myeventlane_event_studio.workspace', ['node' => $saved->id()], ['query' => ['mel_celebrate' => '1']]));
      return;
    }
    $this->messenger()->addStatus($this->t('Event saved.'));
    $form_state->setRedirectUrl(Url::fromRoute('entity.node.canonical', ['node' => $saved->id()]));
  }

  /**
   * {@inheritdoc}
   */
  protected function buildWizardStepContent(array &$form, FormStateInterface $form_state, NodeInterface $node, array $melDefaults): void {
    $form_state->set('mel_was_published_snapshot', $node->isPublished());
    $published = !empty($melDefaults['status']);
    $draft_hidden = $published ? ' hidden' : '';
    $live_hidden = $published ? '' : ' hidden';
    $form['mel']['status'] = [
      '#type' => 'hidden',
      '#default_value' => $published ? '1' : '0',
      '#prefix' =>
        '<div class="mel-publish-action-card" role="region" aria-labelledby="mel-publish-action-title-wizard" data-mel-publish-card="1">' .
        '<div class="mel-publish-action-card__panel mel-publish-action-card__draft"' . $draft_hidden . ' data-mel-publish-panel="draft">' .
        '<h3 id="mel-publish-action-title-wizard" class="mel-publish-action-card__title">' . $this->t('Publish event') . '</h3>' .
        '<p class="mel-publish-action-card__desc">' . $this->t('After publishing, your public page can show RSVPs or tickets you\'ve turned on.') . '</p>' .
        '<button type="button" class="mel-btn mel-btn--primary" id="mel-publish-now">' . $this->t('Publish now') . '</button>' .
        '</div>' .
        '<div class="mel-publish-action-card__panel mel-publish-action-card__live"' . $live_hidden . ' data-mel-publish-panel="live">' .
        '<h3 class="mel-publish-action-card__title">' . $this->t('Your event is live') . '</h3>' .
        '<p class="mel-publish-action-card__desc">' . $this->t('Updates publish when you save.') . '</p>' .
        '<button type="button" class="mel-btn mel-btn--ghost" id="mel-revert-draft">' . $this->t('Unpublish') . '</button>' .
        '</div>',
      '#suffix' => '</div>',
    ];
  }

}
