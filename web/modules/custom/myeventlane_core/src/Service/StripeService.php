<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;
use Stripe\Account;
use Stripe\AccountLink;
use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;
use Stripe\LoginLink;
use Stripe\PaymentIntent;

/**
 * Service for Stripe operations including Connect and platform payments.
 */
final class StripeService {

  /**
   * Constructs a StripeService.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Gets the logger for this service.
   *
   * @return \Psr\Log\LoggerInterface
   *   The logger.
   */
  private function logger(): LoggerInterface {
    return $this->loggerFactory->get('myeventlane_core');
  }

  /**
   * Gets the Stripe client for the platform account.
   *
   * @return \Stripe\StripeClient
   *   The Stripe client configured with platform secret key.
   *
   * @throws \RuntimeException
   *   If platform Stripe keys are not configured.
   */
  public function getPlatformClient(): StripeClient {
    $secretKey = $this->getPlatformSecretKey();
    if (empty($secretKey)) {
      throw new \RuntimeException('Platform Stripe secret key is not configured.');
    }

    return new StripeClient($secretKey);
  }

  /**
   * Gets the platform Stripe secret key from payment gateway config.
   *
   * @return string
   *   The secret key, or empty string if not found.
   */
  private function getPlatformSecretKey(): string {
    // Try to get from mel_stripe gateway (preferred).
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

    // Try config entity as last resort.
    $config = $this->configFactory->get('myeventlane_core.stripe_settings');
    $secretKey = $config->get('platform_secret_key');
    if (!empty($secretKey)) {
      return (string) $secretKey;
    }

    return '';
  }

  /**
   * Gets the platform Stripe publishable key.
   *
   * @return string
   *   The publishable key, or empty string if not found.
   */
  public function getPlatformPublishableKey(): string {
    // Try to get from mel_stripe gateway (preferred).
    $gateway = $this->entityTypeManager
      ->getStorage('commerce_payment_gateway')
      ->load('mel_stripe');

    if ($gateway instanceof PaymentGatewayInterface) {
      $config = $gateway->getPluginConfiguration();
      if (!empty($config['publishable_key'])) {
        return (string) $config['publishable_key'];
      }
    }

    // Fallback to stripe gateway.
    $gateway = $this->entityTypeManager
      ->getStorage('commerce_payment_gateway')
      ->load('stripe');

    if ($gateway instanceof PaymentGatewayInterface) {
      $config = $gateway->getPluginConfiguration();
      if (!empty($config['publishable_key'])) {
        return (string) $config['publishable_key'];
      }
    }

    return '';
  }

