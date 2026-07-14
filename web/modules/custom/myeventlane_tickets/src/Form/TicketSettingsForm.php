<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\myeventlane_tickets\Ticket\TicketQrPayload;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for ticket PDF generation and download settings.
 */
final class TicketSettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    private readonly TicketQrPayload $ticketQrPayload,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('myeventlane_tickets.ticket_qr_payload'),
    );
  }

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

    $form['display']['qr_payload_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('QR payload mode'),
      '#options' => [
        'signed' => $this->t('Signed payload (recommended)'),
        'code_only' => $this->t('Ticket code only'),
      ],
      '#default_value' => $config->get('qr_payload_mode') ?? 'signed',
      '#description' => $this->t('Signed payload includes event id and signature to reduce code forgery risk.'),
    ];

    $form['branding'] = [
      '#type' => 'details',
      '#title' => $this->t('PDF branding'),
      '#open' => TRUE,
    ];

    $form['branding']['brand_logo_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Brand logo URL'),
      '#default_value' => $config->get('brand_logo_url') ?? '',
      '#description' => $this->t('Absolute URL to a logo image included in ticket PDFs.'),
    ];

    $form['branding']['brand_accent_color'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Brand accent color'),
      '#default_value' => $config->get('brand_accent_color') ?? '#f26d5b',
      '#description' => $this->t('Hex color used for key accents (example: #f26d5b).'),
      '#maxlength' => 16,
    ];

    $form['branding']['brand_footer_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Brand footer text'),
      '#default_value' => $config->get('brand_footer_text') ?? '',
      '#description' => $this->t('Optional footer shown in ticket PDF. Tokens are supported when Token module is enabled.'),
    ];

    $form['security'] = [
      '#type' => 'details',
      '#title' => $this->t('QR signing'),
      '#open' => TRUE,
    ];

    $source = $this->ticketQrPayload->resolveSecretSource();
    if (!$this->ticketQrPayload->requiresSigningSecret()) {
      $markup = $source === NULL
        ? $this->t('Status: <strong>PASS</strong> — Not required (QR payload mode is ticket code only)')
        : $this->t('Status: <strong>PASS</strong> — Not required (code only); optional source: @source', ['@source' => $source]);
    }
    elseif ($source === NULL) {
      $markup = $this->t('Status: <strong>FAIL</strong> — MEL_QR_SECRET missing');
    }
    else {
      $markup = $this->t('Status: <strong>PASS</strong> — Source: @source', ['@source' => $source]);
    }
    $form['security']['qr_secret_status'] = [
      '#type' => 'item',
      '#title' => $this->t('QR signing secret'),
      '#markup' => $markup,
      '#description' => $this->t("Set \$settings['myeventlane_qr_secret'] in settings.php or the MEL_QR_SECRET environment variable when using signed QR mode. Secrets are never stored in exported configuration. Verify with: drush mel:qr-secret-status"),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('myeventlane_tickets.settings')
      ->set('pdf_enabled', (bool) $form_state->getValue('pdf_enabled'))
      ->set('pdf_expiry_days', (int) $form_state->getValue('pdf_expiry_days'))
      ->set('include_qr_code', (bool) $form_state->getValue('include_qr_code'))
      ->set('show_ticket_code', (bool) $form_state->getValue('show_ticket_code'))
      ->set('ticket_code_format', (string) $form_state->getValue('ticket_code_format'))
      ->set('qr_payload_mode', (string) $form_state->getValue('qr_payload_mode'))
      ->set('brand_logo_url', trim((string) $form_state->getValue('brand_logo_url')))
      ->set('brand_accent_color', trim((string) $form_state->getValue('brand_accent_color')))
      ->set('brand_footer_text', (string) $form_state->getValue('brand_footer_text'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
