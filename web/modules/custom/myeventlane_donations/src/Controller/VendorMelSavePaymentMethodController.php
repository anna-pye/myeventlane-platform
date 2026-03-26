<?php

declare(strict_types=1);

namespace Drupal\myeventlane_donations\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_donations\Service\VendorBillingPreferencesService;
use Drupal\myeventlane_donations\Service\VendorContributionInvoiceService;
use Drupal\myeventlane_core\Service\StripeService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Save payment method for MEL contribution auto-billing (SetupIntent flow).
 */
final class VendorMelSavePaymentMethodController extends ControllerBase {

  public function __construct(
    private readonly VendorContributionInvoiceService $invoiceService,
    private readonly VendorBillingPreferencesService $billingPreferences,
    private readonly StripeService $stripeService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_donations.vendor_contribution_invoice'),
      $container->get('myeventlane_donations.vendor_billing_preferences'),
      $container->get('myeventlane_core.stripe'),
    );
  }

  /**
   * Save payment method page: SetupIntent + Stripe Elements form.
   */
  public function savePaymentMethod(Request $request): array|RedirectResponse|Response {
    $uid = (int) $this->currentUser()->id();
    $vendorId = $this->invoiceService->resolveVendorIdForUser($uid);
    if ($vendorId === NULL) {
      $this->messenger()->addError($this->t('No organiser account found.'));
      return new RedirectResponse(Url::fromRoute('myeventlane_donations.vendor_mel_billing')->toString());
    }

    $vendor = $this->entityTypeManager()->getStorage('myeventlane_vendor')->load($vendorId);
    if (!$vendor) {
      $this->messenger()->addError($this->t('Vendor not found.'));
      return new RedirectResponse(Url::fromRoute('myeventlane_donations.vendor_mel_billing')->toString());
    }

    // Handle callback: setup_intent in query means we just finished.
    $setupIntentId = $request->query->get('setup_intent');
    if (is_string($setupIntentId) && str_starts_with($setupIntentId, 'seti_')) {
      try {
        $this->billingPreferences->savePaymentMethodFromSetupIntent($vendor, $setupIntentId);
        $this->messenger()->addStatus($this->t('Payment method saved. Automatic payments are now enabled.'));
      }
      catch (\Throwable $e) {
        $this->messenger()->addError($this->t('Could not save payment method: @message', ['@message' => $e->getMessage()]));
      }
      return new RedirectResponse(Url::fromRoute('myeventlane_donations.vendor_mel_billing')->toString());
    }

    try {
      $setupIntent = $this->billingPreferences->createSetupIntentForVendor($vendor);
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Could not start setup: @message', ['@message' => $e->getMessage()]));
      return new RedirectResponse(Url::fromRoute('myeventlane_donations.vendor_mel_billing')->toString());
    }

    $publishableKey = $this->stripeService->getPlatformPublishableKey();
    if (empty($publishableKey)) {
      $this->messenger()->addError($this->t('Stripe is not configured.'));
      return new RedirectResponse(Url::fromRoute('myeventlane_donations.vendor_mel_billing')->toString());
    }

    $returnUrl = Url::fromRoute('myeventlane_donations.vendor_mel_save_payment_method', [], [
      'query' => ['setup_intent' => $setupIntent->id],
      'absolute' => TRUE,
    ])->toString();

    return [
      '#theme' => 'myeventlane_mel_save_payment_method',
      '#client_secret' => $setupIntent->client_secret,
      '#publishable_key' => $publishableKey,
      '#return_url' => $returnUrl,
      '#billing_url' => Url::fromRoute('myeventlane_donations.vendor_mel_billing')->toString(),
      '#attached' => [
        'library' => ['myeventlane_donations/mel-save-payment-method'],
        'drupalSettings' => [
          'melSavePaymentMethod' => [
            'clientSecret' => $setupIntent->client_secret,
            'publishableKey' => $publishableKey,
            'returnUrl' => $returnUrl,
          ],
        ],
      ],
    ];
  }

  /**
   * Removes saved payment method and disables auto-billing.
   */
  public function removePaymentMethod(Request $request): RedirectResponse {
    $uid = (int) $this->currentUser()->id();
    $vendorId = $this->invoiceService->resolveVendorIdForUser($uid);
    if ($vendorId === NULL) {
      $this->messenger()->addError($this->t('No organiser account found.'));
      return new RedirectResponse(Url::fromRoute('myeventlane_donations.vendor_mel_billing')->toString());
    }

    $vendor = $this->entityTypeManager()->getStorage('myeventlane_vendor')->load($vendorId);
    if ($vendor) {
      $this->billingPreferences->removePaymentMethod($vendor);
      $this->messenger()->addStatus($this->t('Payment method removed. Automatic payments are now disabled.'));
    }

    return new RedirectResponse(Url::fromRoute('myeventlane_donations.vendor_mel_billing')->toString());
  }

}
