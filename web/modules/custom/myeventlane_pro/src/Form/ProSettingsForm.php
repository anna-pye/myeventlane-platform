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
    $trialDays = max(0, (int) $this->config('commerce_recurring.commerce_billing_schedule.mel_pro_monthly')
      ->get('configuration.trial_interval.number'));

    $form['trial_days'] = [
      '#type' => 'item',
      '#title' => $this->t('Trial period'),
      '#markup' => $this->formatPlural($trialDays, '1 day', '@count days'),
      '#description' => $this->t('Controlled by the MEL Pro Commerce billing schedule so checkout and renewal use one source of truth.'),
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

    $form['commercial'] = [
      '#type' => 'details',
      '#title' => $this->t('Commercial settings'),
      '#open' => TRUE,
    ];

    $form['commercial']['grace_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Grace period days'),
      '#description' => $this->t('Days of Pro access after payment failure before suspension.'),
      '#default_value' => $config->get('grace_days') ?? 7,
      '#min' => 1,
      '#max' => 30,
    ];

    $form['commercial']['pro_boost_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Included Boost days per event'),
      '#description' => $this->t('Each eligible event receives one non-renewing Boost while the organiser has active Pro access.'),
      '#default_value' => $config->get('pro_boost_days') ?? 7,
      '#min' => 1,
      '#max' => 30,
      '#required' => TRUE,
    ];

    $form['commercial']['upgrade_cta_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show upgrade CTA to non-Pro organisers'),
      '#default_value' => $config->get('upgrade_cta_enabled') ?? TRUE,
    ];

    $form['commercial']['cancel_request_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable cancellation request flow'),
      '#description' => $this->t('Allow subscription-managed Pro users to submit cancellation requests.'),
      '#default_value' => $config->get('cancel_request_enabled') ?? TRUE,
    ];

    $form['commercial']['billing_support_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Billing support URL'),
      '#description' => $this->t('URL for billing or support contact (e.g. help centre or contact form).'),
      '#default_value' => $config->get('billing_support_url') ?? '',
    ];

    $form['commercial']['billing_portal_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Stripe Customer Billing Portal'),
      '#description' => $this->t('When enabled, vendors with a resolvable Stripe customer can open Stripe’s billing portal to update payment methods. Commerce Recurring remains the source of truth.'),
      '#default_value' => (bool) ($config->get('billing_portal_enabled') ?? TRUE),
    ];

    $form['commercial']['webhook_audit_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable webhook audit logging'),
      '#description' => $this->t('Log subscription webhook events for audit (when webhook endpoint is configured).'),
      '#default_value' => $config->get('webhook_audit_enabled') ?? FALSE,
    ];

    $form['commercial']['subscription_webhook_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Stripe subscription webhook secret'),
      '#description' => $this->t('Webhook signing secret (whsec_…) for /stripe/webhook/subscription.'),
      '#default_value' => $config->get('subscription_webhook_secret') ?? '',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $commercial = $values['commercial'] ?? [];
    $this->config('myeventlane_pro.settings')
      ->set('pro_price', (int) ($values['pro_price'] ?? 49))
      ->set('pro_variation_sku', trim((string) ($values['pro_variation_sku'] ?? '')))
      ->set('grace_days', (int) ($commercial['grace_days'] ?? 7))
      ->set('pro_boost_days', (int) ($commercial['pro_boost_days'] ?? 7))
      ->set('upgrade_cta_enabled', (bool) ($commercial['upgrade_cta_enabled'] ?? TRUE))
      ->set('cancel_request_enabled', (bool) ($commercial['cancel_request_enabled'] ?? TRUE))
      ->set('billing_support_url', trim((string) ($commercial['billing_support_url'] ?? '')))
      ->set('billing_portal_enabled', (bool) ($commercial['billing_portal_enabled'] ?? TRUE))
      ->set('webhook_audit_enabled', (bool) ($commercial['webhook_audit_enabled'] ?? FALSE))
      ->set('subscription_webhook_secret', trim((string) ($commercial['subscription_webhook_secret'] ?? '')) ?: ($this->config('myeventlane_pro.settings')->get('subscription_webhook_secret') ?? ''))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
