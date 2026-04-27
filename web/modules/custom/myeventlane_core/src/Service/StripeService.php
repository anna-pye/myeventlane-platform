<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\myeventlane_core\Security\SensitiveDataScrubber;
use Psr\Log\LoggerInterface;
use Stripe\Account;
use Stripe\AccountLink;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;
use Stripe\LoginLink;
use Stripe\PaymentIntent;
use Stripe\SetupIntent;
use Stripe\Stripe;
use Stripe\StripeClient;

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
   * Logs a message after scrubbing sensitive data from the context.
   *
   * @param string $level
   *   The log level (info, error, warning, etc.).
   * @param string $message
   *   The log message.
   * @param array $context
   *   The log context array.
   */
  private function safeLog(string $level, string $message, array $context = []): void {
    $this->logger()->log($level, $message, SensitiveDataScrubber::scrub($context));
  }

  /**
   * Logs Stripe API failures to the dedicated production error channel.
   */
  private function logStripeApiError(ApiErrorException $e): void {
    $this->loggerFactory->get('stripe_error')->error($e->getMessage());
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

    Stripe::setApiKey($secretKey);
    $this->loggerFactory->get('stripe_debug')->notice('Stripe key set');

    return new StripeClient($secretKey);
  }

  /**
   * Gets or creates the vendor's Express Connect account.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $vendor
   *   The MEL vendor entity.
   * @param \Drupal\commerce_store\Entity\StoreInterface|null $store
   *   The Commerce store already resolved by the caller, if available.
   *
   * @return string
   *   The Stripe account ID.
   */
  public function getOrCreateAccount(ContentEntityInterface $vendor, ?StoreInterface $store = NULL): string {
    $store ??= $this->resolveVendorStore($vendor);
    if (!$store) {
      $this->loggerFactory->get('stripe_debug')->error('No Commerce store found for vendor @vendor_id', [
        '@vendor_id' => (string) $vendor->id(),
      ]);
      throw new \RuntimeException('No Commerce store found for vendor.');
    }

    if ($store->hasField('field_stripe_account_id') && !$store->get('field_stripe_account_id')->isEmpty()) {
      return trim((string) $store->get('field_stripe_account_id')->value);
    }

    $email = $this->resolveVendorOwnerEmail($vendor);
    if ($email === '') {
      $this->loggerFactory->get('stripe_debug')->error('No owner email found for vendor @vendor_id', [
        '@vendor_id' => (string) $vendor->id(),
      ]);
      throw new \RuntimeException('Vendor owner email is required for Stripe onboarding.');
    }

    $account = $this->createConnectAccount($email, 'AU', 'express');
    $accountId = (string) $account->id;
    if ($accountId === '') {
      $this->loggerFactory->get('stripe_debug')->error('Stripe account creation returned an empty account ID for vendor @vendor_id', [
        '@vendor_id' => (string) $vendor->id(),
      ]);
      throw new \RuntimeException('Stripe account creation returned an empty account ID.');
    }

    if ($store->hasField('field_stripe_account_id')) {
      $store->set('field_stripe_account_id', $accountId);
    }
    if ($store->hasField('field_stripe_status')) {
      $store->set('field_stripe_status', 'pending');
    }
    if ($store->hasField('field_stripe_connected')) {
      $store->set('field_stripe_connected', FALSE);
    }
    $store->save();

    if ($vendor->hasField('field_stripe_account_id')) {
      $vendor->set('field_stripe_account_id', $accountId);
    }
    if ($vendor->hasField('field_stripe_status')) {
      $vendor->set('field_stripe_status', 'pending');
    }
    if ($vendor->hasField('field_stripe_connected')) {
      $vendor->set('field_stripe_connected', FALSE);
    }
    if ($vendor->hasField('field_vendor_store')) {
      $vendor->set('field_vendor_store', $store->id());
    }
    $vendor->save();

    return $accountId;
  }

  /**
   * Resolves the Commerce store attached to a vendor.
   */
  private function resolveVendorStore(ContentEntityInterface $vendor): ?StoreInterface {
    if ($vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
      $store = $vendor->get('field_vendor_store')->entity;
      if ($store instanceof StoreInterface) {
        return $store;
      }
    }

    $ownerId = method_exists($vendor, 'getOwnerId') ? (int) $vendor->getOwnerId() : 0;
    if ($ownerId <= 0) {
      return NULL;
    }

    $storeStorage = $this->entityTypeManager->getStorage('commerce_store');
    $storeIds = $storeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $ownerId)
      ->range(0, 1)
      ->execute();

    if ($storeIds === []) {
      return NULL;
    }

    $store = $storeStorage->load(reset($storeIds));
    return $store instanceof StoreInterface ? $store : NULL;
  }

  /**
   * Resolves the vendor owner email address.
   */
  private function resolveVendorOwnerEmail(ContentEntityInterface $vendor): string {
    if (method_exists($vendor, 'getOwner')) {
      $owner = $vendor->getOwner();
      if ($owner && method_exists($owner, 'getEmail')) {
        return trim((string) $owner->getEmail());
      }
    }

    $ownerId = method_exists($vendor, 'getOwnerId') ? (int) $vendor->getOwnerId() : 0;
    if ($ownerId <= 0) {
      return '';
    }

    $owner = $this->entityTypeManager->getStorage('user')->load($ownerId);
    return $owner && method_exists($owner, 'getEmail')
      ? trim((string) $owner->getEmail())
      : '';
  }

  /**
   * Gets the platform Stripe secret key from payment gateway config.
   *
   * @return string
   *   The secret key, or empty string if not found.
   */
  private function getPlatformSecretKey(): string {
    // Order: sync defaults (mel_stripe, stripe), then MEL v2 id used on some envs, then PE recurring.
    foreach (['mel_stripe', 'stripe', 'stripe_myeventlane_v2', 'stripe_pe_recurring'] as $gatewayId) {
      $gateway = $this->entityTypeManager
        ->getStorage('commerce_payment_gateway')
        ->load($gatewayId);

      if ($gateway instanceof PaymentGatewayInterface) {
        $config = $gateway->getPluginConfiguration();
        if (!empty($config['secret_key'])) {
          return (string) $config['secret_key'];
        }
      }
    }

    $config = $this->configFactory->get('myeventlane_core.stripe_settings');
    $secretKey = $config->get('platform_secret_key');
    if (!empty($secretKey)) {
      return (string) $secretKey;
    }

    $fromEnv = getenv('MEL_STRIPE_SECRET_KEY');
    if (is_string($fromEnv) && $fromEnv !== '') {
      return $fromEnv;
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
    foreach (['mel_stripe', 'stripe', 'stripe_myeventlane_v2', 'stripe_pe_recurring'] as $gatewayId) {
      $gateway = $this->entityTypeManager
        ->getStorage('commerce_payment_gateway')
        ->load($gatewayId);

      if ($gateway instanceof PaymentGatewayInterface) {
        $config = $gateway->getPluginConfiguration();
        if (!empty($config['publishable_key'])) {
          return (string) $config['publishable_key'];
        }
      }
    }

    $fromEnv = getenv('MEL_STRIPE_PUBLISHABLE_KEY');
    if (is_string($fromEnv) && $fromEnv !== '') {
      return $fromEnv;
    }

    return '';
  }

  /**
   * Masks a Stripe Connect account id for safe logs (e.g. acct_1Ab…YZ).
   */
  public static function maskAccountId(string $accountId): string {
    $accountId = trim($accountId);
    if ($accountId === '' || !str_starts_with($accountId, 'acct_')) {
      return '(invalid)';
    }
    if (strlen($accountId) <= 12) {
      return $accountId[0] . $accountId[1] . $accountId[2] . $accountId[3] . '…';
    }
    return substr($accountId, 0, 8) . '…' . substr($accountId, -4);
  }

  /**
   * Returns existing Connect account id on the store, or creates an Express account.
   *
   * Never overwrites a non-empty field_stripe_account_id (connected vendors
   * keep their account; new Express accounts are only created when the id is
   * empty).
   *
   * @return string
   *   Stripe account id (acct_…).
   */
  public function ensureConnectAccountIdForStore(StoreInterface $store, string $email, string $country = 'AU'): string {
    if ($store->hasField('field_stripe_account_id') && !$store->get('field_stripe_account_id')->isEmpty()) {
      $existing = trim((string) $store->get('field_stripe_account_id')->value);
      if ($existing !== '') {
        return $existing;
      }
    }

    if (trim($email) === '') {
      throw new \InvalidArgumentException('Email is required to create a Connect account.');
    }

    $account = $this->createConnectAccount($email, $country, 'express');
    $accountId = (string) $account->id;

    if ($store->hasField('field_stripe_account_id')) {
      $store->set('field_stripe_account_id', $accountId);
    }
    if ($store->hasField('field_stripe_status')) {
      $store->set('field_stripe_status', 'pending');
    }
    if ($store->hasField('field_stripe_connected')) {
      $store->set('field_stripe_connected', FALSE);
    }
    if ($store->hasField('field_stripe_charges_enabled')) {
      $store->set('field_stripe_charges_enabled', FALSE);
    }
    if ($store->hasField('field_stripe_payouts_enabled')) {
      $store->set('field_stripe_payouts_enabled', FALSE);
    }
    $store->save();

    return $accountId;
  }

  /**
   * Persists common Connect flags from a getAccountStatus() result on the store.
   */
  public function applyConnectStatusToCommerceStore(StoreInterface $store, array $status): void {
    if ($store->hasField('field_stripe_status')) {
      $store->set('field_stripe_status', $status['status'] ?? 'pending');
    }
    if ($store->hasField('field_stripe_connected')) {
      $store->set('field_stripe_connected', (bool) ($status['charges_enabled'] ?? FALSE));
    }
    if ($store->hasField('field_stripe_charges_enabled')) {
      $store->set('field_stripe_charges_enabled', (bool) ($status['charges_enabled'] ?? FALSE));
    }
    if ($store->hasField('field_stripe_payouts_enabled')) {
      $store->set('field_stripe_payouts_enabled', (bool) ($status['payouts_enabled'] ?? FALSE));
    }
    $store->save();
  }

  /**
   * Creates a Stripe Connect account.
   *
   * MEL uses Connect Express for vendor-hosted onboarding and responsibilities.
   *
   * @param string $email
   *   The vendor email address.
   * @param string $country
   *   The country code (e.g., 'AU', 'US').
   * @param string $type
   *   Connect account type: 'express' (MEL default) or 'standard' (legacy only).
   *
   * @return \Stripe\Account
   *   The created Stripe account.
   *
   * @throws \Stripe\Exception\ApiErrorException
   *   If account creation fails.
   */
  public function createConnectAccount(string $email, string $country = 'AU', string $type = 'express'): Account {
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

      $this->safeLog('info', 'Created Stripe Connect account @id for @email', [
        '@id' => self::maskAccountId((string) $account->id),
        '@email' => $email,
      ]);

      return $account;
    }
    catch (ApiErrorException $e) {
      $this->logStripeApiError($e);
      $this->safeLog('error', 'Failed to create Stripe Connect account: @message', [
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

      $this->safeLog('info', 'Created AccountLink for account @id', [
        '@id' => self::maskAccountId($accountId),
      ]);

      return $link;
    }
    catch (ApiErrorException $e) {
      $this->logStripeApiError($e);
      $this->safeLog('error', 'Failed to create AccountLink: @message', [
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

      $this->safeLog('info', 'Created LoginLink for account @id', [
        '@id' => self::maskAccountId($accountId),
      ]);

      return $link;
    }
    catch (ApiErrorException $e) {
      $this->logStripeApiError($e);
      $this->safeLog('error', 'Failed to create LoginLink: @message', [
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
      $this->logStripeApiError($e);
      // Account retrieval failed - log error and return not eligible.
      $this->safeLog('error', 'Failed to retrieve Stripe account @id for eligibility check: @message', [
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
      return NULL;
    }

    // All eligibility checks passed - safe to create login link.
    try {
      return $this->createLoginLink($accountId);
    }
    catch (ApiErrorException $e) {
      $this->logStripeApiError($e);
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

      // Map Stripe account status to MEL's seller-ready signal. Payouts can
      // lag after onboarding, so they are tracked separately.
      $status = 'pending';
      if ($account->details_submitted && $account->charges_enabled) {
        $status = 'complete';
      }
      elseif ($account->details_submitted === FALSE || $account->charges_enabled === FALSE) {
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
      $this->logStripeApiError($e);
      $this->safeLog('error', 'Failed to retrieve account status: @message', [
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

      $this->safeLog('info', 'Created PaymentIntent @id for ticket sale: @amount @currency to account @account (fee: @fee)', [
        '@id' => $paymentIntent->id,
        '@amount' => $amount,
        '@currency' => $currency,
        '@account' => self::maskAccountId($stripeAccountId),
        '@fee' => $applicationFeeAmount,
      ]);

      return $paymentIntent;
    }
    catch (ApiErrorException $e) {
      $this->logStripeApiError($e);
      $this->safeLog('error', 'Failed to create PaymentIntent for ticket sale: @message', [
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

      $this->safeLog('info', 'Created PaymentIntent @id for Boost purchase: @amount @currency', [
        '@id' => $paymentIntent->id,
        '@amount' => $amount,
        '@currency' => $currency,
      ]);

      return $paymentIntent;
    }
    catch (ApiErrorException $e) {
      $this->logStripeApiError($e);
      $this->safeLog('error', 'Failed to create PaymentIntent for Boost: @message', [
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

  /**
   * Creates a Stripe Customer for platform billing (e.g. vendor MEL contributions).
   *
   * Reuse for auto-billing: one Customer per vendor, attached payment methods.
   *
   * @param string $email
   *   Customer email (e.g. vendor owner).
   * @param string|null $name
   *   Optional customer name.
   *
   * @return \Stripe\Customer
   *   The created Customer.
   *
   * @throws \Stripe\Exception\ApiErrorException
   */
  public function createCustomer(string $email, ?string $name = NULL): Customer {
    $client = $this->getPlatformClient();

    try {
      $params = ['email' => $email];
      if ($name !== NULL && trim($name) !== '') {
        $params['name'] = trim($name);
      }

      $customer = $client->customers->create($params);

      $this->safeLog('info', 'Created Stripe Customer @id for @email', [
        '@id' => $customer->id,
        '@email' => $email,
      ]);

      return $customer;
    }
    catch (ApiErrorException $e) {
      $this->logStripeApiError($e);
      $this->safeLog('error', 'Failed to create Stripe Customer: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Creates a SetupIntent to collect and save a payment method for a customer.
   *
   * Used for MEL contribution auto-billing: vendor saves card for future invoices.
   * Frontend uses stripe.confirmSetup() with the client secret.
   *
   * @param string $customerId
   *   Stripe Customer ID (cus_xxx).
   * @param array $metadata
   *   Optional metadata (e.g. vendor_id, user_id).
   *
   * @return \Stripe\SetupIntent
   *
   * @throws \Stripe\Exception\ApiErrorException
   */
  public function createSetupIntent(string $customerId, array $metadata = []): SetupIntent {
    $client = $this->getPlatformClient();

    try {
      $params = [
        'customer' => $customerId,
        'usage' => 'off_session',
        'metadata' => $metadata,
      ];

      $setupIntent = $client->setupIntents->create($params);

      $this->safeLog('info', 'Created SetupIntent @id for customer @customer', [
        '@id' => $setupIntent->id,
        '@customer' => $customerId,
      ]);

      return $setupIntent;
    }
    catch (ApiErrorException $e) {
      $this->logStripeApiError($e);
      $this->safeLog('error', 'Failed to create SetupIntent: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Creates and confirms a PaymentIntent for off-session charge (e.g. MEL invoice).
   *
   * Uses saved payment method. MUST only be called when vendor has explicitly
   * opted in to auto-billing and saved a payment method.
   *
   * @param int $amountCents
   *   Amount in cents (e.g. 10000 for $100.00).
   * @param string $currency
   *   Currency code (e.g. 'aud').
   * @param string $customerId
   *   Stripe Customer ID (cus_xxx).
   * @param string $paymentMethodId
   *   Stripe PaymentMethod ID (pm_xxx).
   * @param array $metadata
   *   Metadata (e.g. mel_vendor_invoice_id, vendor_id).
   *
   * @return \Stripe\PaymentIntent
   *
   * @throws \Stripe\Exception\ApiErrorException
   */
  public function createPaymentIntentOffSession(
    int $amountCents,
    string $currency,
    string $customerId,
    string $paymentMethodId,
    array $metadata = [],
  ): PaymentIntent {
    $client = $this->getPlatformClient();

    try {
      $params = [
        'amount' => $amountCents,
        'currency' => strtolower($currency),
        'customer' => $customerId,
        'payment_method' => $paymentMethodId,
        'off_session' => TRUE,
        'confirm' => TRUE,
        'metadata' => $metadata,
      ];

      $paymentIntent = $client->paymentIntents->create($params);

      $this->safeLog('info', 'Created off-session PaymentIntent @id: @amount @currency customer @customer', [
        '@id' => $paymentIntent->id,
        '@amount' => $amountCents,
        '@currency' => $currency,
        '@customer' => $customerId,
      ]);

      return $paymentIntent;
    }
    catch (ApiErrorException $e) {
      $this->logStripeApiError($e);
      $this->safeLog('error', 'Off-session PaymentIntent failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Retrieves the last4 (last 4 digits) of a card for display.
   *
   * @param string $paymentMethodId
   *   Stripe PaymentMethod ID (pm_xxx).
   *
   * @return string|null
   *   Last 4 digits (e.g. '4242') or NULL if not retrievable.
   */
  public function getPaymentMethodLast4(string $paymentMethodId): ?string {
    $client = $this->getPlatformClient();

    try {
      $pm = $client->paymentMethods->retrieve($paymentMethodId);
      if (isset($pm->card->last4)) {
        return (string) $pm->card->last4;
      }
    }
    catch (ApiErrorException $e) {
      $this->logStripeApiError($e);
      $this->safeLog('warning', 'Could not retrieve PaymentMethod @id for last4: @message', [
        '@id' => $paymentMethodId,
        '@message' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

}
