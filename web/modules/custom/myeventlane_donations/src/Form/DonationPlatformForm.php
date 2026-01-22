<?php

declare(strict_types=1);

namespace Drupal\myeventlane_donations\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\myeventlane_donations\Service\PlatformDonationService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Platform donation form (vendor → MyEventLane).
 */
final class DonationPlatformForm extends FormBase {

  /**
   * Constructs a DonationPlatformForm.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\myeventlane_donations\Service\PlatformDonationService $donationService
   *   The platform donation service.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly PlatformDonationService $donationService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('myeventlane_donations.platform'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_donations_platform_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->configFactory->get('myeventlane_donations.settings');

    if (!$config->get('enable_platform_donations')) {
      $form['disabled'] = [
        '#markup' => '<p>' . $this->t('Platform donations are currently disabled.') . '</p>',
      ];
      return $form;
    }

    $form['#attributes']['class'][] = 'mel-donation-form';
    $form['#attributes']['class'][] = 'mel-donation-form--platform';

    $form['intro'] = [
      '#markup' => '<div class="mel-donation-intro">' .
      '<p class="mel-donation-intro__text">' . $this->t($config->get('platform_copy') ?? 'Support MyEventLane and help us continue building tools for event creators.') . '</p>' .
      '</div>',
    ];

    $presets = $config->get('platform_presets') ?? [10.00, 25.00, 50.00, 100.00];
    $minAmount = (float) ($config->get('min_amount') ?? 1.00);

    $form['amount'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-donation-amount']],
    ];

    $form['amount']['presets'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-donation-presets']],
    ];

    // Preset buttons.
    foreach ($presets as $preset) {
      $form['amount']['presets']['preset_' . str_replace('.', '_', (string) $preset)] = [
        '#type' => 'button',
        '#value' => '$' . number_format($preset, 2),
        '#attributes' => [
          'class' => ['mel-btn', 'mel-btn-secondary', 'mel-donation-preset'],
          'data-amount' => (string) $preset,
          'type' => 'button',
        ],
      ];
    }

    // Custom amount input.
    $form['amount']['custom'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-donation-custom']],
    ];

    $form['amount']['custom']['custom_amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Custom amount (AUD)'),
      '#description' => $this->t('Enter a custom donation amount (minimum $@min).', ['@min' => number_format($minAmount, 2)]),
      '#required' => FALSE,
      '#min' => $minAmount,
      '#step' => 0.01,
      '#default_value' => '',
      '#attributes' => [
        'class' => ['mel-donation-custom-input'],
        'placeholder' => $this->t('Enter amount'),
      ],
      '#field_prefix' => '$',
    ];

    // Hidden field to store selected amount.
    $form['selected_amount'] = [
      '#type' => 'hidden',
      '#default_value' => '',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Donate Now'),
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn-primary', 'mel-btn-lg'],
      ],
    ];

    $form['#attached']['library'][] = 'myeventlane_donations/donation-form';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->configFactory->get('myeventlane_donations.settings');
    $minAmount = (float) ($config->get('min_amount') ?? 1.00);

    $selectedAmount = $form_state->getValue('selected_amount');
    $customAmount = $form_state->getValue('custom_amount');

    // Determine final amount.
    $amount = NULL;
    if (!empty($selectedAmount) && is_numeric($selectedAmount)) {
      $amount = (float) $selectedAmount;
    }
    elseif (!empty($customAmount) && is_numeric($customAmount)) {
      $amount = (float) $customAmount;
    }

    if ($amount === NULL || $amount < $minAmount) {
      $form_state->setError($form['amount'], $this->t('Please select a preset amount or enter a custom amount of at least $@min.', [
        '@min' => number_format($minAmount, 2),
      ]));
    }

    $form_state->set('donation_amount', $amount);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $amount = $form_state->get('donation_amount');
    if ($amount > 0) {
      try {
        $userId = (int) $this->currentUser()->id();
        $order = $this->donationService->createDonationOrder($userId, $amount);
        $form_state->setRedirect('commerce_checkout.form', [
          'commerce_order' => $order->id(),
        ]);
      }
      catch (\Exception $e) {
        $this->messenger()->addError($this->t('Failed to create donation order. Please try again.'));
        $this->getLogger('myeventlane_donations')->error('Failed to create platform donation order: @message', [
          '@message' => $e->getMessage(),
        ]);
        $form_state->setRebuild(TRUE);
      }
    }
  }

}
