<?php

namespace Drupal\myeventlane_rsvp\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

final class SmsSettingsForm extends ConfigFormBase {

  protected function getEditableConfigNames(): array {
    return ['myeventlane_rsvp.sms_settings'];
  }

  public function getFormId(): string {
    return 'myeventlane_rsvp_sms_settings';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $c = $this->config('myeventlane_rsvp.sms_settings');

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => 'Enable SMS Notifications',
      '#default_value' => $c->get('enabled'),
    ];

    $form['provider'] = [
      '#type' => 'select',
      '#title' => 'Provider',
      '#options' => ['twilio' => 'Twilio'],
      '#default_value' => $c->get('provider'),
    ];

    $form['twilio'] = [
      '#type' => 'details',
      '#title' => 'Twilio Credentials',
      '#open' => TRUE,
    ];
    $form['twilio']['twilio_sid'] = [
      '#type' => 'textfield',
      '#title' => 'Account SID',
      '#default_value' => $c->get('twilio_sid'),
    ];
    $form['twilio']['twilio_token'] = [
      '#type' => 'textfield',
      '#title' => 'Auth Token',
      '#default_value' => $c->get('twilio_token'),
    ];
    $form['twilio']['twilio_from'] = [
      '#type' => 'textfield',
      '#title' => 'From Number',
      '#default_value' => $c->get('twilio_from'),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('myeventlane_rsvp.sms_settings')
      ->set('enabled', $form_state->getValue('enabled'))
      ->set('provider', $form_state->getValue('provider'))
      ->set('twilio_sid', $form_state->getValue('twilio_sid'))
      ->set('twilio_token', $form_state->getValue('twilio_token'))
      ->set('twilio_from', $form_state->getValue('twilio_from'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}