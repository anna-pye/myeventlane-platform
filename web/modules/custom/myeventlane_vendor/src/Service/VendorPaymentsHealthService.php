<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Psr\Log\LoggerInterface;

/**
 * Organiser-facing payment health for the Payments Hub and trust cards.
 *
 * Reads stored Stripe Connect flags on the organiser's commerce store.
 * Does not call the Stripe API. Never exposes gateway/plugin/store jargon.
 */
final class VendorPaymentsHealthService {

  use StringTranslationTrait;

  public function __construct(
    private readonly CurrentVendorResolverInterface $vendorResolver,
    private readonly LoggerInterface $logger,
    TranslationInterface $stringTranslation,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * Builds payment health for the current account (or a known vendor).
   *
   * @return array<string, mixed>
   *   Organiser-facing health card payload.
   */
  public function buildForCurrentUser(?Vendor $vendor = NULL): array {
    $vendor = $vendor ?? $this->vendorResolver->resolveFromCurrentUser();
    $connectUrl = $this->safeRouteUrl('myeventlane_vendor.stripe_connect', [], [
      'query' => ['destination' => '/vendor/payments'],
    ]);
    $manageUrl = $this->safeRouteUrl('myeventlane_vendor.stripe_manage');
    $resumeUrl = $connectUrl;

    $base = [
      'state' => 'not_connected',
      'tone' => 'muted',
      'headline' => (string) $this->t('Connect Stripe to get paid'),
      'summary' => (string) $this->t('You are not set up to receive ticket payments yet.'),
      'why' => (string) $this->t('Ticket payments need a Stripe connection so money can reach your bank.'),
      'impact' => (string) $this->t('You cannot sell paid tickets until Stripe is connected.'),
      'next_step' => (string) $this->t('Connect Stripe — it only takes a few minutes.'),
      'cta_label' => (string) $this->t('Connect Stripe'),
      'cta_url' => $connectUrl,
      'secondary_cta_label' => NULL,
      'secondary_cta_url' => NULL,
      'connected' => FALSE,
      'charges_enabled' => FALSE,
      'payouts_enabled' => FALSE,
      'verification_status' => (string) $this->t('Not connected'),
      'account_label' => NULL,
      'account_id_present' => FALSE,
      // No Stripe sync timestamp field exists; never use store changed time.
      'last_verified_label' => NULL,
      'needs_attention' => TRUE,
    ];

    if (!$vendor instanceof Vendor) {
      return $base;
    }

    $store = $this->resolveStore($vendor);
    if (!$store instanceof StoreInterface) {
      return $base;
    }

    $accountId = '';
    if ($store->hasField('field_stripe_account_id') && !$store->get('field_stripe_account_id')->isEmpty()) {
      $accountId = trim((string) $store->get('field_stripe_account_id')->value);
    }
    $hasAccount = $accountId !== '' && str_starts_with($accountId, 'acct_');
    $replacementPending = $store->hasField('field_stripe_replacement_id')
      && !$store->get('field_stripe_replacement_id')->isEmpty()
      && str_starts_with(trim((string) $store->get('field_stripe_replacement_id')->value), 'acct_');

    $charges = $store->hasField('field_stripe_charges_enabled') && !$store->get('field_stripe_charges_enabled')->isEmpty()
      ? (bool) $store->get('field_stripe_charges_enabled')->value
      : FALSE;
    $payouts = $store->hasField('field_stripe_payouts_enabled') && !$store->get('field_stripe_payouts_enabled')->isEmpty()
      ? (bool) $store->get('field_stripe_payouts_enabled')->value
      : FALSE;

    $rawStatus = 'pending';
    if ($store->hasField('field_stripe_status') && !$store->get('field_stripe_status')->isEmpty()) {
      $rawStatus = (string) $store->get('field_stripe_status')->value;
    }

    $base['account_label'] = $store->label() ?: NULL;
    $base['account_id_present'] = $hasAccount;
    $base['charges_enabled'] = $charges;
    $base['payouts_enabled'] = $payouts;

    // Open Stripe only after a Connect account exists; not-connected stays
    // Connect-only so organisers are not offered a dead manage link.
    if (!$hasAccount) {
      return $base;
    }

    if ($replacementPending) {
      return array_merge($base, [
        'state' => 'reconnection_pending',
        'tone' => 'attention',
        'headline' => (string) $this->t('Finish reconnecting Stripe'),
        'summary' => (string) $this->t('Your replacement Stripe account still needs setup or verification.'),
        'why' => (string) $this->t('Direct ticket payments require the approved connected-account configuration.'),
        'impact' => (string) $this->t('Paid ticket sales stay blocked until the replacement is ready.'),
        'next_step' => (string) $this->t('Continue the remaining steps in Stripe.'),
        'cta_label' => (string) $this->t('Continue Stripe reconnection'),
        'cta_url' => $this->safeRouteUrl('myeventlane_vendor.stripe_reconnect', [], [
          'query' => ['destination' => '/vendor/payments'],
        ]),
        'secondary_cta_label' => $manageUrl ? (string) $this->t('Open previous Stripe account') : NULL,
        'secondary_cta_url' => $manageUrl,
        'connected' => FALSE,
        'verification_status' => (string) $this->t('Reconnection in progress'),
        'needs_attention' => TRUE,
      ]);
    }

    $base['secondary_cta_label'] = $manageUrl ? (string) $this->t('Open Stripe') : NULL;
    $base['secondary_cta_url'] = $manageUrl;

    if ($charges && $payouts) {
      return array_merge($base, [
        'state' => 'ready',
        'tone' => 'success',
        'headline' => (string) $this->t('Ready to receive payments'),
        'summary' => (string) $this->t('Stripe is connected. You can sell tickets and receive Stripe payouts.'),
        'why' => (string) $this->t('Your Stripe payment account is verified and Stripe payouts are enabled.'),
        'impact' => (string) $this->t("Ticket sales can reach your bank on Stripe's usual schedule."),
        'next_step' => (string) $this->t('Check Stripe payouts anytime from this page.'),
        'cta_label' => (string) $this->t('View Stripe payouts'),
        'cta_url' => $this->safeRouteUrl('myeventlane_vendor.console.payouts') ?? '/vendor/payouts',
        'connected' => TRUE,
        'verification_status' => (string) $this->t('Connected'),
        'needs_attention' => FALSE,
      ]);
    }

    // Restricted must win over charges-enabled / payouts-disabled so organisers
    // see the general attention path instead of payout-delay copy alone.
    if ($rawStatus === 'restricted') {
      return array_merge($base, [
        'state' => 'needs_attention',
        'tone' => 'attention',
        'headline' => (string) $this->t('Stripe needs attention'),
        'summary' => (string) $this->t('Stripe needs more information before payments can continue smoothly.'),
        'why' => (string) $this->t('Verification or account requirements are outstanding in Stripe.'),
        'impact' => (string) $this->t('Stripe charges or payouts may be paused until this is resolved.'),
        'next_step' => (string) $this->t('Review the outstanding items in Stripe.'),
        'cta_label' => (string) $this->t('Fix issue'),
        'cta_url' => $resumeUrl ?? $manageUrl,
        'connected' => $charges,
        'verification_status' => (string) $this->t('Action required'),
        'needs_attention' => TRUE,
      ]);
    }

    if ($charges && !$payouts) {
      return array_merge($base, [
        'state' => 'payout_delayed',
        'tone' => 'attention',
        'headline' => (string) $this->t('Stripe payout delayed'),
        'summary' => (string) $this->t('You can sell tickets, but Stripe payouts to your bank are still restricted.'),
        'why' => (string) $this->t('Stripe still needs bank or identity details before it can send payouts.'),
        'impact' => (string) $this->t('Stripe may keep funds in your Stripe balance until its payout requirements are complete.'),
        'next_step' => (string) $this->t('Open Stripe and finish your payout details.'),
        'cta_label' => (string) $this->t('Fix issue'),
        'cta_url' => $manageUrl ?? $resumeUrl,
        'connected' => TRUE,
        'verification_status' => (string) $this->t('Stripe payouts restricted'),
        'needs_attention' => TRUE,
      ]);
    }

    // acct_ present but charges not ready — connection row must not say Connected.
    return array_merge($base, [
      'state' => 'verification_pending',
      'tone' => 'attention',
      'headline' => (string) $this->t('Verification pending'),
      'summary' => (string) $this->t('Your Stripe setup is underway. A few steps are still left.'),
      'why' => (string) $this->t('Stripe needs you to finish onboarding before everything is ready.'),
      'impact' => (string) $this->t('Paid ticket sales stay blocked until setup is complete.'),
      'next_step' => (string) $this->t('Continue setup in Stripe.'),
      'cta_label' => (string) $this->t('Fix issue'),
      'cta_url' => $resumeUrl ?? $connectUrl,
      'connected' => FALSE,
      'verification_status' => (string) $this->t('Setup incomplete'),
      'needs_attention' => TRUE,
    ]);
  }

  /**
   * Resolves the commerce store linked to a vendor entity.
   */
  public function resolveStore(?Vendor $vendor): ?StoreInterface {
    if (!$vendor instanceof Vendor) {
      return NULL;
    }
    try {
      if ($vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
        $candidate = $vendor->get('field_vendor_store')->entity;
        if ($candidate instanceof StoreInterface) {
          return $candidate;
        }
      }
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not resolve vendor store for payments health: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
    return NULL;
  }

  /**
   * Builds a route URL, or NULL if the route is missing.
   *
   * @param string $route
   *   Route name.
   * @param array<string, mixed> $params
   *   Route parameters.
   * @param array<string, mixed> $options
   *   URL options.
   *
   * @return string|null
   *   Generated URL or NULL.
   */
  private function safeRouteUrl(string $route, array $params = [], array $options = []): ?string {
    try {
      return Url::fromRoute($route, $params, $options)->toString();
    }
    catch (\Throwable) {
      return NULL;
    }
  }

}
