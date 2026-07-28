<?php

declare(strict_types=1);

namespace Drupal\myeventlane_donations\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\myeventlane_donations\Service\VendorBillingPreferencesService;
use Drupal\myeventlane_donations\Service\VendorContributionInvoiceService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Vendor UI: MEL % contribution accrual, invoices, checkout to pay.
 */
final class VendorMelContributionBillingController extends ControllerBase {

  public function __construct(
    private readonly VendorContributionInvoiceService $invoiceService,
    private readonly VendorBillingPreferencesService $billingPreferences,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_donations.vendor_contribution_invoice'),
      $container->get('myeventlane_donations.vendor_billing_preferences'),
    );
  }

  /**
   * Summary + invoice list + auto-billing section.
   */
  public function overview(): array {
    $uid = (int) $this->currentUser()->id();
    $vendorId = $this->invoiceService->resolveVendorIdForUser($uid);
    if ($vendorId === NULL) {
      return [
        '#markup' => '<p>' . $this->t('No organiser account was found for your user.') . '</p>',
      ];
    }

    $summary = $this->invoiceService->getVendorDashboardSummary($vendorId);
    $invoices = $this->invoiceService->listInvoicesForVendor($vendorId);

    $vendor = $this->entityTypeManager()->getStorage('myeventlane_vendor')->load($vendorId);
    $billing_prefs = [
      'auto_billing_enabled' => FALSE,
      'has_payment_method' => FALSE,
      'last4' => NULL,
      'stripe_customer_id' => NULL,
      'payment_controls_available' => FALSE,
    ];
    $billing_notice = NULL;
    if ($vendor) {
      try {
        $billing_prefs = $this->billingPreferences->getBillingState($vendor) + [
          'payment_controls_available' => TRUE,
        ];
      }
      catch (\Throwable $e) {
        \Drupal::logger('myeventlane_donations')->warning('Vendor billing payment controls are unavailable: @message', [
          '@message' => $e->getMessage(),
        ]);
        $billing_notice = (string) $this->t('Saved-card controls are temporarily unavailable. Your contribution totals and invoices are still shown below.');
      }
    }

    return [
      '#theme' => 'myeventlane_mel_vendor_billing',
      '#summary' => $summary,
      '#invoices' => $invoices,
      '#billing_prefs' => $billing_prefs,
      '#billing_notice' => $billing_notice,
      '#save_payment_url' => Url::fromRoute('myeventlane_donations.vendor_mel_save_payment_method')->toString(),
      '#remove_payment_url' => Url::fromRoute('myeventlane_donations.vendor_mel_remove_payment_method')->toString(),
      '#attached' => [
        'library' => ['myeventlane_donations/mel-vendor-billing'],
      ],
    ];
  }

  /**
   * Invoice detail + line breakdown.
   */
  public function invoiceView(int $invoice): array {
    $row = $this->invoiceService->loadInvoice($invoice);
    if (!$row) {
      return [
        '#markup' => '<p>' . $this->t('Invoice not found.') . '</p>',
      ];
    }
    $lines = $this->invoiceService->getInvoiceContributionLines($invoice);

    return [
      '#theme' => 'myeventlane_mel_vendor_invoice',
      '#invoice' => $row,
      '#lines' => $lines,
      '#pay_url' => Url::fromRoute('myeventlane_donations.vendor_mel_invoice_pay', ['invoice' => $invoice])->toString(),
      '#attached' => [
        'library' => ['myeventlane_donations/mel-vendor-billing'],
      ],
    ];
  }

  /**
   * Redirects to Commerce checkout for the invoice balance.
   */
  public function invoicePay(int $invoice): RedirectResponse|TrustedRedirectResponse {
    $uid = (int) $this->currentUser()->id();
    $url = $this->invoiceService->createCheckoutForInvoice($uid, $invoice);
    if ($url === NULL) {
      $this->messenger()->addError($this->t('This invoice cannot be paid right now. It may already be paid or your session does not match the invoice.'));
      return $this->redirect('myeventlane_donations.vendor_mel_billing');
    }
    return new TrustedRedirectResponse($url->toString());
  }

  /**
   * Page title for invoice view.
   */
  public function invoiceTitle(RouteMatchInterface $route_match): string {
    $invoice = (int) ($route_match->getParameter('invoice') ?? 0);
    return (string) $this->t('MEL contribution invoice #@id', ['@id' => (string) $invoice]);
  }

}