  /**
   * Creates a Stripe Connect account.
   *
   * @param string $email
   *   The vendor email address.
   * @param string $country
   *   The country code (e.g., 'AU', 'US').
   * @param string $type
   *   Account type: 'standard' (default) or 'express'.
   *
   * @return \Stripe\Account
   *   The created Stripe account.
   *
   * @throws \Stripe\Exception\ApiErrorException
   *   If account creation fails.
   */
  public function createConnectAccount(string $email, string $country = 'AU', string $type = 'standard'): Account {
    $client = $this->getPlatformClient();

    try {
      $account = $client->accounts->create([
        'type' => $type,
        'country' => $country,
        'email' => $email,
        'capabilities' => [
          'card_payments' => ['requested' => TRUE],
          'transfers' => ['requested' => TRUE],
        ],
      ]);

      $this->logger()->info('Created Stripe Connect account @id for @email', [
        '@id' => $account->id,
        '@email' => $email,
      ]);

      return $account;
    }
    catch (ApiErrorException $e) {
      $this->logger()->error('Failed to create Stripe Connect account: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Creates an AccountLink for onboarding a Connect account.
   *
   * @param string $accountId
   *   The Stripe Connect account ID (acct_xxx).
   * @param string $returnUrl
   *   URL to redirect to after onboarding.
   * @param string $refreshUrl
   *   URL to redirect to if link expires.
   *
   * @return \Stripe\AccountLink
   *   The AccountLink object.
   *
   * @throws \Stripe\Exception\ApiErrorException
   *   If AccountLink creation fails.
   */
  public function createAccountLink(string $accountId, string $returnUrl, string $refreshUrl): AccountLink {
    $client = $this->getPlatformClient();

    try {
      $link = $client->accountLinks->create([
        'account' => $accountId,
        'refresh_url' => $refreshUrl,
        'return_url' => $returnUrl,
        'type' => 'account_onboarding',
      ]);

      $this->logger()->info('Created AccountLink for account @id', [
        '@id' => $accountId,
      ]);

      return $link;
    }
    catch (ApiErrorException $e) {
      $this->logger()->error('Failed to create AccountLink: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Creates a LoginLink for accessing a Connect account dashboard.
   *
   * @param string $accountId
   *   The Stripe Connect account ID (acct_xxx).
   *
   * @return \Stripe\LoginLink
   *   The LoginLink object.
   *
   * @throws \Stripe\Exception\ApiErrorException
   *   If LoginLink creation fails.
   */
  public function createLoginLink(string $accountId): LoginLink {
    $client = $this->getPlatformClient();

    try {
      $link = $client->accounts->createLoginLink($accountId);

      $this->logger()->info('Created LoginLink for account @id', [
        '@id' => $accountId,
      ]);

      return $link;
    }
    catch (ApiErrorException $e) {
      $this->logger()->error('Failed to create LoginLink: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Validates if a Stripe Connect account is eligible for dashboard login links.
   *
   * Eligibility requirements:
   * - Account exists (can be retrieved)
   * - Account is not deleted
   * - Account has details_submitted === true
   * - Account has charges_enabled === true.
   *
   * @param string $accountId
   *   The Stripe Connect account ID (acct_xxx).
   *
   * @return array{eligible: bool, account: Account|null, reason: string|null}
   *   Array with eligibility status, account object (if eligible), and reason (if not eligible).
   */
  public function validateAccountDashboardEligibility(string $accountId): array {
    $client = $this->getPlatformClient();

    try {
      // Load Stripe account via API.
      $account = $client->accounts->retrieve($accountId);

      // Validate: account exists (retrieval succeeded means it exists).
      if (!$account) {
        return [
          'eligible' => FALSE,
          'account' => NULL,
          'reason' => 'Account not found',
        ];
      }

      // Validate: account.deleted !== true.
      if (isset($account->deleted) && $account->deleted === TRUE) {
        return [
          'eligible' => FALSE,
          'account' => $account,
          'reason' => 'Account has been deleted',
        ];
      }

      // Validate: account.details_submitted === true.
      if (empty($account->details_submitted) || $account->details_submitted !== TRUE) {
        return [
          'eligible' => FALSE,
          'account' => $account,
          'reason' => 'Account details not yet submitted',
        ];
      }

      // Validate: account.charges_enabled === true.
      if (empty($account->charges_enabled) || $account->charges_enabled !== TRUE) {
        return [
          'eligible' => FALSE,
          'account' => $account,
          'reason' => 'Account charges not yet enabled',
        ];
      }

      // All checks passed - account is eligible.
      return [
        'eligible' => TRUE,
        'account' => $account,
        'reason' => NULL,
      ];
    }
    catch (ApiErrorException $e) {
      // Account retrieval failed - log error and return not eligible.
      $this->logger()->error('Failed to retrieve Stripe account @id for eligibility check: @message', [
        '@id' => $accountId,
        '@message' => $e->getMessage(),
      ]);

      return [
        'eligible' => FALSE,
        'account' => NULL,
        'reason' => 'Failed to retrieve account: ' . $e->getMessage(),
      ];
    }
  }

  /**
   * Creates a LoginLink only if the account is eligible for dashboard access.
   *
   * Before calling createLoginLink(), this method:
   * 1. Loads the Stripe account via API
   * 2. Validates the account exists, is not deleted, details_submitted is true,
   *    and charges_enabled is true
   * 3. Only calls createLoginLink() if all checks pass.
   *
   * @param string $accountId
   *   The Stripe Connect account ID (acct_xxx).
   *
   * @return \Stripe\LoginLink|null
   *   The LoginLink object if eligible, or NULL if not eligible.
   */
  public function createLoginLinkIfEligible(string $accountId): ?LoginLink {
    // Validate account eligibility before attempting to create login link.
    $eligibility = $this->validateAccountDashboardEligibility($accountId);

    if (!$eligibility['eligible']) {
      // Account is not eligible - do NOT call createLoginLink().
      // Logging will be handled by the caller (controller) with myeventlane_vendor channel.
      return NULL;
    }

    // All eligibility checks passed - safe to create login link.
    try {
      return $this->createLoginLink($accountId);
    }
    catch (ApiErrorException $e) {
      // Unexpected failure during login link creation - rethrow for controller to handle.
      throw $e;
    }
  }

  /**
   * Gets the status of a Stripe Connect account.
   *
   * @param string $accountId
   *   The Stripe Connect account ID (acct_xxx).
   *
   * @return array{status: string, charges_enabled: bool, payouts_enabled: bool, details_submitted: bool}
   *   Account status information.
   *
   * @throws \Stripe\Exception\ApiErrorException
   *   If account retrieval fails.
   */
  public function getAccountStatus(string $accountId): array {
    $client = $this->getPlatformClient();

    try {
      $account = $client->accounts->retrieve($accountId);

      // Map Stripe account status to our status values.
      $status = 'pending';
      if ($account->details_submitted && $account->charges_enabled && $account->payouts_enabled) {
        $status = 'complete';
      }
      elseif ($account->charges_enabled === FALSE || $account->payouts_enabled === FALSE) {
        $status = 'restricted';
      }

      return [
        'status' => $status,
        'charges_enabled' => (bool) $account->charges_enabled,
        'payouts_enabled' => (bool) $account->payouts_enabled,
        'details_submitted' => (bool) $account->details_submitted,
      ];
    }
    catch (ApiErrorException $e) {
      $this->logger()->error('Failed to retrieve account status: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Creates a PaymentIntent for a ticket sale (destination charge with Connect).
   *
   * @param int $amount
   *   Amount in cents (e.g., 5000 for $50.00).
   * @param string $currency
   *   Currency code (e.g., 'usd', 'aud').
   * @param string $stripeAccountId
   *   The vendor's Stripe Connect account ID (acct_xxx).
   * @param int $applicationFeeAmount
   *   Application fee in cents (platform fee).
   * @param array $metadata
   *   Optional metadata to attach to the PaymentIntent.
   *
   * @return \Stripe\PaymentIntent
   *   The created PaymentIntent.
   *
   * @throws \Stripe\Exception\ApiErrorException
   *   If PaymentIntent creation fails.
   */
  public function createPaymentIntentForTicketSale(
    int $amount,
    string $currency,
    string $stripeAccountId,
    int $applicationFeeAmount,
    array $metadata = [],
  ): PaymentIntent {
    $client = $this->getPlatformClient();

    try {
      $params = [
        'amount' => $amount,
        'currency' => strtolower($currency),
        'application_fee_amount' => $applicationFeeAmount,
        'transfer_data' => [
          'destination' => $stripeAccountId,
        ],
        'metadata' => $metadata,
      ];

      $paymentIntent = $client->paymentIntents->create($params);

      $this->logger()->info('Created PaymentIntent @id for ticket sale: @amount @currency to account @account (fee: @fee)', [
        '@id' => $paymentIntent->id,
        '@amount' => $amount,
        '@currency' => $currency,
        '@account' => $stripeAccountId,
        '@fee' => $applicationFeeAmount,
      ]);

      return $paymentIntent;
    }
    catch (ApiErrorException $e) {
      $this->logger()->error('Failed to create PaymentIntent for ticket sale: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Creates a PaymentIntent for a Boost purchase (platform-only, no Connect).
   *
   * @param int $amount
   *   Amount in cents (e.g., 3500 for $35.00).
   * @param string $currency
   *   Currency code (e.g., 'usd', 'aud').
   * @param array $metadata
   *   Optional metadata to attach to the PaymentIntent.
   *
   * @return \Stripe\PaymentIntent
   *   The created PaymentIntent.
   *
   * @throws \Stripe\Exception\ApiErrorException
   *   If PaymentIntent creation fails.
   */
  public function createPaymentIntentForBoost(
    int $amount,
    string $currency,
    array $metadata = [],
  ): PaymentIntent {
    $client = $this->getPlatformClient();

    try {
      $params = [
        'amount' => $amount,
        'currency' => strtolower($currency),
        'metadata' => $metadata,
      ];

      $paymentIntent = $client->paymentIntents->create($params);

      $this->logger()->info('Created PaymentIntent @id for Boost purchase: @amount @currency', [
        '@id' => $paymentIntent->id,
        '@amount' => $amount,
        '@currency' => $currency,
      ]);

      return $paymentIntent;
    }
    catch (ApiErrorException $e) {
      $this->logger()->error('Failed to create PaymentIntent for Boost: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Calculates the application fee for a ticket sale.
   *
   * @param int $amount
   *   Amount in cents.
   * @param float $feePercentage
   *   Fee percentage (e.g., 0.03 for 3%).
   * @param int $fixedFeeCents
   *   Fixed fee in cents (e.g., 30 for $0.30).
   *
   * @return int
   *   Application fee in cents.
   */
  public function calculateApplicationFee(int $amount, float $feePercentage = 0.03, int $fixedFeeCents = 30): int {
    $percentageFee = (int) round($amount * $feePercentage);
    return $percentageFee + $fixedFeeCents;
  }

}
