<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Form\Wizard;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Step 4: Preview & confirm Boost wizard values.
 */
final class PreviewConfirmForm extends FormBase implements ContainerInjectionInterface {

  /**
   * Constructs the form.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The private temp store factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    private readonly PrivateTempStoreFactory $tempStoreFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('tempstore.private'),
      $container->get('logger.channel.myeventlane_boost'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_boost_preview_confirm_form';
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

    $state = $this->loadWizardState($event_id);

    $form['#attributes']['class'][] = 'mel-boost-wizard-step';
    $form['#title'] = $this->t('Preview & Confirm');

    $form['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Back'),
      '#url' => Url::fromRoute('myeventlane_boost.wizard.step3', ['event' => $event_id]),
      '#attributes' => [
        'class' => ['mel-button', 'mel-button--ghost'],
        'aria-label' => $this->t('Back to dates step'),
      ],
    ];

    $rows = [
      [$this->t('Placement'), $this->formatValue($state['placement_type'] ?? NULL)],
      [$this->t('Budget'), $this->formatValue($state['budget_amount'] ?? NULL)],
      [$this->t('Duration'), $this->formatValue($state['duration_days'] ?? NULL)],
      [$this->t('Start date'), $this->formatValue($state['start_date'] ?? NULL)],
      [$this->t('End date'), $this->formatValue($state['end_date'] ?? NULL)],
    ];

    $form['summary'] = [
      '#type' => 'table',
      '#header' => [$this->t('Field'), $this->t('Value')],
      '#rows' => $rows,
      '#attributes' => ['class' => ['mel-boost-wizard-summary']],
      '#empty' => $this->t('No wizard data found yet.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Confirm & Submit'),
      '#button_type' => 'primary',
      '#attributes' => [
        'class' => ['mel-button', 'mel-button--primary'],
        'aria-label' => $this->t('Confirm and continue to payment'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event_id = (int) $form_state->get('event_id');
    $state = $this->loadWizardState($event_id);

    // Placeholder: log confirmation. (Does NOT modify tempstore.)
    $this->logger->notice('Boost wizard confirmation submitted (stub).', [
      'event_id' => $event_id,
      'placement_type' => $state['placement_type'] ?? NULL,
      'duration_days' => $state['duration_days'] ?? NULL,
      'budget_amount' => $state['budget_amount'] ?? NULL,
      'start_date' => $state['start_date'] ?? NULL,
      'end_date' => $state['end_date'] ?? NULL,
    ]);

    $this->messenger()->addStatus($this->t('Boost wizard confirmation received.'));

    $form_state->setRedirect('myeventlane_boost.wizard.step5', ['event' => $event_id]);
  }

  /**
   * Loads wizard state from tempstore with backward compatibility.
   *
   * @param int $event_id
   *   The event node ID.
   *
   * @return array
   *   Wizard state array.
   */
  private function loadWizardState(int $event_id): array {
    $temp_store = $this->tempStoreFactory->get('myeventlane_boost_wizard');
    $state = $temp_store->get((string) $event_id);
    if (!is_array($state)) {
      $state = [];
    }

    // Backward-compatible reads (older steps may have used per-key storage).
    $legacy_map = [
      'placement_type' => 'event:' . $event_id . ':placement_type',
      'duration_days' => 'event:' . $event_id . ':duration_days',
      'budget_amount' => 'event:' . $event_id . ':budget_amount',
      'start_date' => 'event:' . $event_id . ':start_date',
      'end_date' => 'event:' . $event_id . ':end_date',
    ];

    foreach ($legacy_map as $key => $legacy_key) {
      if (!isset($state[$key]) || $state[$key] === '' || $state[$key] === NULL) {
        $legacy = $temp_store->get($legacy_key);
        if ($legacy !== NULL && $legacy !== '') {
          $state[$key] = $legacy;
        }
      }
    }

    return $state;
  }

  /**
   * Formats a wizard value for display.
   *
   * @param mixed $value
   *   The value.
   *
   * @return string
   *   Display string.
   */
  private function formatValue(mixed $value): string {
    if ($value === NULL || $value === '') {
      return (string) $this->t('Not set yet');
    }
    return is_scalar($value) ? (string) $value : (string) $this->t('Not set yet');
  }

}

