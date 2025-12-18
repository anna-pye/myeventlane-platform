<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for boost settings.
 */
final class BoostSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_boost_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['myeventlane_boost.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('myeventlane_boost.settings');

    $form['boost_options'] = [
      '#type' => 'details',
      '#title' => $this->t('Boost packages'),
      '#open' => TRUE,
      '#description' => $this->t('Configure the boost duration options available to event organisers.'),
    ];

    $form['boost_options']['default_duration'] = [
      '#type' => 'number',
      '#title' => $this->t('Default boost duration (days)'),
      '#description' => $this->t('Number of days an event remains featured after a standard boost purchase.'),
      '#default_value' => $config->get('default_duration') ?? 7,
      '#min' => 1,
      '#max' => 365,
      '#required' => TRUE,
    ];

    $form['boost_options']['max_concurrent_boosts'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum concurrent boosts'),
      '#description' => $this->t('Maximum number of events that can be boosted at once per organiser. Set to 0 for unlimited.'),
      '#default_value' => $config->get('max_concurrent_boosts') ?? 0,
      '#min' => 0,
    ];

    $form['display'] = [
      '#type' => 'details',
      '#title' => $this->t('Display settings'),
      '#open' => TRUE,
    ];

    $form['display']['badge_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Featured badge text'),
      '#description' => $this->t('Text shown on the badge for boosted events (e.g. "Featured", "Promoted").'),
      '#default_value' => $config->get('badge_text') ?? 'Featured',
      '#maxlength' => 32,
    ];

    $form['display']['show_expiry_to_vendor'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show boost expiry to organisers'),
      '#description' => $this->t('When enabled, organisers see when their boost expires on the vendor dashboard.'),
      '#default_value' => $config->get('show_expiry_to_vendor') ?? TRUE,
    ];

    $form['notifications'] = [
      '#type' => 'details',
      '#title' => $this->t('Notifications'),
      '#open' => TRUE,
    ];

    $form['notifications']['expiry_reminder_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Expiry reminder (days before)'),
      '#description' => $this->t('Send organisers an email reminder this many days before their boost expires. Set to 0 to disable.'),
      '#default_value' => $config->get('expiry_reminder_days') ?? 3,
      '#min' => 0,
      '#max' => 30,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('myeventlane_boost.settings')
      ->set('default_duration', $form_state->getValue('default_duration'))
      ->set('max_concurrent_boosts', $form_state->getValue('max_concurrent_boosts'))
      ->set('badge_text', $form_state->getValue('badge_text'))
      ->set('show_expiry_to_vendor', $form_state->getValue('show_expiry_to_vendor'))
      ->set('expiry_reminder_days', $form_state->getValue('expiry_reminder_days'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
