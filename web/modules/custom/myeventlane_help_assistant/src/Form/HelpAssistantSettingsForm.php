<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_assistant\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures Help Assistant operational settings.
 */
final class HelpAssistantSettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $configFactory,
    TypedConfigManagerInterface $typedConfigManager,
  ) {
    parent::__construct($configFactory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('config.typed'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['myeventlane_help_assistant.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_help_assistant_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('myeventlane_help_assistant.settings');
    $perHour = (int) ($config->get('max_requests_per_hour') ?? $config->get('rate_limit_per_session_hour') ?? 20);

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Help Assistant'),
      '#default_value' => (bool) ($config->get('enabled') ?? TRUE),
    ];

    $form['max_retrieved_articles'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum retrieved articles'),
      '#default_value' => (int) ($config->get('max_retrieved_articles') ?? 5),
      '#min' => 3,
      '#max' => 5,
      '#required' => TRUE,
      '#description' => $this->t('Number of help articles sent as grounding context (3–5).'),
    ];

    $form['max_requests_per_hour'] = [
      '#type' => 'number',
      '#title' => $this->t('Max requests per visitor per hour'),
      '#default_value' => $perHour,
      '#min' => 5,
      '#max' => 100,
      '#required' => TRUE,
    ];

    $form['max_requests_per_10min'] = [
      '#type' => 'number',
      '#title' => $this->t('Max requests per IP per 10 minutes'),
      '#default_value' => (int) ($config->get('max_requests_per_10min') ?? 5),
      '#min' => 1,
      '#max' => 60,
      '#required' => TRUE,
    ];

    $form['support_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Support URL'),
      '#default_value' => (string) ($config->get('support_url') ?? ''),
      '#description' => $this->t('Relative or absolute URL for support escalation.'),
    ];

    $form['event_suggestions_ai'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Optional AI for event wizard suggestions'),
      '#default_value' => (bool) ($config->get('event_suggestions_ai') ?? FALSE),
      '#description' => $this->t('When enabled, adds up to two extra suggestions using grounded Help Centre search and MyEventLane AI. Deterministic rules always run first; requires AI module enabled.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $perHour = (int) $form_state->getValue('max_requests_per_hour');
    $this->config('myeventlane_help_assistant.settings')
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('max_retrieved_articles', (int) $form_state->getValue('max_retrieved_articles'))
      ->set('max_requests_per_hour', $perHour)
      ->set('rate_limit_per_session_hour', $perHour)
      ->set('max_requests_per_10min', (int) $form_state->getValue('max_requests_per_10min'))
      ->set('support_url', trim((string) $form_state->getValue('support_url')))
      ->set('event_suggestions_ai', (bool) $form_state->getValue('event_suggestions_ai'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
