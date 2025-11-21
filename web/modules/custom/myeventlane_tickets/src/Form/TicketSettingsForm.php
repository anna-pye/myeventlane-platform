<?php

namespace Drupal\myeventlane_tickets\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class TicketSettingsForm extends ConfigFormBase {

  public function getFormId(): string {
    return 'myeventlane_tickets_settings_form';
  }

  protected function getEditableConfigNames(): array {
    return ['myeventlane_tickets.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('myeventlane_tickets.settings');

    $form['pdf_expiry_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Ticket PDF Expiry (days)'),
      '#default_value' => $config->get('pdf_expiry_days') ?? 365,
      '#min' => 1,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('myeventlane_tickets.settings')
      ->set('pdf_expiry_days', $form_state->getValue('pdf_expiry_days'))
      ->save();
    parent::submitForm($form, $form_state);
  }
}