<?php

declare(strict_types=1);

namespace Drupal\myeventlane_dashboard\Service;

use Drupal\commerce_store\Entity\StoreInterface;

/**
 * Stripe Connect connection status for the vendor dashboard.
 *
 * Authoritative mapping:
 * - commerce_store.field_stripe_account_id (connected account id)
 */
final class VendorStripeConnectionService implements VendorStripeConnectionServiceInterface {

  /**
   * {@inheritdoc}
   */
  public function getConnectionStatus(StoreInterface $store): array {
    $account_id = $this->getStripeAccountId($store);

    if ($account_id === '') {
      return [
        'label' => 'Stripe not connected',
        'state' => 'warn',
        'help' => 'Connect Stripe to receive payouts.',
        'cache_tags' => ['commerce_store:' . $store->id()],
      ];
    }

    return [
      'label' => 'Stripe connected',
      'state' => 'ok',
      'help' => 'Payouts and balances are fetched from Stripe.',
      'cache_tags' => ['commerce_store:' . $store->id()],
    ];
  }

  /**
   * Reads the authoritative Stripe account id from the store.
   */
  private function getStripeAccountId(StoreInterface $store): string {
    if (!$store->hasField('field_stripe_account_id') || $store->get('field_stripe_account_id')->isEmpty()) {
      return '';
    }
    return trim((string) $store->get('field_stripe_account_id')->value);
  }

}
