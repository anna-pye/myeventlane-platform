<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Form;

use Drupal\Core\Datetime\TimeZoneFormHelper;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

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
    ];

    $form['payments']['platform_fee_percent'] = [
      '#type' => 'number',
      '#title' => $this->t('Platform fee percentage'),
      '#description' => $this->t('Percentage applied to ticket subtotals (excludes donations and Boost). For example, 5 applies a 5% platform fee. Set to 0 to disable.'),
      '#default_value' => (string) ($config->get('platform_fee_percent') ?? 5),
      '#min' => 0,
      '#max' => 100,
      '#step' => 0.5,
      '#size' => 5,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $v = $form_state->getValue(['payments', 'platform_fee_percent']);
    $platform_fee_percent = is_numeric($v) ? (float) $v : 5;
    $platform_fee_percent = max(0, min(100, $platform_fee_percent));

    $this->config('myeventlane_core.settings')
      ->set('site_name', $form_state->getValue('site_name'))
      ->set('support_email', $form_state->getValue('support_email'))
      ->set('default_timezone', $form_state->getValue('default_timezone'))
      ->set('default_currency', $form_state->getValue('default_currency'))
      ->set('platform_fee_percent', $platform_fee_percent)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
