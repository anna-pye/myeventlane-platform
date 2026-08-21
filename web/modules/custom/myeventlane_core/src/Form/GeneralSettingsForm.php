<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Form;

use Drupal\Core\Datetime\TimeZoneFormHelper;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\myeventlane_core\PlatformFeeDefaults;
use Drupal\myeventlane_core\Service\PlatformFeeSettings;

/**
 * General settings form for MyEventLane platform.
 */
final class GeneralSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_core_general_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['myeventlane_core.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('myeventlane_core.settings');

    $form['platform'] = [
      '#type' => 'details',
      '#title' => $this->t('Platform settings'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $form['platform']['site_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Platform name'),
      '#description' => $this->t('The name shown in emails and branding. Leave blank to use the site name.'),
      '#default_value' => $config->get('site_name') ?? '',
      '#maxlength' => 128,
    ];

    $form['platform']['support_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Support email'),
      '#description' => $this->t('Email address shown to attendees for support enquiries.'),
      '#default_value' => $config->get('support_email') ?? '',
    ];

    $form['defaults'] = [
      '#type' => 'details',
      '#title' => $this->t('Default settings'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $form['defaults']['default_timezone'] = [
      '#type' => 'select',
      '#title' => $this->t('Default timezone'),
      '#description' => $this->t('Timezone used for new events when not specified by the organiser.'),
      '#options' => TimeZoneFormHelper::getOptionsList(TRUE, TRUE),
      '#default_value' => $config->get('default_timezone') ?? 'Australia/Sydney',
    ];

    $form['defaults']['default_currency'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default currency'),
      '#description' => $this->t('Three-letter currency code used for new ticket products (e.g. AUD, USD, GBP).'),
      '#default_value' => $config->get('default_currency') ?? 'AUD',
      '#maxlength' => 3,
      '#size' => 5,
    ];

    $form['payments'] = [
      '#type' => 'details',
      '#title' => $this->t('Payments & fees'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $ticket_fee = PlatformFeeDefaults::normalizePercent($config->get('platform_fee_percent'));
    $extras_raw = $config->get('operational_extras_platform_fee_percent');
    $extras_default = PlatformFeeDefaults::normalizePercent($ticket_fee * PlatformFeeSettings::EXTRAS_FEE_TICKET_MULTIPLIER);

    $form['payments']['platform_fee_percent'] = [
      '#type' => 'number',
      '#title' => $this->t('Platform fee percentage (tickets)'),
      '#description' => $this->t('GST-inclusive MEL platform fee applied to ticket subtotals only (excludes donations, Boost, and operational extras). This same percentage is used for the Stripe direct-charge application fee. For example, 1.5 applies a 1.5% fee. Set to 0 to disable.'),
      '#default_value' => (string) $ticket_fee,
      '#min' => 0,
      '#max' => 100,
      '#step' => 0.5,
      '#size' => 5,
    ];

    $form['payments']['operational_extras_platform_fee_percent'] = [
      '#type' => 'number',
      '#title' => $this->t('Platform fee percentage (merch & add-ons)'),
      '#description' => $this->t('Percentage applied to operational extras subtotals in mixed checkout. Leave blank to use double the ticket fee (@default%). Does not change Stripe Connect application fees on tickets.',
        ['@default' => number_format($extras_default, 1)]),
      '#default_value' => is_numeric($extras_raw) ? (string) PlatformFeeDefaults::normalizePercent($extras_raw) : '',
      '#min' => 0,
      '#max' => 100,
      '#step' => 0.5,
      '#size' => 5,
    ];

    $form['payments']['direct_charge_fee_model'] = [
      '#type' => 'item',
      '#title' => $this->t('Direct-charge fee model'),
      '#markup' => $this->t('@rate% of the ticket subtotal, GST included, with no fixed fee. Change the ticket percentage above to adjust both the checkout fee and Stripe application fee together.', [
        '@rate' => number_format($ticket_fee, 1),
      ]),
    ];

    $form['payments']['fee_payer'] = [
      '#type' => 'select',
      '#title' => $this->t('Who pays platform fee'),
      '#description' => $this->t('Buyer: MEL platform fee is shown as an order adjustment. Organiser absorbs: no fee is added to the buyer; the fee is applied to the organiser payment.'),
      '#options' => [
        'buyer' => $this->t('Buyer'),
        'organizer_absorbs' => $this->t('Organiser absorbs'),
      ],
      '#default_value' => $config->get('fee_payer') ?? 'buyer',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $v = $form_state->getValue(['payments', 'platform_fee_percent']);
    $existing_fee = PlatformFeeDefaults::normalizePercent(
      $this->config('myeventlane_core.settings')->get('platform_fee_percent')
    );
    $platform_fee_percent = is_numeric($v)
      ? PlatformFeeDefaults::normalizePercent($v)
      : $existing_fee;

    $fee_payer = $form_state->getValue(['payments', 'fee_payer']);
    $fee_payer = in_array($fee_payer, ['buyer', 'organizer_absorbs'], TRUE) ? $fee_payer : 'buyer';

    $extras_v = $form_state->getValue(['payments', 'operational_extras_platform_fee_percent']);
    $operational_extras_platform_fee_percent = NULL;
    if ($extras_v !== '' && $extras_v !== NULL && is_numeric($extras_v)) {
      $operational_extras_platform_fee_percent = PlatformFeeDefaults::normalizePercent($extras_v);
    }

    $this->config('myeventlane_core.settings')
      ->set('site_name', $form_state->getValue(['platform', 'site_name']))
      ->set('support_email', $form_state->getValue(['platform', 'support_email']))
      ->set('default_timezone', $form_state->getValue(['defaults', 'default_timezone']))
      ->set('default_currency', $form_state->getValue(['defaults', 'default_currency']))
      ->set('platform_fee_percent', $platform_fee_percent)
      ->set('operational_extras_platform_fee_percent', $operational_extras_platform_fee_percent)
      ->set('fee_payer', $fee_payer)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
