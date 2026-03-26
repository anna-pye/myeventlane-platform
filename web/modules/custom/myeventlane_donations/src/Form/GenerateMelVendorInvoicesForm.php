<?php

declare(strict_types=1);

namespace Drupal\myeventlane_donations\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\myeventlane_donations\Service\VendorAutoBillingService;
use Drupal\myeventlane_donations\Service\VendorContributionInvoiceService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Manual invoice generation (Phase 3): group unbilled accruals by vendor/currency.
 *
 * Phase 4: After generation, attempts auto-billing for vendors who have opted in.
 */
final class GenerateMelVendorInvoicesForm extends FormBase {

  public function __construct(
    private readonly VendorContributionInvoiceService $invoiceService,
    private readonly VendorAutoBillingService $autoBillingService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_donations.vendor_contribution_invoice'),
      $container->get('myeventlane_donations.vendor_auto_billing'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_generate_mel_vendor_invoices';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['help'] = [
      '#markup' => '<p>' . $this->t('Includes only <strong>unbilled</strong> accrued rows in the time window. Already invoiced rows are never duplicated.') . '</p>',
    ];

    $form['since'] = [
      '#type' => 'date',
      '#title' => $this->t('Accrual created from (date)'),
      '#required' => TRUE,
      '#default_value' => date('Y-m-d', strtotime('-30 days')),
    ];

    $form['until'] = [
      '#type' => 'date',
      '#title' => $this->t('Accrual created to (date)'),
      '#required' => TRUE,
      '#default_value' => date('Y-m-d'),
    ];

    $form['vendor_id'] = [
      '#type' => 'number',
      '#title' => $this->t('Limit to vendor ID (optional)'),
      '#min' => 1,
      '#step' => 1,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate invoices'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $sinceStr = (string) $form_state->getValue('since');
    $untilStr = (string) $form_state->getValue('until');
    $since = strtotime($sinceStr . ' 00:00:00');
    $until = strtotime($untilStr . ' 23:59:59');
    if ($since === FALSE || $until === FALSE) {
      $form_state->setErrorByName('since', $this->t('Enter valid dates.'));
      return;
    }
    if ($until < $since) {
      $form_state->setErrorByName('until', $this->t('"To" must be on or after "From".'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $sinceStr = (string) $form_state->getValue('since');
    $untilStr = (string) $form_state->getValue('until');
    $since = (int) strtotime($sinceStr . ' 00:00:00');
    $until = (int) strtotime($untilStr . ' 23:59:59');

    $vendorRaw = $form_state->getValue('vendor_id');
    $vendorFilter = NULL;
    if ($vendorRaw !== NULL && $vendorRaw !== '' && is_numeric($vendorRaw)) {
      $v = (int) $vendorRaw;
      $vendorFilter = $v > 0 ? $v : NULL;
    }

    $created = $this->invoiceService->generateInvoicesForWindow($vendorFilter, $since, $until);
    if ($created === []) {
      $this->messenger()->addStatus($this->t('No invoices were created. There may be no unbilled rows in that window.'));
      return;
    }

    $this->messenger()->addStatus($this->t('Created @count invoice(s).', ['@count' => (string) count($created)]));

    $autoResult = $this->autoBillingService->processAutoBillingForInvoices($created);
    if ($autoResult['attempted'] > 0) {
      $this->messenger()->addStatus($this->t('Auto-billing: @attempted attempted, @succeeded succeeded, @failed failed.', [
        '@attempted' => (string) $autoResult['attempted'],
        '@succeeded' => (string) $autoResult['succeeded'],
        '@failed' => (string) $autoResult['failed'],
      ]));
    }
    foreach ($created as $row) {
      $this->messenger()->addStatus($this->t('Invoice #@id — vendor @v, @amt @cur', [
        '@id' => (string) ($row['invoice_id'] ?? ''),
        '@v' => (string) ($row['vendor_id'] ?? ''),
        '@amt' => number_format(((int) ($row['total_amount_cents'] ?? 0)) / 100, 2),
        '@cur' => strtoupper((string) ($row['currency_code'] ?? '')),
      ]));
    }
  }

}
