<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_donations\Service\VendorEventMelSupportService;
use Drupal\node\NodeInterface;

/**
 * Shared Support MyEventLane fields for split wizard steps (Tickets, Publish).
 */
final class MelPlatformSupportWizardFormHelper {

  use StringTranslationTrait;

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly VendorEventMelSupportService $vendorEventMelSupport,
    TranslationInterface $stringTranslation,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * Builds the mel_mel_support element tree (identical on Tickets and Publish).
   */
  public function buildSection(
    array &$form,
    FormStateInterface $form_state,
    NodeInterface $event,
    int $weight = 88,
    string $heading_id = 'mel-mel-support-heading',
  ): void {
    if (!$this->moduleHandler->moduleExists('myeventlane_donations')) {
      return;
    }
    if (!$event->hasField('field_mel_sup_mode')) {
      return;
    }

    $event_type = (string) ($event->get('field_event_type')->value ?? 'rsvp');
    $paid_like = in_array($event_type, ['paid', 'both'], TRUE);

    $mode = (string) ($event->get('field_mel_sup_mode')->value ?? 'none');
    if (!$paid_like && $mode === 'percent') {
      $mode = 'none';
    }

    $amount_default = '';
    if (!$event->get('field_mel_sup_amt')->isEmpty()) {
      $amount_default = (string) $event->get('field_mel_sup_amt')->value;
    }
    $percent_default = '';
    if (!$event->get('field_mel_sup_pct')->isEmpty()) {
      $percent_default = (string) $event->get('field_mel_sup_pct')->value;
    }

    $form['mel_mel_support'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#weight' => $weight,
      '#attributes' => [
        'class' => [
          'mel-vendor-wizard__mel-support',
          'mel-mel-support',
          'mel-mel-support--callout',
        ],
        'role' => 'region',
        'aria-labelledby' => $heading_id,
      ],
    ];

    $form['mel_mel_support']['_title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' => $this->t('Support MyEventLane'),
      '#attributes' => [
        'class' => ['mel-mel-support__title'],
        'id' => $heading_id,
      ],
    ];

    $form['mel_mel_support']['_intro'] = [
      '#type' => 'markup',
      '#markup' => '<p class="mel-mel-support__intro">' . $this->t('Optional: help us keep community event tools affordable. A one-time amount is paid on MyEventLane checkout <strong>after you publish</strong>—it is not mixed into the ticket cart or attendee purchases. Revenue % pledges are stored for future billing.') . '</p>',
    ];

    $options = [
      'none' => $this->t('No thanks'),
      'onetime' => $paid_like
        ? $this->t('Add a one-time contribution now')
        : $this->t('Add a one-time contribution'),
    ];
    if ($paid_like) {
      $options['percent'] = $this->t('Pledge a percentage of ticket revenue later');
    }

    $effective_mode = $form_state->getValue(['mel_mel_support', 'mode']);
    if ($effective_mode === NULL || $effective_mode === '') {
      $effective_mode = in_array($mode, array_keys($options), TRUE) ? $mode : 'none';
    }
    if (!$paid_like && $effective_mode === 'percent') {
      $effective_mode = 'none';
    }

    $form['mel_mel_support']['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Support MyEventLane'),
      '#options' => $options,
      '#default_value' => $effective_mode,
      '#required' => TRUE,
    ];

    $form['mel_mel_support']['amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Amount (AUD)'),
      '#min' => 0.01,
      '#step' => 0.01,
      '#default_value' => $amount_default !== '' ? $amount_default : NULL,
      '#field_prefix' => '$',
      '#states' => [
        'visible' => [
          ':input[name="mel_mel_support[mode]"]' => ['value' => 'onetime'],
        ],
      ],
    ];

    $form['mel_mel_support']['percent'] = [
      '#type' => 'number',
      '#title' => $this->t('Percentage of ticket revenue'),
      '#min' => 0.01,
      '#max' => 100,
      '#step' => 0.01,
      '#default_value' => $percent_default !== '' ? $percent_default : NULL,
      '#field_suffix' => '%',
      '#description' => $this->t('This won’t be charged now. We’ll store your pledge for future MEL billing tools.'),
      '#access' => $paid_like,
      '#states' => [
        'visible' => [
          ':input[name="mel_mel_support[mode]"]' => ['value' => 'percent'],
        ],
      ],
    ];
  }

  /**
   * Validates optional MEL support fields.
   */
  public function validate(FormStateInterface $form_state, NodeInterface $event, string $event_type): void {
    if (Settings::get('mel_save_trace', FALSE)) {
      \Drupal::logger('myeventlane_event')->notice('TRACE: validate method fired: MelPlatformSupportWizardFormHelper::validate');
    }
    if (!$this->moduleHandler->moduleExists('myeventlane_donations')) {
      return;
    }
    if (!$event->hasField('field_mel_sup_mode')) {
      return;
    }

    $support = $form_state->getValue('mel_mel_support');
    if (!is_array($support)) {
      return;
    }

    $paid_like = in_array($event_type, ['paid', 'both'], TRUE);
    $mode = (string) ($support['mode'] ?? 'none');
    if (!$paid_like && $mode === 'percent') {
      $form_state->setErrorByName('mel_mel_support][mode', $this->t('Percentage pledges apply to paid ticket events only.'));
      return;
    }

    $min = (float) ($this->configFactory->get('myeventlane_donations.settings')->get('min_amount') ?? 1.0);

    if ($mode === 'onetime') {
      $raw = $support['amount'] ?? '';
      $amount = is_numeric($raw) ? (float) $raw : 0.0;
      if ($amount < $min) {
        $form_state->setErrorByName('mel_mel_support][amount', $this->t('Enter at least $@min for a one-time contribution.', [
          '@min' => number_format($min, 2),
        ]));
      }
    }

    if ($mode === 'percent') {
      $raw = $support['percent'] ?? '';
      $pct = is_numeric($raw) ? (float) $raw : 0.0;
      if ($pct <= 0 || $pct > 100) {
        $form_state->setErrorByName('mel_mel_support][percent', $this->t('Enter a percentage between 0.01 and 100.'));
      }
    }
  }

  /**
   * Persists MEL support wizard values onto the event (not on default form display).
   */
  public function apply(NodeInterface $event, FormStateInterface $form_state): void {
    if (!$this->moduleHandler->moduleExists('myeventlane_donations')) {
      return;
    }
    $this->vendorEventMelSupport->applyMelSupportFormValues($event, $form_state->getValues());
  }

  /**
   * Delegates to vendor MEL service (post-publish checkout).
   */
  public function getPostPublishCheckoutUrl(NodeInterface $event, int $vendorUid): ?Url {
    return $this->vendorEventMelSupport->getPostPublishCheckoutUrl($event, $vendorUid);
  }

}
