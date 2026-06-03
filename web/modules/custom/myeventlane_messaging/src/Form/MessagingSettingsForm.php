<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for messaging settings.
 */
final class MessagingSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_messaging_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['myeventlane_messaging.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('myeventlane_messaging.settings');

    $form['delivery'] = [
      '#type' => 'details',
      '#title' => $this->t('Delivery provider'),
      '#open' => TRUE,
    ];

    $form['delivery']['delivery_provider'] = [
      '#type' => 'radios',
      '#title' => $this->t('Email delivery provider'),
      '#description' => $this->t('Select the provider used to send email. Postmark requires server credentials configured via environment variables.'),
      '#options' => [
        'drupal_mail' => $this->t('Drupal Mail'),
        'postmark' => $this->t('Postmark'),
      ],
      '#default_value' => $config->get('delivery_provider') ?? 'drupal_mail',
      '#required' => TRUE,
    ];

    $form['delivery']['postmark'] = [
      '#type' => 'details',
      '#title' => $this->t('Postmark settings'),
      '#open' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="delivery_provider"]' => ['value' => 'postmark'],
        ],
      ],
    ];

    $form['delivery']['postmark']['postmark_message_stream'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Message stream'),
      '#description' => $this->t('Postmark message stream identifier (default: outbound).'),
      '#default_value' => $config->get('postmark.message_stream') ?? 'outbound',
      '#maxlength' => 64,
      '#required' => TRUE,
    ];

    $form['delivery']['postmark']['postmark_server_token_status'] = [
      '#type' => 'item',
      '#title' => $this->t('Server token'),
      '#markup' => $this->secretStatusMarkup('MEL_POSTMARK_SERVER_TOKEN', (string) ($config->get('postmark.server_token') ?? '')),
    ];

    $form['delivery']['postmark']['postmark_webhook_secret_status'] = [
      '#type' => 'item',
      '#title' => $this->t('Webhook secret'),
      '#markup' => $this->secretStatusMarkup('MEL_POSTMARK_WEBHOOK_SECRET', (string) ($config->get('postmark.webhook_secret') ?? '')),
    ];

    $form['sender'] = [
      '#type' => 'details',
      '#title' => $this->t('Sender settings'),
      '#open' => TRUE,
    ];

    $form['sender']['from_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('From name'),
      '#description' => $this->t('Name shown in the "From" field of emails sent to attendees.'),
      '#default_value' => $config->get('from_name') ?? 'MyEventLane',
      '#maxlength' => 128,
    ];

    $form['sender']['from_email'] = [
      '#type' => 'email',
      '#title' => $this->t('From email'),
      '#description' => $this->t('Email address used as the sender. Leave blank to use the site email.'),
      '#default_value' => $config->get('from_email') ?? '',
    ];

    $form['sender']['reply_to'] = [
      '#type' => 'email',
      '#title' => $this->t('Reply-to email'),
      '#description' => $this->t('Email address for replies. Leave blank to use the From email.'),
      '#default_value' => $config->get('reply_to') ?? '',
    ];

    $form['reminders'] = [
      '#type' => 'details',
      '#title' => $this->t('Reminder settings'),
      '#open' => TRUE,
    ];

    $form['reminders']['event_reminder_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send event reminders'),
      '#description' => $this->t('When enabled, attendees receive a reminder email before their event.'),
      '#default_value' => $config->get('event_reminder_enabled') ?? TRUE,
    ];

    $form['reminders']['event_reminder_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Event reminder timing (hours before)'),
      '#description' => $this->t('How many hours before the event to send the reminder.'),
      '#default_value' => $config->get('event_reminder_hours') ?? 24,
      '#min' => 1,
      '#max' => 168,
      '#states' => [
        'visible' => [
          ':input[name="event_reminder_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['reminders']['cart_abandoned_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send abandoned cart reminders'),
      '#description' => $this->t('When enabled, customers who add tickets but do not complete checkout receive a reminder.'),
      '#default_value' => $config->get('cart_abandoned_enabled') ?? TRUE,
    ];

    $form['reminders']['cart_abandoned_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Abandoned cart timing (hours after)'),
      '#description' => $this->t('How many hours after cart abandonment to send the reminder.'),
      '#default_value' => $config->get('cart_abandoned_hours') ?? 2,
      '#min' => 1,
      '#max' => 72,
      '#states' => [
        'visible' => [
          ':input[name="cart_abandoned_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['queue'] = [
      '#type' => 'details',
      '#title' => $this->t('Queue settings'),
      '#open' => FALSE,
    ];

    $form['queue']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Queue batch size'),
      '#description' => $this->t('Number of messages to process per cron run (legacy).'),
      '#default_value' => $config->get('batch_size') ?? 50,
      '#min' => 1,
      '#max' => 500,
    ];

    $form['queue']['max_messages_per_run'] = [
      '#type' => 'number',
      '#title' => $this->t('Max messages per cron run'),
      '#description' => $this->t('Rate-limit cap: maximum messages processed per cron run.'),
      '#default_value' => $config->get('max_messages_per_run') ?? 200,
      '#min' => 1,
      '#max' => 2000,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('myeventlane_messaging.settings')
      ->set('from_name', $form_state->getValue('from_name'))
      ->set('from_email', $form_state->getValue('from_email'))
      ->set('reply_to', $form_state->getValue('reply_to'))
      ->set('event_reminder_enabled', $form_state->getValue('event_reminder_enabled'))
      ->set('event_reminder_hours', $form_state->getValue('event_reminder_hours'))
      ->set('cart_abandoned_enabled', $form_state->getValue('cart_abandoned_enabled'))
      ->set('cart_abandoned_hours', $form_state->getValue('cart_abandoned_hours'))
      ->set('batch_size', $form_state->getValue('batch_size'))
      ->set('max_messages_per_run', $form_state->getValue('max_messages_per_run'))
      ->set('delivery_provider', $form_state->getValue('delivery_provider'))
      ->set('postmark.message_stream', $form_state->getValue('postmark_message_stream'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Builds status markup for an env-backed secret field.
   *
   * @param string $envName
   *   Environment variable name (e.g. MEL_POSTMARK_SERVER_TOKEN).
   * @param string $effectiveValue
   *   Effective config value after settings.php overrides.
   */
  private function secretStatusMarkup(string $envName, string $effectiveValue): string {
    if ($this->getEnvValue($envName) !== '') {
      return (string) $this->t('Configured via environment variable (@env).', ['@env' => $envName]);
    }
    if ($effectiveValue !== '') {
      return (string) $this->t('Configured.');
    }
    return (string) $this->t('Not configured. Set @env in the web PHP process environment.', ['@env' => $envName]);
  }

  /**
   * Reads a non-empty environment variable value.
   */
  private function getEnvValue(string $name): string {
    $value = getenv($name);
    if (is_string($value) && $value !== '') {
      return $value;
    }
    if (isset($_ENV[$name]) && is_string($_ENV[$name]) && $_ENV[$name] !== '') {
      return $_ENV[$name];
    }
    if (isset($_SERVER[$name]) && is_string($_SERVER[$name]) && $_SERVER[$name] !== '') {
      return $_SERVER[$name];
    }
    return '';
  }

}
