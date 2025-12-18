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
      '#description' => $this->t('Number of messages to process per cron run.'),
      '#default_value' => $config->get('batch_size') ?? 50,
      '#min' => 1,
      '#max' => 500,
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
      ->save();

    parent::submitForm($form, $form_state);
  }

}
