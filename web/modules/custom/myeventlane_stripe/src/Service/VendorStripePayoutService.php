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
 * Stripe-backed vendor payout checking and balance reporting.
 *
 * Secret key is resolved from commerce payment gateway config (mel_stripe
 * or stripe), per project convention. Never exposes secrets; catches and
 * logs all Stripe errors.
 */
final class VendorStripePayoutService {

  /**
   * Zero-decimal currencies (smallest unit = 1). No division for amount.
   *
   * @var string[]
   */
  private const ZERO_DECIMAL = [
    'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg',
    'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf',
  ];

  /**
   * Request-scoped Stripe Balance objects keyed by store ID.
   *
   * @var array<string, object|null>
   */
  private array $balanceCache = [];

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
    if ($secret === '' || !class_exists(StripeClient::class)) {
      // Expected when gateway keys are not set (e.g. local) or SDK missing;
      // vendor theme calls this on many pages — do not log per request.
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
   * Gets available Stripe balance formatted as currency (e.g. "$1,234.56").
   *
   * Uses balance API; consider caching result (e.g. 5 min) when called on
   * page load to avoid repeated Stripe calls.
   */
  public function getAvailableBalanceFormatted(StoreInterface $store): string {
    return $this->getBalancesFormatted($store)['available'];
  }

  /**
   * Gets pending Stripe balance formatted as currency.
   */
  public function getPendingBalanceFormatted(StoreInterface $store): string {
    return $this->getBalancesFormatted($store)['pending'];
  }

  /**
   * Gets available and pending AUD balances with a single Stripe retrieve.
   *
   * @return array{available: string, pending: string}
   *   Formatted balances.
   */
  public function getBalancesFormatted(StoreInterface $store): array {
    $balance = $this->retrieveBalance($store);
    if ($balance === NULL) {
      return [
        'available' => '$0.00',
        'pending' => '$0.00',
      ];
    }

    return [
      'available' => $this->formatAudEntries($balance->available ?? []),
      'pending' => $this->formatAudEntries($balance->pending ?? []),
    ];
  }

  /**
   * Returns the most recent payout summary (any age), or NULL.
   *
   * @return array{amount: string, currency: string, date_label: string, status: string}|null
   *   Latest payout summary, or NULL when unavailable.
   */
  public function getLatestPayoutSummary(StoreInterface $store): ?array {
    $accountId = $this->getStripeAccountId($store);
    if ($accountId === '') {
      return NULL;
    }

    $secret = $this->resolveStripeSecretKey();
    if ($secret === '' || !class_exists(StripeClient::class)) {
      return NULL;
    }

    try {
      $client = new StripeClient($secret);
      $list = $client->payouts->all(
        ['limit' => 1],
        ['stripe_account' => $accountId]
      );
      if ($list->count() === 0) {
        return NULL;
      }

      $payout = $list->data[0];
      $currency = strtolower((string) ($payout->currency ?? 'aud'));
      $minor = (int) ($payout->amount ?? 0);
      $divisor = in_array($currency, self::ZERO_DECIMAL, TRUE) ? 1 : 100;
      $decimals = in_array($currency, self::ZERO_DECIMAL, TRUE) ? 0 : 2;
      $amount = number_format($minor / $divisor, $decimals);
      $ts = (int) ($payout->arrival_date ?? $payout->created ?? 0);
      $dateLabel = $ts > 0 ? date('j M Y', $ts) : '';
      $statusRaw = (string) ($payout->status ?? 'pending');
      $status = match ($statusRaw) {
        'paid' => 'Paid',
        'pending' => 'Pending',
        'in_transit' => 'In transit',
        'canceled', 'cancelled' => 'Cancelled',
        'failed' => 'Failed',
        default => ucfirst($statusRaw),
      };

      return [
        'amount' => '$' . $amount,
        'currency' => strtoupper($currency),
        'date_label' => $dateLabel,
        'status' => $status,
      ];
    }
    catch (ApiErrorException $e) {
      $this->logger->error('Stripe API error in getLatestPayoutSummary for store @id: @m', [
        '@id' => $store->id(),
        '@m' => $e->getMessage(),
      ]);
      return NULL;
    }
    catch (\Throwable $e) {
      $this->logger->error('Unexpected error in getLatestPayoutSummary for store @id: @m', [
        '@id' => $store->id(),
        '@m' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Retrieves the Stripe Balance object once per store per request.
   *
   * @return object|null
   *   Stripe Balance, or NULL when unavailable.
   */
  private function retrieveBalance(StoreInterface $store): ?object {
    $cacheKey = (string) $store->id();
    if (array_key_exists($cacheKey, $this->balanceCache)) {
      return $this->balanceCache[$cacheKey];
    }

    $accountId = $this->getStripeAccountId($store);
    if ($accountId === '') {
      $this->balanceCache[$cacheKey] = NULL;
      return NULL;
    }

    $secret = $this->resolveStripeSecretKey();
    if ($secret === '' || !class_exists(StripeClient::class)) {
      $this->balanceCache[$cacheKey] = NULL;
      return NULL;
    }

    try {
      $client = new StripeClient($secret);
      $balance = $client->balance->retrieve([], ['stripe_account' => $accountId]);
      $this->balanceCache[$cacheKey] = $balance;
      return $balance;
    }
    catch (ApiErrorException $e) {
      $this->logger->error('Stripe balance fetch failed for store @id: @m', [
        '@id' => $store->id(),
        '@m' => $e->getMessage(),
      ]);
      $this->balanceCache[$cacheKey] = NULL;
      return NULL;
    }
    catch (\Throwable $e) {
      $this->logger->error('Stripe balance fetch failed for store @id: @m', [
        '@id' => $store->id(),
        '@m' => $e->getMessage(),
      ]);
      $this->balanceCache[$cacheKey] = NULL;
      return NULL;
    }
  }

  /**
   * Formats AUD amounts from a Stripe Balance available/pending entry list.
   *
   * @param iterable $entries
   *   Stripe balance amount entries.
   */
  private function formatAudEntries(iterable $entries): string {
    $total = 0;
    foreach ($entries as $entry) {
      if (strtolower((string) ($entry->currency ?? '')) === 'aud') {
        $total += (int) ($entry->amount ?? 0);
      }
    }
    return '$' . number_format($total / 100, 2);
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
