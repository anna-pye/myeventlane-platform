<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Form;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Event wizard step: Details (wizard_step_details).
 *
 * Policies, highlights, attendee questions, accessibility.
 */
final class EventWizardDetailsForm extends EventWizardBaseForm {

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
    return 'event_wizard_details_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $event = $this->getEvent();

    $form_display = EntityFormDisplay::collectRenderDisplay($event, 'wizard_step_details');
    $form_display->buildForm($event, $form, $form_state);

    $this->applyAccessibilityConditionalLogic($form);

    $form['#title'] = $this->t('Create event: Details');
    $form['#event'] = $event;
    $form['#step_id'] = 'details';

    $steps = $this->buildStepper($event, 'details');
    $form['#steps'] = $steps;

    $next_step = $this->getNextStep('details');
    $submit_label = $next_step
      ? $this->t('Continue to @step →', ['@step' => $next_step['label']])
      : $this->t('Continue →');

    $form['actions'] = [
      '#type' => 'actions',
      '#weight' => 100,
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $submit_label,
      '#button_type' => 'primary',
      '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary']],
      '#prefix' => '<div class="mel-wizard-step-card__actions">',
      '#suffix' => '</div>',
    ];

    $form['#prefix'] = $this->buildWizardPrefix($steps, 'details', (string) $form['#title']);
    $form['#suffix'] = $this->buildWizardSuffix();

    return $form;
  }

  /**
   * Applies #states so accessibility sub-fields appear when accessibility is filled.
   */
  private function applyAccessibilityConditionalLogic(array &$form): void {
    $accessibility_selector = ':input[name="field_accessibility[0][target_id]"]';
    $sub_fields = [
      'field_accessibility_contact',
      'field_accessibility_directions',
      'field_accessibility_entry',
      'field_accessibility_parking',
    ];
    foreach ($sub_fields as $field_name) {
      if (isset($form[$field_name])) {
        $form[$field_name]['#states'] = [
          'visible' => [$accessibility_selector => ['filled' => TRUE]],
        ];
      }
    }
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

    $form_display = EntityFormDisplay::collectRenderDisplay($event, 'wizard_step_details');
    $field_names = array_keys($form_display->getComponents());

    $this->copyFormValuesToEvent($event, $form, $form_state, 'wizard_step_details');

    $this->logger->notice('Event wizard details saved: event_id=@id, fields=@fields', [
      '@id' => $event->id(),
      '@fields' => implode(', ', $field_names),
    ]);

    $this->redirectToNextStep($form_state, 'details');
  }

}
