<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Event wizard step: Publish.
 *
 * Sets published state, saves, redirects to success.
 */
final class EventWizardPublishForm extends EventWizardBaseForm {

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  /**
   * Constructs the form.
   */
  public function __construct(
    $entity_type_manager,
    $domain_detector,
    $current_user,
    RendererInterface $renderer,
    LoggerInterface $logger,
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

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event = $this->getEvent();

    $event->setPublished(TRUE);
    $event->save();

    $this->logger->notice('Event published via wizard: nid=@nid uid=@uid', [
      '@nid' => $event->id(),
      '@uid' => $this->currentUser->id(),
    ]);

    $this->messenger()->addStatus($this->t('Event "@title" has been published!', [
      '@title' => $event->label(),
    ]));

    $url = Url::fromRoute('myeventlane_event.wizard.success', ['event' => $event->id()]);
    $form_state->setRedirectUrl($url);
  }

}
