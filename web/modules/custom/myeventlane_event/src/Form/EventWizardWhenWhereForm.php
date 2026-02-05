<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Form;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Event wizard step: When & Where (wizard_step_2: start, end, location, venue).
 */
final class EventWizardWhenWhereForm extends EventWizardBaseForm {

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
    return 'event_wizard_when_where_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $event = $this->getEvent();

    $form_display = EntityFormDisplay::collectRenderDisplay($event, 'wizard_step_2');
    $form_display->buildForm($event, $form, $form_state);

    $this->applyLocationDefaults($form);

    $form['#title'] = $this->t('Create event: When & Where');
    $form['#event'] = $event;
    $form['#step_id'] = 'when_where';

    $steps = $this->buildStepper($event, 'when_where');
    $form['#steps'] = $steps;

    $next_step = $this->getNextStep('when_where');
    $submit_label = $next_step
      ? $this->t('Continue to @step →', ['@step' => $next_step['label']])
      : $this->t('Continue →');

    $form['actions'] = [
      '#type' => 'actions',
      '#weight' => 100,
    ];
    // Set button #submit so our handler runs. FormBuilder only adds ::submitForm
    // to $form['#submit']; when the button has its own #submit (e.g. from
    // myeventlane_location form_alter), only the button's handlers run. So we
    // must put our handler on the button first; then form_alter appends and our
    // submitForm runs before the location handler.
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $submit_label,
      '#button_type' => 'primary',
      '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary']],
      '#prefix' => '<div class="mel-wizard-step-card__actions">',
      '#suffix' => '</div>',
      '#submit' => ['::submitForm'],
    ];

    $form['#prefix'] = $this->buildWizardPrefix($steps, 'when_where', (string) $form['#title']);
    $form['#suffix'] = $this->buildWizardSuffix();

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // So validation sees values: widgets submit under *_wrapper.
    $form_display = EntityFormDisplay::collectRenderDisplay($this->getEvent(), 'wizard_step_2');
    $this->normalizeFormStateForExtraction($form_display, $form_state);

    if ($this->getEvent()->hasField('field_event_start')) {
      $start = $form_state->getValue('field_event_start');
      if (empty($start) || (is_array($start) && empty($start[0]['value'] ?? ''))) {
        $form_state->setErrorByName('field_event_start', $this->t('Start date is required.'));
      }
    }

    if ($this->getEvent()->hasField('field_location')) {
      $location = $form_state->getValue('field_location');
      $has_address = FALSE;
      if (is_array($location) && isset($location[0]['address'])) {
        $addr = $location[0]['address'] ?? [];
        $line1 = $addr['address_line1'] ?? '';
        $locality = $addr['locality'] ?? '';
        $has_address = trim((string) $line1) !== '' || trim((string) $locality) !== '';
      }
      if (!$has_address) {
        $form_state->setErrorByName('field_location', $this->t('Location is required.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Unconditional notice so watchdog shows this form ran (same channel/level as basics).
    $this->logger->notice('Event wizard when_where submitForm entered: form_id=@form_id', [
      '@form_id' => $this->getFormId(),
    ]);

    $event = $this->getEvent();
    $event_id = $event->id();

    try {
      $form_display = EntityFormDisplay::collectRenderDisplay($event, 'wizard_step_2');
      $field_names = array_keys($form_display->getComponents());

      $this->copyFormValuesToEvent($event, $form, $form_state, 'wizard_step_2');

      $this->logger->notice('Event wizard when_where saved: event_id=@id, fields=@fields', [
        '@id' => $event->id(),
        '@fields' => implode(', ', $field_names),
      ]);

      $this->redirectToNextStep($form_state, 'when_where');
    }
    catch (\Throwable $e) {
      $this->logger->error('Event wizard when_where save failed: event_id=@id, message=@message', [
        '@id' => $event_id,
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Applies Australia as default country for location when field is empty.
   */
  private function applyLocationDefaults(array &$form): void {
    if (!$this->getEvent()->get('field_location')->isEmpty()) {
      return;
    }

    $addr = NULL;
    if (isset($form['field_location']['widget'][0]['address'])) {
      $addr = &$form['field_location']['widget'][0]['address'];
    }
    elseif (isset($form['field_location'][0]['address'])) {
      $addr = &$form['field_location'][0]['address'];
    }
    elseif (isset($form['field_location']['address'])) {
      $addr = &$form['field_location']['address'];
    }

    if ($addr !== NULL && is_array($addr) && ($addr['#type'] ?? '') === 'address') {
      $default = $addr['#default_value'] ?? [];
      if (empty($default['country_code'])) {
        $addr['#default_value'] = array_merge($default, ['country_code' => 'AU']);
      }
    }
  }

}
