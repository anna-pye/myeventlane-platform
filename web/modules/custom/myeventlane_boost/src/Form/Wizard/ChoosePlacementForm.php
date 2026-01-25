<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Form\Wizard;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Step 1: Choose Boost placement.
 */
final class ChoosePlacementForm extends FormBase implements ContainerInjectionInterface {

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
    return 'myeventlane_boost_wizard_choose_placement_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $event = NULL): array {
    if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
      // This form must be invoked with an event route parameter.
      $form['#markup'] = $this->t('This step requires an event.');
      return $form;
    }

    // Store the event ID in form state for submit handlers.
    $form_state->set('event_id', (int) $event->id());

    $form['#attributes']['class'][] = 'mel-boost-wizard-step';
    $form['#title'] = $this->t('Choose Boost Placement');

    $form['description'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Where should your event be promoted?') . '</p>',
    ];

    $form['placement_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Placement'),
      '#options' => [
        'homepage' => $this->t('Homepage Featured'),
        'category' => $this->t('Category Highlight'),
        'search' => $this->t('Search Priority'),
      ],
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Next'),
      '#button_type' => 'primary',
    ];

    // @todo Step 2 route (/step-2) will be wired after implementing step 2.

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event_id = (int) $form_state->get('event_id');
    $selection = (string) $form_state->getValue('placement_type');

    // Persist selection for the wizard flow (Step 2+ reads this).
    if ($event_id > 0 && $selection !== '') {
      $store = $this->tempStoreFactory->get('myeventlane_boost_wizard');
      $state = $store->get((string) $event_id);
      if (!is_array($state)) {
        $state = [];
      }
      $state['placement_type'] = $selection;
      $store->set((string) $event_id, $state);

      // Backward-compatible write (older step-2 reads per-key).
      $store->set('event:' . $event_id . ':placement_type', $selection);
    }

    // Drupal 11: use Messenger API (replacement for deprecated drupal_set_message()).
    $this->messenger()->addStatus($this->t('Selected placement: @placement', [
      '@placement' => $selection,
    ]));

    // Continue to step 2.
    $form_state->setRedirect('myeventlane_boost.vendor_boost_wizard_step2', ['event' => $event_id]);
  }

}

