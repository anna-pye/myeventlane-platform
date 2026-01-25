<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Form\Wizard;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Step 3: Choose Boost start/end dates.
 */
final class ChooseDatesForm extends FormBase implements ContainerInjectionInterface {

  /**
   * Constructs the form.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The private temp store factory.
   */
  public function __construct(
    private readonly PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('tempstore.private'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_boost_choose_dates_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $event = NULL): array {
    if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
      $form['#markup'] = $this->t('This step requires an event.');
      return $form;
    }

    $event_id = (int) $event->id();
    $form_state->set('event_id', $event_id);

    $temp_store = $this->tempStoreFactory->get('myeventlane_boost_wizard');
    $state = $temp_store->get((string) $event_id);
    if (!is_array($state)) {
      $state = [];
    }

    // Backward-compatible reads (older steps may have used per-key storage).
    if (empty($state['placement_type'])) {
      $legacy = $temp_store->get('event:' . $event_id . ':placement_type');
      if (is_string($legacy) && $legacy !== '') {
        $state['placement_type'] = $legacy;
      }
    }
    if (!isset($state['duration_days'])) {
      $legacy = $temp_store->get('event:' . $event_id . ':duration_days');
      if (is_string($legacy) && $legacy !== '') {
        $state['duration_days'] = $legacy;
      }
    }
    if (!isset($state['budget_amount'])) {
      $legacy = $temp_store->get('event:' . $event_id . ':budget_amount');
      if (is_string($legacy) && $legacy !== '') {
        $state['budget_amount'] = $legacy;
      }
    }

    $placement = isset($state['placement_type']) && is_string($state['placement_type']) && $state['placement_type'] !== ''
      ? $state['placement_type']
      : 'unknown';
    $duration = isset($state['duration_days']) ? (string) $state['duration_days'] : 'unknown';
    $budget = isset($state['budget_amount']) ? (string) $state['budget_amount'] : 'unknown';

    $form['#attributes']['class'][] = 'mel-boost-wizard-step';
    $form['#title'] = $this->t('Choose Boost Dates');

    $form['summary'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Placement: @placement · Duration: @duration days · Budget: @budget', [
        '@placement' => $placement,
        '@duration' => $duration,
        '@budget' => $budget,
      ]) . '</p>',
    ];

    $form['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Back'),
      '#url' => Url::fromRoute('myeventlane_boost.vendor_boost_wizard_step2', ['event' => $event_id]),
      '#attributes' => [
        'class' => ['button', 'button--ghost'],
      ],
    ];

    $form['start_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Start date'),
      '#required' => TRUE,
      '#default_value' => isset($state['start_date']) && is_string($state['start_date']) ? $state['start_date'] : NULL,
    ];

    $form['end_date'] = [
      '#type' => 'date',
      '#title' => $this->t('End date'),
      '#required' => TRUE,
      '#default_value' => isset($state['end_date']) && is_string($state['end_date']) ? $state['end_date'] : NULL,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Next'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $start = (string) $form_state->getValue('start_date');
    $end = (string) $form_state->getValue('end_date');

    if ($start === '' || $end === '') {
      return;
    }

    $start_ts = strtotime($start . ' 00:00:00 UTC');
    $end_ts = strtotime($end . ' 00:00:00 UTC');

    if ($start_ts === FALSE || $end_ts === FALSE) {
      $form_state->setErrorByName('start_date', $this->t('Please enter valid dates.'));
      return;
    }

    if ($end_ts < $start_ts) {
      $form_state->setErrorByName('end_date', $this->t('End date cannot be earlier than start date.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event_id = (int) $form_state->get('event_id');
    $start = (string) $form_state->getValue('start_date');
    $end = (string) $form_state->getValue('end_date');

    $temp_store = $this->tempStoreFactory->get('myeventlane_boost_wizard');
    $state = $temp_store->get((string) $event_id);
    if (!is_array($state)) {
      $state = [];
    }

    $state['start_date'] = $start;
    $state['end_date'] = $end;
    $temp_store->set((string) $event_id, $state);

    // Step 4 is not implemented yet; redirect to the intended URL stub.
    $form_state->setRedirectUrl(Url::fromUserInput('/vendor/events/' . $event_id . '/boost/wizard/step-4'));
  }

}

