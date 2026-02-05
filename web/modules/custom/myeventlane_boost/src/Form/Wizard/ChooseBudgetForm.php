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
 * Step 2: Choose Boost duration and budget.
 */
final class ChooseBudgetForm extends FormBase implements ContainerInjectionInterface {

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
    return 'myeventlane_boost_choose_budget_form';
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

    $store = $this->tempStoreFactory->get('myeventlane_boost_wizard');
    $state = $store->get((string) $event_id);
    if (!is_array($state)) {
      $state = [];
    }

    $placement = $state['placement_type'] ?? $store->get('event:' . $event_id . ':placement_type');
    if (!is_string($placement) || $placement === '') {
      $placement = 'unknown';
    }

    $form['#attributes']['class'][] = 'mel-boost-wizard-step';
    $form['#title'] = $this->t('Choose Boost Budget');

    $form['description'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Set your boost duration and budget. Placement: @placement', [
        '@placement' => $placement,
      ]) . '</p>',
    ];

    $form['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Back'),
      '#url' => Url::fromRoute('myeventlane_boost.wizard.step1', ['event' => $event_id]),
      '#attributes' => [
        'class' => ['mel-button', 'mel-button--ghost'],
        'aria-label' => $this->t('Back to placement step'),
      ],
    ];

    $form['duration_days'] = [
      '#type' => 'select',
      '#title' => $this->t('Duration'),
      '#options' => [
        '7' => $this->t('7 days'),
        '14' => $this->t('14 days'),
        '30' => $this->t('30 days'),
      ],
      '#required' => TRUE,
      '#default_value' => '7',
    ];

    $form['budget_amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Budget amount'),
      '#description' => $this->t('Enter your total budget for this boost.'),
      '#required' => TRUE,
      '#min' => 0,
      '#step' => '0.01',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Next'),
      '#button_type' => 'primary',
      '#attributes' => [
        'class' => ['mel-button', 'mel-button--primary'],
        'aria-label' => $this->t('Continue to dates step'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $budget = $form_state->getValue('budget_amount');
    if ($budget === NULL || $budget === '' || !is_numeric($budget)) {
      $form_state->setErrorByName('budget_amount', $this->t('Please enter a valid budget amount.'));
      return;
    }

    if ((float) $budget < 0) {
      $form_state->setErrorByName('budget_amount', $this->t('Budget amount cannot be negative.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event_id = (int) $form_state->get('event_id');
    $duration = (string) $form_state->getValue('duration_days');
    $budget = (string) $form_state->getValue('budget_amount');

    $store = $this->tempStoreFactory->get('myeventlane_boost_wizard');
    $state = $store->get((string) $event_id);
    if (!is_array($state)) {
      $state = [];
    }

    $placement = $state['placement_type'] ?? $store->get('event:' . $event_id . ':placement_type');
    if (!is_string($placement) || $placement === '') {
      $placement = 'unknown';
    }

    $state['duration_days'] = $duration;
    $state['budget_amount'] = $budget;
    $store->set((string) $event_id, $state);

    // Backward-compatible writes (step-3 may read per-key if present).
    $store->set('event:' . $event_id . ':duration_days', $duration);
    $store->set('event:' . $event_id . ':budget_amount', $budget);

    $this->messenger()->addStatus($this->t(
      'Boost config: placement=@placement, duration=@duration days, budget=@budget',
      [
        '@placement' => $placement,
        '@duration' => $duration,
        '@budget' => $budget,
      ]
    ));

    $form_state->setRedirect('myeventlane_boost.wizard.step3', ['event' => $event_id]);
  }

}

