<?php

declare(strict_types=1);

namespace Drupal\myeventlane_wallet\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for wallet pass settings.
 */
final class WalletSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_wallet_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['myeventlane_wallet.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('myeventlane_wallet.settings');

    $form['apple'] = [
      '#type' => 'details',
      '#title' => $this->t('Apple Wallet'),
      '#open' => TRUE,
      '#description' => $this->t('Configure Apple Wallet pass generation. You need an Apple Developer account and Pass Type ID.'),
    ];

    $form['apple']['apple_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Apple Wallet passes'),
      '#description' => $this->t('Allow attendees to add tickets to Apple Wallet on iOS devices.'),
      '#default_value' => $config->get('apple_enabled') ?? FALSE,
    ];

    $form['apple']['apple_team_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Team ID'),
      '#description' => $this->t('Your Apple Developer Team ID (10 characters).'),
      '#default_value' => $config->get('apple_team_id') ?? '',
      '#maxlength' => 10,
      '#states' => [
        'visible' => [
          ':input[name="apple_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['apple']['apple_pass_type_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Pass Type ID'),
      '#description' => $this->t('Your Pass Type ID (e.g. pass.com.example.tickets).'),
      '#default_value' => $config->get('apple_pass_type_id') ?? '',
      '#states' => [
        'visible' => [
          ':input[name="apple_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['apple']['apple_organisation_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Organisation name'),
      '#description' => $this->t('Name shown on the pass (e.g. your company name).'),
      '#default_value' => $config->get('apple_organisation_name') ?? '',
      '#states' => [
        'visible' => [
          ':input[name="apple_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['google'] = [
      '#type' => 'details',
      '#title' => $this->t('Google Wallet'),
      '#open' => TRUE,
      '#description' => $this->t('Configure Google Wallet pass generation. You need a Google Cloud project with the Wallet API enabled.'),
    ];

    $form['google']['google_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Google Wallet passes'),
      '#description' => $this->t('Allow attendees to add tickets to Google Wallet on Android devices.'),
      '#default_value' => $config->get('google_enabled') ?? FALSE,
    ];

    $form['google']['google_issuer_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Issuer ID'),
      '#description' => $this->t('Your Google Wallet Issuer ID.'),
      '#default_value' => $config->get('google_issuer_id') ?? '',
      '#states' => [
        'visible' => [
          ':input[name="google_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['display'] = [
      '#type' => 'details',
      '#title' => $this->t('Display settings'),
      '#open' => TRUE,
    ];

    $form['display']['show_wallet_buttons'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show wallet buttons on confirmation page'),
      '#description' => $this->t('Display "Add to Wallet" buttons after ticket purchase.'),
      '#default_value' => $config->get('show_wallet_buttons') ?? TRUE,
    ];

    $form['display']['show_wallet_in_email'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include wallet links in confirmation emails'),
      '#description' => $this->t('Add wallet pass download links to order confirmation emails.'),
      '#default_value' => $config->get('show_wallet_in_email') ?? TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('myeventlane_wallet.settings')
      ->set('apple_enabled', $form_state->getValue('apple_enabled'))
      ->set('apple_team_id', $form_state->getValue('apple_team_id'))
      ->set('apple_pass_type_id', $form_state->getValue('apple_pass_type_id'))
      ->set('apple_organisation_name', $form_state->getValue('apple_organisation_name'))
      ->set('google_enabled', $form_state->getValue('google_enabled'))
      ->set('google_issuer_id', $form_state->getValue('google_issuer_id'))
      ->set('show_wallet_buttons', $form_state->getValue('show_wallet_buttons'))
      ->set('show_wallet_in_email', $form_state->getValue('show_wallet_in_email'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}












