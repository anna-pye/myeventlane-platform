<?php

declare(strict_types=1);

namespace Drupal\myeventlane_stripe\Service;

use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stripe-backed vendor services (payouts, etc.).
 *
 * Secret key is resolved from commerce payment gateway config (mel_stripe
 * or stripe), per project convention. Never exposes secrets; catches and
 * logs all Stripe errors.
 */
final class VendorStripeService {

  /**
   * Zero-decimal currencies (smallest unit = 1). No division for amount.
   *
   * @var string[]
   */
  private const ZERO_DECIMAL = [
    'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg',
    'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Checks if the store had a paid payout in the last N days.
   *
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The commerce store (must have field_stripe_account_id).
   * @param int $days
   *   Window in days (default 3).
   *
   * @return array{amount: string, currency: string, date: \DateTimeImmutable}|null
   *   Payout summary if a recent paid payout exists, NULL otherwise.
   */
  public function hasRecentPayout(StoreInterface $store, int $days = 3): ?array {
    $accountId = $this->getStripeAccountId($store);
    if ($accountId === '') {
      return NULL;
    }

    $secret = $this->resolveStripeSecretKey();
    if ($secret === '') {
      $this->logger->warning('Stripe secret key not found in payment gateway. hasRecentPayout skipped for store @id.', [
        '@id' => $store->id(),
      ]);
      return NULL;
    }

    if (!class_exists(StripeClient::class)) {
      $this->logger->warning('Stripe SDK (stripe/stripe-php) not installed. hasRecentPayout skipped for store @id.', [
        '@id' => $store->id(),
      ]);
      return NULL;
    }

    try {
      $client = new StripeClient($secret);
      $list = $client->payouts->all(
        ['limit' => 1, 'status' => 'paid'],
        ['stripe_account' => $accountId]
      );

      if ($list->count() === 0) {
        return NULL;
      }

      $payout = $list->data[0];
      $created = (int) ($payout->created ?? 0);
      $cutoff = time() - ($days * 86400);
      if ($created < $cutoff) {
        return NULL;
      }

      $currency = strtolower((string) ($payout->currency ?? 'aud'));
      $minor = (int) ($payout->amount ?? 0);
      $divisor = in_array($currency, self::ZERO_DECIMAL, TRUE) ? 1 : 100;
      $major = $minor / $divisor;
      $decimals = in_array($currency, self::ZERO_DECIMAL, TRUE) ? 0 : 2;
      $amount = number_format($major, $decimals);

      $ts = (int) ($payout->arrival_date ?? $payout->created ?? 0);
      $date = \DateTimeImmutable::createFromFormat('U', (string) $ts);
      if ($date === FALSE) {
        $date = new \DateTimeImmutable('now');
      }

      return [
        'amount' => $amount,
        'currency' => strtoupper($currency),
        'date' => $date,
      ];
    }
    catch (ApiErrorException $e) {
      $this->logger->error('Stripe API error in hasRecentPayout for store @id: @m', [
        '@id' => $store->id(),
        '@m' => $e->getMessage(),
      ]);
      return NULL;
    }
    catch (\Throwable $e) {
      $this->logger->error('Unexpected error in hasRecentPayout for store @id: @m', [
        '@id' => $store->id(),
        '@m' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Reads Stripe account ID from the store.
   */
  private function getStripeAccountId(StoreInterface $store): string {
    if (!$store->hasField('field_stripe_account_id') || $store->get('field_stripe_account_id')->isEmpty()) {
      return '';
    }
    return trim((string) $store->get('field_stripe_account_id')->value);
  }

  /**
   * Resolves Stripe secret key from commerce payment gateway config.
   *
   * Tries mel_stripe, then stripe. Matches myeventlane_dashboard logic.
   */
  private function resolveStripeSecretKey(): string {
    $gateway = $this->entityTypeManager
      ->getStorage('commerce_payment_gateway')
      ->load('mel_stripe');

    if ($gateway instanceof PaymentGatewayInterface) {
      $config = $gateway->getPluginConfiguration();
      if (!empty($config['secret_key'])) {
        return (string) $config['secret_key'];
      }
    }

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
