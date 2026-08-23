<?php

declare(strict_types=1);

namespace Drupal\myeventlane_donations\Service;

use Drupal\myeventlane_core\Service\StripeService;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Stripe\SetupIntent;

/**
 * Manages vendor billing preferences for MEL contribution auto-billing.
 */
final class VendorBillingPreferencesService {

  public function __construct(
    private readonly StripeService $stripeService,
    private readonly \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Gets billing state for a vendor.
   *
   * @return array{auto_billing_enabled: bool, has_payment_method: bool, last4: string|null, stripe_customer_id: string|null}
   */
  public function getBillingState(Vendor $vendor): array {
    $customerId = $vendor->getStripeCustomerId();
    $pmId = $vendor->getStripeDefaultPaymentMethod();

    $last4 = NULL;
    if ($pmId) {
      $last4 = $this->stripeService->getPaymentMethodLast4($pmId);
    }

    return [
      'auto_billing_enabled' => $vendor->isAutoBillingEnabled(),
      'has_payment_method' => $pmId !== NULL && trim($pmId) !== '',
      'last4' => $last4,
      'stripe_customer_id' => $customerId,
    ];
  }

  /**
   * Ensures Stripe Customer exists for vendor, creates if needed.
   *
   * @return string
   *   Stripe Customer ID (cus_xxx).
   */
  public function ensureStripeCustomer(Vendor $vendor): string {
    $existing = $vendor->getStripeCustomerId();
    if ($existing) {
      return $existing;
    }

    $owner = $vendor->getOwner();
    $email = $owner ? $owner->getEmail() : '';
    $name = $vendor->getName();

    if (trim($email) === '') {
      throw new \RuntimeException('Vendor owner must have an email to save a payment method.');
    }

    $customer = $this->stripeService->createCustomer($email, $name ?: NULL);
    $vendor->set('stripe_customer_id', $customer->id);
    $vendor->save();

    return $customer->id;
  }

  /**
   * Saves payment method from completed SetupIntent to vendor.
   */
  public function savePaymentMethodFromSetupIntent(Vendor $vendor, string $setupIntentId): void {
    $client = $this->stripeService->getPlatformPaymentsClient();
    $si = $client->setupIntents->retrieve($setupIntentId);

    if ($si->status !== 'succeeded' || empty($si->payment_method)) {
      throw new \InvalidArgumentException('SetupIntent must be succeeded with a payment method.');
    }

    $pmId = is_string($si->payment_method) ? $si->payment_method : $si->payment_method->id;

    $vendor->set('stripe_default_payment_method', $pmId);
    $vendor->set('auto_billing_enabled', 1);
    $vendor->save();
  }

  /**
   * Removes saved payment method and disables auto-billing.
   */
  public function removePaymentMethod(Vendor $vendor): void {
    $vendor->set('stripe_default_payment_method', NULL);
    $vendor->set('auto_billing_enabled', 0);
    $vendor->save();
  }

  /**
   * Sets auto-billing enabled (without changing payment method).
   */
  public function setAutoBillingEnabled(Vendor $vendor, bool $enabled): void {
    $vendor->set('auto_billing_enabled', $enabled ? 1 : 0);
    $vendor->save();
  }

  /**
   * Creates a SetupIntent for the vendor (ensures customer exists first).
   */
  public function createSetupIntentForVendor(Vendor $vendor): SetupIntent {
    $customerId = $this->ensureStripeCustomer($vendor);
    return $this->stripeService->createSetupIntent($customerId, [
      'vendor_id' => (string) $vendor->id(),
    ]);
  }

}
