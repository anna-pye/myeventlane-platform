<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Wizard step: publishing (live / draft).
 */
class EventStudioPublishForm extends EventStudioBaseForm {

  private ?DomainDetector $domainDetector = NULL;

  public static function create(ContainerInterface $container): static {
    $form = parent::create($container);
    $form->domainDetector = $container->get('myeventlane_core.domain_detector');
    return $form;
  }

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

    $canonical_path = Url::fromRoute('entity.node.canonical', ['node' => $saved->id()])->toString();
    $target = $this->domainDetector?->publicUrl($canonical_path) ?? $canonical_path;

    if (str_starts_with($target, 'http://') || str_starts_with($target, 'https://')) {
      $form_state->setResponse(new TrustedRedirectResponse($target, 302));
    }
    else {
      $form_state->setRedirectUrl(Url::fromRoute('entity.node.canonical', ['node' => $saved->id()]));
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function buildWizardStepContent(array &$form, FormStateInterface $form_state, NodeInterface $node, array $melDefaults): void {
    $form_state->set('mel_was_published_snapshot', $node->isPublished());
    $published = !empty($melDefaults['status']);
    $form['mel']['publish_action_card'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-publish-action-card'],
        'role' => 'region',
        'aria-labelledby' => 'mel-publish-action-title-wizard',
        'data-mel-publish-card' => '1',
      ],
      'draft' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-publish-action-card__panel', 'mel-publish-action-card__draft'],
          'data-mel-publish-panel' => 'draft',
        ] + ($published ? ['hidden' => 'hidden'] : []),
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Publish event'),
          '#attributes' => [
            'id' => 'mel-publish-action-title-wizard',
            'class' => ['mel-publish-action-card__title'],
          ],
        ],
        'description' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('After publishing, your public page can show RSVPs or tickets you\'ve turned on.'),
          '#attributes' => ['class' => ['mel-publish-action-card__desc']],
        ],
        'publish_now' => [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => $this->t('Publish now'),
          '#attributes' => [
            'type' => 'button',
            'class' => ['mel-btn', 'mel-btn--primary'],
            'id' => 'mel-publish-now',
            'data-mel-card-publish-action' => '',
          ],
        ],
      ],
      'live' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-publish-action-card__panel', 'mel-publish-action-card__live'],
          'data-mel-publish-panel' => 'live',
        ] + ($published ? [] : ['hidden' => 'hidden']),
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Your event is live'),
          '#attributes' => ['class' => ['mel-publish-action-card__title']],
        ],
        'description' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Updates publish when you save.'),
          '#attributes' => ['class' => ['mel-publish-action-card__desc']],
        ],
        'unpublish' => [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => $this->t('Unpublish'),
          '#attributes' => [
            'type' => 'button',
            'class' => ['mel-btn', 'mel-btn--ghost'],
            'id' => 'mel-revert-draft',
            'data-mel-unpublish-action' => '',
          ],
        ],
      ],
    ];
    $form['mel']['status'] = [
      '#type' => 'hidden',
      '#default_value' => $published ? '1' : '0',
    ];
  }

}
