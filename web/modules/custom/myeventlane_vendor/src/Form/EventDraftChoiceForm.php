<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_vendor\Service\VendorEventStudioCreateService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Choice screen when Create event finds an existing resumable draft.
 *
 * Form ID must not contain the substring "vendor": vendor-theme language
 * standardisation rewrites display strings vendor→organiser and would corrupt
 * the posted form_id, blocking Continue / Start new.
 */
final class EventDraftChoiceForm extends FormBase {

  public function __construct(
    private readonly VendorEventStudioCreateService $eventStudioCreate,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_vendor.event_studio_create'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mel_event_draft_choice';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $uid = (int) $this->currentUser()->id();
    $draft = $this->eventStudioCreate->loadLatestResumableDraftForUser($uid);
    if (!$draft instanceof NodeInterface) {
      throw new EnforcedResponseException(
        new RedirectResponse(Url::fromRoute('myeventlane_event_studio.create')->toString()),
      );
    }

    // Defence: never offer another organiser's draft.
    if ((int) $draft->getOwnerId() !== $uid) {
      $this->logger('myeventlane_vendor')->error('Draft choice blocked: nid=@nid not owned by uid=@uid', [
        '@nid' => (string) $draft->id(),
        '@uid' => (string) $uid,
      ]);
      throw new EnforcedResponseException(
        new RedirectResponse(Url::fromRoute('myeventlane_event_studio.create')->toString()),
      );
    }

    $form_state->set('draft_nid', (int) $draft->id());

    $title = trim($draft->label() ?? '') !== '' ? $draft->label() : (string) $this->t('Untitled event');
    $changed = (int) $draft->getChangedTime();
    $changed_label = $changed > 0
      ? $this->dateFormatter->format($changed, 'medium')
      : (string) $this->t('Not available');

    $form['intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-draft-choice', 'mel-onboard-wrapper']],
    ];
    // h2 under console chrome h1 ("Event Studio") for a single logical outline.
    $form['intro']['acknowledge'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('You already have an unfinished event'),
      '#attributes' => ['class' => ['mel-onboard-title']],
    ];
    $form['intro']['align'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('You can continue working on it or start a different event.'),
      '#attributes' => ['class' => ['mel-onboard-description']],
    ];
    $form['intro']['assure'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Your existing draft will stay saved whichever option you choose.'),
      '#attributes' => ['class' => ['mel-draft-choice__assure']],
    ];

    $form['draft'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-draft-choice__card']],
    ];
    $form['draft']['title'] = [
      '#type' => 'item',
      '#title' => $this->t('Event'),
      '#markup' => '<strong>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>',
    ];
    $form['draft']['status'] = [
      '#type' => 'item',
      '#title' => $this->t('Status'),
      '#markup' => $this->t('Unfinished draft'),
    ];
    $form['draft']['changed'] = [
      '#type' => 'item',
      '#title' => $this->t('Last updated'),
      '#markup' => htmlspecialchars($changed_label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['continue'] = [
      '#type' => 'submit',
      '#value' => $this->t('Continue draft'),
      '#button_type' => 'primary',
      '#submit' => ['::submitContinue'],
    ];
    $form['actions']['start_new'] = [
      '#type' => 'submit',
      '#value' => $this->t('Start a new event'),
      '#submit' => ['::submitStartNew'],
    ];
    $form['actions']['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Back to my events'),
      '#url' => Url::fromRoute('myeventlane_vendor.console.events'),
      '#attributes' => ['class' => ['mel-btn', 'mel-btn-ghost']],
    ];

    return $form;
  }

  /**
   * Opens the existing Studio workspace for the resumable draft.
   */
  public function submitContinue(array &$form, FormStateInterface $form_state): void {
    $nid = (int) $form_state->get('draft_nid');
    $uid = (int) $this->currentUser()->id();
    $draft = $this->eventStudioCreate->loadLatestResumableDraftForUser($uid);
    if (!$draft instanceof NodeInterface || (int) $draft->id() !== $nid || (int) $draft->getOwnerId() !== $uid) {
      $this->messenger()->addError($this->t('That draft is no longer available. You can start a new event instead.'));
      $form_state->setRedirect('myeventlane_event_studio.create');
      return;
    }

    $form_state->setRedirectUrl($this->eventStudioCreate->studioWorkspaceUrl($nid));
  }

  /**
   * Creates a new draft and opens its Studio workspace (bypasses auto-resume).
   */
  public function submitStartNew(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $this->currentUser()->id();
    if ($uid <= 0) {
      $form_state->setRedirect('user.login');
      return;
    }

    // Idempotency for repeated submission of the same form build.
    if ($form_state->get('created_nid')) {
      $form_state->setRedirectUrl(
        $this->eventStudioCreate->studioWorkspaceUrl((int) $form_state->get('created_nid')),
      );
      return;
    }

    try {
      $node = $this->eventStudioCreate->createDraftEventForUser($uid);
    }
    catch (\Throwable $e) {
      $this->logger('myeventlane_vendor')->error('Draft choice start-new failed uid=@uid: @m', [
        '@uid' => (string) $uid,
        '@m' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('We could not start a new event. Please try again.'));
      $form_state->setRedirect('myeventlane_vendor.create_event_draft_choice');
      return;
    }

    $nid = (int) $node->id();
    $form_state->set('created_nid', $nid);
    $this->messenger()->addStatus($this->t('New event started. Your previous unfinished event is still saved.'));
    $form_state->setRedirectUrl($this->eventStudioCreate->studioWorkspaceUrl($nid));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Submit handlers are button-specific.
  }

}
