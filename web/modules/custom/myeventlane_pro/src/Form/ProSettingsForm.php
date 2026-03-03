<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for MyEventLane Pro settings.
 */
final class ProSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_pro_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['myeventlane_pro.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('myeventlane_pro.settings');

    $form['trial_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Trial days'),
      '#default_value' => $config->get('trial_days') ?? 30,
      '#required' => TRUE,
      '#min' => 0,
    ];

    $form['pro_price'] = [
      '#type' => 'number',
      '#title' => $this->t('Displayed Pro price'),
      '#default_value' => $config->get('pro_price') ?? 49,
      '#required' => TRUE,
      '#min' => 0,
    ];

    $form['pro_variation_sku'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Pro subscription variation SKU'),
      '#description' => $this->t('Commerce variation SKU for the Pro subscription.'),
      '#default_value' => $config->get('pro_variation_sku') ?? '',
      '#maxlength' => 255,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('myeventlane_pro.settings')
      ->set('trial_days', (int) $form_state->getValue('trial_days'))
      ->set('pro_price', (int) $form_state->getValue('pro_price'))
      ->set('pro_variation_sku', trim((string) $form_state->getValue('pro_variation_sku')))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
