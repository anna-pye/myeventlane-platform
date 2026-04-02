<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\myeventlane_event\Service\MelPlatformSupportWizardFormHelper;
use Drupal\myeventlane_event\Utility\EventNodeRevisionSave;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Event wizard step: Publish.
 *
 * Sets published state, saves, redirects to success or optional MEL checkout.
 */
final class EventWizardPublishForm extends EventWizardBaseForm {

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  public function __construct(
    $entity_type_manager,
    $domain_detector,
    $current_user,
    RendererInterface $renderer,
    LoggerInterface $logger,
    private readonly MelPlatformSupportWizardFormHelper $melPlatformSupportWizardForm,
  ) {
    parent::__construct($entity_type_manager, $domain_detector, $current_user, $renderer);
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('renderer'),
      $container->get('logger.factory')->get('myeventlane_event'),
      $container->get('myeventlane_event.mel_platform_support_wizard_form'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'event_wizard_publish_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $event = $this->getEvent();

    $form['#title'] = $this->t('Publish event');
    $form['#event'] = $event;
    $form['#step_id'] = 'publish';

    $steps = $this->buildStepper($event, 'publish');
    $form['#steps'] = $steps;

    $form['message'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Click below to publish "@title" and make it visible to attendees.', [
        '@title' => $event->label(),
      ]),
      '#weight' => 0,
    ];

    $this->melPlatformSupportWizardForm->buildSection(
      $form,
      $form_state,
      $event,
      50,
      'mel-mel-support-heading-publish',
    );

    $form['actions'] = [
      '#type' => 'actions',
      '#weight' => 100,
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('✨ Publish Event'),
      '#button_type' => 'primary',
      '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary', 'mel-btn--large']],
    ];
    $form['actions']['back'] = [
      '#type' => 'link',
      '#title' => $this->t('← Back to Review'),
      '#url' => Url::fromRoute('myeventlane_event.wizard.review', ['event' => $event->id()]),
      '#attributes' => ['class' => ['mel-btn', 'mel-btn--secondary']],
      '#weight' => 1,
    ];
    $form['actions']['#prefix'] = '<div class="mel-wizard-step-card__actions">';
    $form['actions']['#suffix'] = '</div>';

    $form['#prefix'] = $this->buildWizardPrefix($steps, 'publish', (string) $form['#title']);
    $form['#suffix'] = $this->buildWizardSuffix();

    $form['#attached']['library'][] = 'core/drupal.states';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $event = $this->getEvent();
    $event_type = (string) ($event->get('field_event_type')->value ?? 'rsvp');
    $this->melPlatformSupportWizardForm->validate($form_state, $event, $event_type);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event = $this->getEvent();

    $this->melPlatformSupportWizardForm->apply($event, $form_state);

    $event->setPublished(TRUE);
    EventNodeRevisionSave::prepare($event, 'Event wizard: published.');
    $event->save();

    $this->logger->notice('Event published via wizard: nid=@nid uid=@uid', [
      '@nid' => $event->id(),
      '@uid' => $this->currentUser->id(),
    ]);

    $this->messenger()->addStatus($this->t('Event "@title" has been published!', [
      '@title' => $event->label(),
    ]));

    $fresh = $this->entityTypeManager->getStorage('node')->load($event->id());
    if ($fresh instanceof NodeInterface) {
      $event = $fresh;
    }

    $vendor_uid = (int) $event->getOwnerId();
    $checkout_url = $this->melPlatformSupportWizardForm->getPostPublishCheckoutUrl($event, $vendor_uid);
    if ($checkout_url !== NULL) {
      $this->messenger()->addStatus($this->t('Next, complete your optional contribution to MyEventLane (separate from ticket cart and attendee checkout).'));
      $form_state->setRedirectUrl($checkout_url);
      return;
    }

    $form_state->setRedirectUrl(Url::fromRoute('myeventlane_event.wizard.success', ['event' => $event->id()]));
  }

}
