<?php

declare(strict_types=1);

namespace Drupal\myeventlane_ai\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\myeventlane_ai\Service\AiCircuitBreaker;
use Drupal\myeventlane_ai\Service\AiUsageTracker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

/**
 * Admin form for AI usage guardrails and current status.
 */
final class AiUsageGuardrailsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    private readonly AiUsageTracker $usageTracker,
    private readonly AiCircuitBreaker $circuitBreaker,
    private readonly ?object $vendorResolver,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    try {
      $vendor_resolver = $container->get('myeventlane_vendor.current_vendor_resolver');
    }
    catch (ServiceNotFoundException $e) {
      $vendor_resolver = NULL;
    }
    return new self(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('myeventlane_ai.usage_tracker'),
      $container->get('myeventlane_ai.circuit_breaker'),
      $vendor_resolver,
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['myeventlane_ai.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_ai_usage_guardrails_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('myeventlane_ai.settings');

    // Read-only status section.
    $form['status'] = [
      '#type' => 'details',
      '#title' => $this->t('Current day usage'),
      '#open' => TRUE,
      '#weight' => -20,
    ];

    $uid = (int) $this->currentUser()->id();
    $user_usage = $this->usageTracker->getUserUsage($uid);
    $form['status']['user'] = [
      '#type' => 'item',
      '#title' => (string) $this->t('Current user (@uid)', ['@uid' => (string) ($uid ?: 'anonymous')]),
      '#plain_text' => (string) $this->t('@tokens tokens, @requests requests today', [
        '@tokens' => number_format($user_usage['tokens']),
        '@requests' => $user_usage['requests'],
      ]),
    ];

    $vendor_usage = null;
    if ($this->vendorResolver !== null) {
      $vendor = $this->vendorResolver->resolveFromCurrentUser();
      if ($vendor !== null) {
        $vendor_usage = $this->usageTracker->getVendorUsage((int) $vendor->id());
      }
    }
    $form['status']['vendor'] = [
      '#type' => 'item',
      '#title' => (string) $this->t('Vendor (if applicable)'),
      '#plain_text' => (string) ($vendor_usage !== null
        ? $this->t('@tokens tokens, @requests requests today', [
          '@tokens' => number_format($vendor_usage['tokens']),
          '@requests' => $vendor_usage['requests'],
        ])
        : $this->t('N/A (vendor quota applies when using Vendor AI in vendor context)')),
    ];

    $cb_tripped = $this->circuitBreaker->isTripped('openai');
    $form['status']['circuit_breaker'] = [
      '#type' => 'item',
      '#title' => (string) $this->t('Circuit breaker'),
      '#plain_text' => (string) ($cb_tripped
        ? $this->t('Tripped — AI requests blocked until window expires')
        : $this->t('Not tripped')),
    ];

    $form['guardrails'] = [
      '#type' => 'details',
      '#title' => $this->t('Guardrail settings'),
      '#open' => TRUE,
    ];

    $form['guardrails']['rate_limit_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Flood rate limits (per user / per scope)'),
      '#default_value' => $config->get('rate_limit_enabled') !== FALSE,
      '#description' => $this->t('When enabled, AiManager enforces per-user (hourly) and per-scope (10 min) limits via the Flood API. Turn off only on trusted local/dev environments for rapid testing; keep enabled in production.'),
    ];

    $form['guardrails']['daily_user_token_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Daily token limit per user'),
      '#default_value' => $config->get('daily_user_token_limit') ?? 50000,
      '#min' => 1000,
      '#step' => 1000,
      '#description' => $this->t('Maximum tokens per user per day. Default: 50,000. Reservation is max_tokens + 200 per request; actual usage may differ slightly.'),
    ];

    $form['guardrails']['daily_vendor_token_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Daily token limit per vendor'),
      '#default_value' => $config->get('daily_vendor_token_limit') ?? 200000,
      '#min' => 1000,
      '#step' => 1000,
      '#description' => $this->t('Maximum tokens per vendor (tenant) per day. Default: 200,000. Reservation is max_tokens + 200 per request; actual usage may differ slightly.'),
    ];

    $form['guardrails']['daily_cost_ceiling_usd'] = [
      '#type' => 'number',
      '#title' => $this->t('Daily cost ceiling (USD)'),
      '#default_value' => $config->get('daily_cost_ceiling_usd') ?? 10,
      '#min' => 0.1,
      '#step' => 0.5,
      '#description' => $this->t('Estimated daily cost threshold that trips the circuit breaker.'),
    ];

    $form['guardrails']['cb_failure_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Circuit breaker failure threshold'),
      '#default_value' => $config->get('cb_failure_threshold') ?? 10,
      '#min' => 1,
      '#description' => $this->t('Number of provider failures in the window that trips the circuit.'),
    ];

    $form['guardrails']['cb_window_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Circuit breaker window (seconds)'),
      '#default_value' => $config->get('cb_window_seconds') ?? 600,
      '#min' => 60,
      '#description' => $this->t('Duration the circuit stays tripped for failures. Default: 600 (10 minutes).'),
    ];

    $form['guardrails']['cb_cost_trip_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Cost-ceiling trip duration (seconds)'),
      '#default_value' => $config->get('cb_cost_trip_seconds') ?? 86400,
      '#min' => 60,
      '#description' => $this->t('Duration the circuit stays tripped when daily cost ceiling is hit. Default: 86400 (24 hours).'),
    ];

    $form['guardrails']['usage_retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Usage retention (days)'),
      '#default_value' => $config->get('usage_retention_days') ?? 7,
      '#min' => 1,
      '#max' => 90,
      '#description' => $this->t('How long to keep usage keys before auto-expiry.'),
    ];

    $form['guardrails']['retention'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('AI job retention (cleanup)'),
      '#description' => $this->t('Completed and errored AI jobs are deleted after the retention period. Queued and running jobs are never deleted. Set to 0 for infinite retention.'),
    ];

    $form['guardrails']['retention']['ai_job_retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Job retention — done (days)'),
      '#default_value' => $config->get('ai_job_retention_days') ?? 7,
      '#min' => 0,
      '#max' => 365,
      '#description' => $this->t('Days to keep completed jobs before cron cleanup. Default: 7.'),
    ];

    $form['guardrails']['retention']['ai_job_error_retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Job retention — errored (days)'),
      '#default_value' => $config->get('ai_job_error_retention_days') ?? 14,
      '#min' => 0,
      '#max' => 365,
      '#description' => $this->t('Days to keep errored jobs before cron cleanup. Default: 14.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('myeventlane_ai.settings');
    $config
      ->set('rate_limit_enabled', (bool) $form_state->getValue('rate_limit_enabled'))
      ->set('daily_user_token_limit', (int) $form_state->getValue('daily_user_token_limit'))
      ->set('daily_vendor_token_limit', (int) $form_state->getValue('daily_vendor_token_limit'))
      ->set('daily_cost_ceiling_usd', (float) $form_state->getValue('daily_cost_ceiling_usd'))
      ->set('cb_failure_threshold', (int) $form_state->getValue('cb_failure_threshold'))
      ->set('cb_window_seconds', (int) $form_state->getValue('cb_window_seconds'))
      ->set('cb_cost_trip_seconds', (int) $form_state->getValue('cb_cost_trip_seconds'))
      ->set('usage_retention_days', (int) $form_state->getValue('usage_retention_days'))
      ->set('ai_job_retention_days', (int) $form_state->getValue(['retention', 'ai_job_retention_days']))
      ->set('ai_job_error_retention_days', (int) $form_state->getValue(['retention', 'ai_job_error_retention_days']))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
