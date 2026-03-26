<?php

declare(strict_types=1);

namespace Drupal\myeventlane_donations\Service;

use Drupal\myeventlane_core\Service\StripeService;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;

/**
 * Handles optional auto-billing for MEL vendor contribution invoices.
 *
 * SAFETY: Only charges when vendor has explicitly opted in (auto_billing_enabled)
 * and has a saved payment method. NEVER charges by default.
 */
final class VendorAutoBillingService {

  public function __construct(
    private readonly VendorContributionInvoiceService $invoiceService,
    private readonly StripeService $stripeService,
    private readonly \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory,
    private readonly \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager,
  ) {}

  private function logger(): LoggerInterface {
    return $this->loggerFactory->get('myeventlane_donations');
  }

  /**
   * Attempts to auto-charge an issued invoice if vendor has opted in.
   *
   * @return array{success: bool, payment_intent_id?: string|null, error?: string}
   */
  public function attemptAutoCharge(int $invoiceId): array {
    $invoice = $this->invoiceService->loadInvoice($invoiceId);
    if (!$invoice) {
      $this->logger()->error('Auto-billing: invoice @iid not found.', ['@iid' => (string) $invoiceId]);
      return ['success' => FALSE, 'error' => 'Invoice not found'];
    }

    $status = (string) ($invoice['status'] ?? '');
    if ($status !== VendorContributionInvoiceService::INVOICE_ISSUED
      && $status !== VendorContributionInvoiceService::INVOICE_PARTIAL) {
      return ['success' => FALSE, 'error' => 'Invoice not in chargeable status'];
    }

    $remaining = $this->invoiceService->getRemainingCents($invoice);
    if ($remaining <= 0) {
      return ['success' => TRUE];
    }

    $vendorId = (int) $invoice['vendor_id'];
    $vendor = $this->entityTypeManager->getStorage('myeventlane_vendor')->load($vendorId);
    if (!$vendor instanceof Vendor) {
      $this->logger()->error('Auto-billing: vendor @vid not found for invoice @iid.', [
        '@vid' => (string) $vendorId,
        '@iid' => (string) $invoiceId,
      ]);
      return ['success' => FALSE, 'error' => 'Vendor not found'];
    }

    if (!$vendor->isAutoBillingEnabled()) {
      $this->logger()->debug('Auto-billing: vendor @vid has not opted in.', ['@vid' => (string) $vendorId]);
      return ['success' => FALSE, 'error' => 'Auto-billing not enabled'];
    }

    $customerId = $vendor->getStripeCustomerId();
    $paymentMethodId = $vendor->getStripeDefaultPaymentMethod();
    if (!$customerId || !$paymentMethodId) {
      $this->logger()->warning('Auto-billing: vendor @vid missing Stripe customer or payment method.', [
        '@vid' => (string) $vendorId,
      ]);
      return ['success' => FALSE, 'error' => 'No saved payment method'];
    }

    $currency = strtolower((string) ($invoice['currency_code'] ?? 'aud'));
    $metadata = [
      'mel_vendor_invoice_id' => (string) $invoiceId,
      'vendor_id' => (string) $vendorId,
      'source' => 'auto_billing',
    ];

    try {
      $paymentIntent = $this->stripeService->createPaymentIntentOffSession(
        $remaining,
        $currency,
        $customerId,
        $paymentMethodId,
        $metadata,
      );
    }
    catch (ApiErrorException $e) {
      $this->logger()->error('Auto-billing failed for invoice @iid: @message', [
        '@iid' => (string) $invoiceId,
        '@message' => $e->getMessage(),
      ]);
      $this->invoiceService->markInvoiceFailed($invoiceId);
      return ['success' => FALSE, 'error' => $e->getMessage()];
    }

    if ($paymentIntent->status !== 'succeeded') {
      $this->logger()->error('Auto-billing: PaymentIntent @pi not succeeded (status @s).', [
        '@pi' => $paymentIntent->id,
        '@s' => $paymentIntent->status ?? 'unknown',
      ]);
      $this->invoiceService->markInvoiceFailed($invoiceId);
      return ['success' => FALSE, 'error' => 'Payment did not succeed'];
    }

    $this->invoiceService->applyAutoBillingPayment(
      $invoiceId,
      $remaining,
      $currency,
      $paymentIntent->id,
    );

    $this->logger()->info('Auto-billing succeeded for invoice @iid: pi @pi, @cents @cur', [
      '@iid' => (string) $invoiceId,
      '@pi' => $paymentIntent->id,
      '@cents' => (string) $remaining,
      '@cur' => $currency,
    ]);

    return ['success' => TRUE, 'payment_intent_id' => $paymentIntent->id];
  }

  /**
   * Processes auto-billing for newly issued invoices (call after generateInvoicesForWindow).
   *
   * @param array<int, array<string, mixed>> $createdInvoices
   *   Output from VendorContributionInvoiceService::generateInvoicesForWindow.
   *
   * @return array{attempted: int, succeeded: int, failed: int}
   */
  public function processAutoBillingForInvoices(array $createdInvoices): array {
    $result = ['attempted' => 0, 'succeeded' => 0, 'failed' => 0];

    foreach ($createdInvoices as $inv) {
      $invoiceId = (int) ($inv['invoice_id'] ?? 0);
      if ($invoiceId <= 0) {
        continue;
      }
      $r = $this->attemptAutoCharge($invoiceId);
      if ($r['error'] === 'Auto-billing not enabled' || $r['error'] === 'No saved payment method') {
        continue;
      }
      $result['attempted']++;
      $r['success'] ? $result['succeeded']++ : $result['failed']++;
    }

    return $result;
  }

}
