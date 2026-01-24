<?php

declare(strict_types=1);

namespace Drupal\myeventlane_dashboard\Service;

use Stripe\StripeClient;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Stripe Connect vendor status + balances.
 *
 * Authoritative mapping:
 * - commerce_store.field_stripe_account_id (connected account id)
 *
 * Stripe API truth:
 * - Uses Stripe SDK if available and secret key is configured.
 * - Secret key from payment gateway config (mel_stripe or stripe gateway).
 */
final class VendorStripeService implements VendorStripeServiceInterface {

  public function __construct(
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

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
   * {@inheritdoc}
   */
  public function getAvailableBalanceFormatted(StoreInterface $store): string {
    $logger = $this->loggerFactory->get('myeventlane_vendor_dashboard');

    $account_id = $this->getStripeAccountId($store);
    if ($account_id === '') {
      return '$0.00';
    }

    // Stripe secret key from payment gateway config (authoritative).
    $secret = $this->resolveStripeSecretKey();
    if ($secret === '') {
      $logger->warning('Stripe secret key not found in payment gateway config. Balance unavailable for store @sid.', [
        '@sid' => (string) $store->id(),
      ]);
      return '$0.00';
    }

    // Use Stripe SDK if present.
    if (!class_exists('\Stripe\StripeClient')) {
      $logger->warning('Stripe SDK (stripe/stripe-php) not installed. Balance unavailable for store @sid.', [
        '@sid' => (string) $store->id(),
      ]);
      return '$0.00';
    }

    try {
      /** @var \Stripe\StripeClient $client */
      $client = new StripeClient($secret);

      // Available balance for connected account.
      // Stripe returns amounts in cents.
      $balance = $client->balance->retrieve([], ['stripe_account' => $account_id]);

      $available_total = 0;
      foreach (($balance->available ?? []) as $entry) {
        // Sum only AUD to match dashboard currency.
        if (($entry->currency ?? '') === 'aud') {
          $available_total += (int) ($entry->amount ?? 0);
        }
      }

      return '$' . number_format($available_total / 100, 2);
    }
    catch (\Throwable $e) {
      $logger->error('Stripe balance fetch failed for store @sid: @m', [
        '@sid' => (string) $store->id(),
        '@m' => $e->getMessage(),
      ]);
      return '$0.00';
    }
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

  /**
   * Resolve Stripe secret key from payment gateway config (authoritative).
   *
   * Matches myeventlane_core.stripe service logic:
   * - Try 'mel_stripe' gateway first
   * - Fallback to 'stripe' gateway
   * - Config key: 'secret_key' in gateway plugin configuration.
   */
  private function resolveStripeSecretKey(): string {
    // Try mel_stripe gateway first (preferred).
    $gateway = $this->entityTypeManager
      ->getStorage('commerce_payment_gateway')
      ->load('mel_stripe');

    if ($gateway instanceof PaymentGatewayInterface) {
      $config = $gateway->getPluginConfiguration();
      if (!empty($config['secret_key'])) {
        return (string) $config['secret_key'];
      }
    }

    // Fallback to stripe gateway.
    $gateway = $this->entityTypeManager
      ->getStorage('commerce_payment_gateway')
      ->load('stripe');

    if ($gateway instanceof PaymentGatewayInterface) {
      $config = $gateway->getPluginConfiguration();
      if (!empty($config['secret_key'])) {
        return (string) $config['secret_key'];
      }
    }

    return '';
  }

}
