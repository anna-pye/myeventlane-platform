<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for ticket PDF generation and download settings.
 */
final class TicketSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_tickets_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['myeventlane_tickets.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('myeventlane_tickets.settings');

    $form['pdf'] = [
      '#type' => 'details',
      '#title' => $this->t('PDF tickets'),
      '#open' => TRUE,
    ];

    $form['pdf']['pdf_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable PDF tickets'),
      '#description' => $this->t('Allow attendees to download tickets as PDF files.'),
      '#default_value' => $config->get('pdf_enabled') ?? TRUE,
    ];

    $form['pdf']['pdf_expiry_days'] = [
      '#type' => 'number',
      '#title' => $this->t('PDF expiry (days)'),
      '#description' => $this->t('Number of days after the event that PDF downloads remain available. Use 0 for no expiry.'),
      '#default_value' => $config->get('pdf_expiry_days') ?? 365,
      '#min' => 0,
      '#states' => [
        'visible' => [
          ':input[name="pdf_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['display'] = [
      '#type' => 'details',
      '#title' => $this->t('Ticket display'),
      '#open' => TRUE,
    ];

    $form['display']['include_qr_code'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include QR code in tickets'),
      '#description' => $this->t('Add a QR code to each ticket for validation at the door.'),
      '#default_value' => $config->get('include_qr_code') ?? TRUE,
    ];

    $form['display']['show_ticket_code'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show ticket code'),
      '#description' => $this->t('Display the unique ticket code on the ticket.'),
      '#default_value' => $config->get('show_ticket_code') ?? TRUE,
    ];

    $form['display']['ticket_code_format'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Ticket code format'),
      '#description' => $this->t('Format pattern for ticket codes (e.g., alphanumeric, length). Leave blank for default.'),
      '#default_value' => $config->get('ticket_code_format') ?? '',
      '#maxlength' => 64,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('myeventlane_tickets.settings')
      ->set('pdf_enabled', (bool) $form_state->getValue('pdf_enabled'))
      ->set('pdf_expiry_days', (string) $form_state->getValue('pdf_expiry_days'))
      ->set('include_qr_code', (bool) $form_state->getValue('include_qr_code'))
      ->set('show_ticket_code', (bool) $form_state->getValue('show_ticket_code'))
      ->set('ticket_code_format', (string) $form_state->getValue('ticket_code_format'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
