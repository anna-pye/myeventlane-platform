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
use Stripe\StripeClient;

/**
 * Service for Stripe operations including Connect and platform payments.
 */
final class StripeService {

  /**
   * Vendor-facing Stripe Dashboard URL for Standard Connect accounts.
   */
  public const VENDOR_STRIPE_DASHBOARD_URL = 'https://dashboard.stripe.com';

  /**
   * Manage route destination: Express LoginLink (Express Dashboard).
   */
  public const MANAGE_DEST_LOGIN_LINK = 'login_link';

  /**
   * Manage route destination: vendor Stripe Dashboard (Standard accounts).
   */
  public const MANAGE_DEST_STRIPE_DASHBOARD = 'stripe_dashboard';

  /**
   * Manage route destination: replace an incompatible connected account.
   */
  public const MANAGE_DEST_RECONNECT = 'reconnect';

  /**
   * Manage route destination: resume Connect onboarding.
   */
  public const MANAGE_DEST_ONBOARDING = 'onboarding';

  /**
   * Manage route destination: fail closed (unknown/custom/unrecoverable).
   */
  public const MANAGE_DEST_UNSUPPORTED = 'unsupported';

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

    return new StripeClient($secretKey);
  }

  /**
   * Gets or creates the vendor's compatible Connect account.
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

    $account = $this->createConnectAccount($email, 'AU', 'standard');
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
   * @return list<string>
   */
  private function getStripeGatewayLookupIds(): array {
    // vendor_payments and stripe_for_events are staging/live gateway IDs; legacy/local IDs remain for DDEV and older environments.
    return [
      'vendor_payments',
      'stripe_for_events',
      'mel_stripe',
      'stripe',
      'stripe_connect',
      'stripe_myeventlane_v2',
      'stripe_pe_recurring',
    ];
  }

  /**
   * Gets the platform Stripe secret key from payment gateway config.
   *
   * @return string
   *   The secret key, or empty string if not found.
   */
  private function getPlatformSecretKey(): string {
    foreach ($this->getStripeGatewayLookupIds() as $gatewayId) {
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

    return self::melGetEnv('MEL_STRIPE_SECRET_KEY');
  }

  /**
   * Gets the platform Stripe publishable key.
   *
   * @return string
   *   The publishable key, or empty string if not found.
   */
  public function getPlatformPublishableKey(): string {
    foreach ($this->getStripeGatewayLookupIds() as $gatewayId) {
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

    return self::melGetEnv('MEL_STRIPE_PUBLISHABLE_KEY');
  }

  /**
   * Reads MEL env vars the same way as settings.mel_shared_session.php.
   *
   * PHP-FPM and some web servers expose secrets in $_SERVER or $_ENV but not
   * always via getenv(), so we check all three.
   *
   * @return string
   *   The value, or empty string if unset/empty.
   */
  private static function melGetEnv(string $name): string {
    $v = getenv($name);
    if (is_string($v) && $v !== '') {
      return $v;
    }
    if (isset($_ENV[$name]) && is_string($_ENV[$name]) && $_ENV[$name] !== '') {
      return $_ENV[$name];
    }
    if (isset($_SERVER[$name]) && is_string($_SERVER[$name]) && $_SERVER[$name] !== '') {
      return $_SERVER[$name];
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
   * Returns an existing Connect account id or creates a direct-charge account.
   *
   * Never overwrites a non-empty field_stripe_account_id (connected vendors
   * keep their account; a new Standard-like account is only created when it is
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

    $account = $this->createConnectAccount($email, $country, 'standard');
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
   * Creates or resumes a non-destructive replacement account migration.
   *
   * The current account remains authoritative until the replacement completes
   * onboarding and passes the direct-charge compatibility checks. This keeps
   * historical payments and refunds bound to their original account.
   */
  public function beginConnectAccountReplacement(StoreInterface $store, string $email, string $country = 'AU'): string {
    foreach (['field_stripe_account_id', 'field_stripe_replacement_id', 'field_stripe_previous_id'] as $fieldName) {
      if (!$store->hasField($fieldName)) {
        throw new \RuntimeException(sprintf('Required Stripe migration field %s is missing. Run database updates.', $fieldName));
      }
    }

    $activeAccountId = trim((string) $store->get('field_stripe_account_id')->value);
    if ($activeAccountId === '' || !str_starts_with($activeAccountId, 'acct_')) {
      throw new \RuntimeException('A valid current Stripe account is required before starting replacement onboarding.');
    }

    $compatibility = $this->validateDirectChargeAccountEligibility($activeAccountId);
    if ($compatibility['configuration_compatible'] === NULL) {
      throw new \RuntimeException('The current Stripe account configuration could not be verified.');
    }
    if ($compatibility['configuration_compatible']) {
      throw new \RuntimeException('The current Stripe account already uses the approved direct-charge configuration.');
    }

    $pendingAccountId = trim((string) $store->get('field_stripe_replacement_id')->value);
    if ($pendingAccountId !== '' && str_starts_with($pendingAccountId, 'acct_')) {
      return $pendingAccountId;
    }

    if (trim($email) === '') {
      throw new \InvalidArgumentException('Email is required to create a replacement Connect account.');
    }

    $account = $this->createConnectAccount(
      $email,
      $country,
      'standard',
      [
        'myeventlane_store_id' => (string) $store->id(),
        'myeventlane_purpose' => 'direct_charge_replacement',
      ],
      sprintf('mel-direct-charge-replacement-store-%s', (string) $store->id()),
    );
    $pendingAccountId = trim((string) $account->id);
    if ($pendingAccountId === '' || !str_starts_with($pendingAccountId, 'acct_')) {
      throw new \RuntimeException('Stripe replacement account creation returned an invalid account ID.');
    }

    $store->set('field_stripe_replacement_id', $pendingAccountId);
    $store->save();

    $this->safeLog('notice', 'Started direct-charge account replacement for store @store: @old -> @new.', [
      '@store' => (string) $store->id(),
      '@old' => self::maskAccountId($activeAccountId),
      '@new' => self::maskAccountId($pendingAccountId),
    ]);

    return $pendingAccountId;
  }

  /**
   * Atomically promotes an eligible replacement account on a Commerce store.
   *
   * @param array<string, mixed> $status
   *   Status returned by getAccountStatus() for the replacement account.
   */
  public function promoteConnectAccountReplacement(StoreInterface $store, string $replacementAccountId, array $status): string {
    foreach (['field_stripe_account_id', 'field_stripe_replacement_id', 'field_stripe_previous_id'] as $fieldName) {
      if (!$store->hasField($fieldName)) {
        throw new \RuntimeException(sprintf('Required Stripe migration field %s is missing. Run database updates.', $fieldName));
      }
    }

    $pendingAccountId = trim((string) $store->get('field_stripe_replacement_id')->value);
    if ($pendingAccountId === '' || !hash_equals($pendingAccountId, $replacementAccountId)) {
      throw new \RuntimeException('The Stripe replacement account does not match the pending migration.');
    }

    $eligibility = $this->validateDirectChargeAccountEligibility($replacementAccountId);
    if (!$eligibility['eligible']) {
      throw new \RuntimeException($eligibility['reason'] ?? 'The replacement Stripe account is not ready for direct charges.');
    }

    $previousAccountId = trim((string) $store->get('field_stripe_account_id')->value);
    if ($previousAccountId === '' || !str_starts_with($previousAccountId, 'acct_')) {
      throw new \RuntimeException('The current Stripe account is invalid and cannot be archived.');
    }

    $store->set('field_stripe_previous_id', $previousAccountId);
    $store->set('field_stripe_account_id', $replacementAccountId);
    $store->set('field_stripe_replacement_id', NULL);
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

    $this->safeLog('notice', 'Completed direct-charge account replacement for store @store: @old -> @new.', [
      '@store' => (string) $store->id(),
      '@old' => self::maskAccountId($previousAccountId),
      '@new' => self::maskAccountId($replacementAccountId),
    ]);

    return $previousAccountId;
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
   * New organiser accounts use Stripe-owned risk and fee responsibility.
   *
   * @param string $email
   *   The vendor email address.
   * @param string $country
   *   The country code (e.g., 'AU', 'US').
   * @param string $type
   *   Connect account type. 'standard' is the direct-charge default; 'express'
   *   is retained only for explicit legacy callers.
   *
   * @return \Stripe\Account
   *   The created Stripe account.
   *
   * @throws \Stripe\Exception\ApiErrorException
   *   If account creation fails.
   */
  public function createConnectAccount(
    string $email,
    string $country = 'AU',
    string $type = 'standard',
    array $metadata = [],
    ?string $idempotencyKey = NULL,
  ): Account {
    $client = $this->getPlatformClient();

    try {
      $parameters = [
        'country' => $country,
        'email' => $email,
        'capabilities' => [
          'card_payments' => ['requested' => TRUE],
          'transfers' => ['requested' => TRUE],
        ],
      ];
      if ($metadata !== []) {
        $parameters['metadata'] = $metadata;
      }

      if ($type === 'standard') {
        // Equivalent to Standard behaviour, expressed as the responsibility
        // properties that matter to the accepted direct-charge model.
        $parameters['controller'] = [
          'fees' => ['payer' => 'account'],
          'losses' => ['payments' => 'stripe'],
          'requirement_collection' => 'stripe',
          'stripe_dashboard' => ['type' => 'full'],
        ];
      }
      else {
        $parameters['type'] = $type;
      }

      $requestOptions = $idempotencyKey !== NULL && $idempotencyKey !== ''
        ? ['idempotency_key' => $idempotencyKey]
        : NULL;
      $account = $client->accounts->create($parameters, $requestOptions);

      $this->safeLog('info', 'Created Stripe Connect account @id for @email', [
        '@id' => self::maskAccountId((string) $account->id),
        '@email' => $email,
      ]);

      return $account;
    }
    catch (\Throwable $e) {
      if ($e instanceof ApiErrorException) {
        $this->logStripeApiError($e);
      }
      $this->safeLog('error', 'Failed to create Stripe Connect account: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Verifies provider-owned responsibilities required by direct charges.
   *
   * @return array{eligible: bool, configuration_compatible: bool|null, reason: string|null, account_type: string|null, losses_payer: string|null, fee_payer: string|null}
   *   A fail-closed eligibility result with no personal account data.
   */
  public function validateDirectChargeAccountEligibility(string $accountId): array {
    try {
      $account = $this->getPlatformClient()->accounts->retrieve($accountId);
      return self::evaluateDirectChargeAccountEligibility($account);
    }
    catch (\Throwable $e) {
      if ($e instanceof ApiErrorException) {
        $this->logStripeApiError($e);
      }
      $this->safeLog('error', 'Direct-charge account eligibility lookup failed for @id.', [
        '@id' => self::maskAccountId($accountId),
      ]);
      return [
        'eligible' => FALSE,
        'configuration_compatible' => NULL,
        'reason' => 'Stripe account eligibility could not be verified.',
        'account_type' => NULL,
        'losses_payer' => NULL,
        'fee_payer' => NULL,
      ];
    }
  }

  /**
   * Evaluates the immutable Stripe controller properties for direct charges.
   *
   * @return array{eligible: bool, configuration_compatible: bool, reason: string|null, account_type: string|null, losses_payer: string|null, fee_payer: string|null}
   *   The direct-charge responsibility decision.
   */
  public static function evaluateDirectChargeAccountEligibility(Account $account): array {
    $controller = isset($account->controller) && $account->controller
      ? $account->controller->toArray()
      : [];
    $lossesPayer = $controller['losses']['payments'] ?? NULL;
    $feePayer = $controller['fees']['payer'] ?? NULL;
    $requirements = $controller['requirement_collection'] ?? NULL;
    $dashboard = $controller['stripe_dashboard']['type'] ?? NULL;
    $accountType = isset($account->type) ? strtolower((string) $account->type) : NULL;
    $capabilities = isset($account->capabilities) && $account->capabilities
      ? $account->capabilities->toArray()
      : [];

    $configurationCompatible = $lossesPayer === 'stripe'
      && $feePayer === 'account'
      && $requirements === 'stripe'
      && $dashboard === 'full';

    $reason = NULL;
    if (!empty($account->deleted)) {
      $reason = 'The connected Stripe account has been deleted.';
    }
    elseif (empty($account->charges_enabled)) {
      $reason = 'Stripe has not enabled card charges for this account.';
    }
    elseif (($capabilities['card_payments'] ?? NULL) !== 'active') {
      $reason = 'The connected account card-payments capability is not active.';
    }
    elseif ($lossesPayer !== 'stripe') {
      $reason = 'MyEventLane is liable for this connected account payment losses.';
    }
    elseif ($feePayer !== 'account') {
      $reason = 'MyEventLane pays Stripe processing fees for this connected account.';
    }
    elseif ($requirements !== 'stripe' || $dashboard !== 'full') {
      $reason = 'The organiser cannot manage this account through the full Stripe Dashboard.';
    }

    return [
      'eligible' => $reason === NULL,
      'configuration_compatible' => $configurationCompatible,
      'reason' => $reason,
      'account_type' => $accountType,
      'losses_payer' => is_string($lossesPayer) ? $lossesPayer : NULL,
      'fee_payer' => is_string($feePayer) ? $feePayer : NULL,
    ];
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
   * @return array{
   *   eligible: bool,
   *   account: Account|null,
   *   reason: string|null,
   *   account_type: string|null
   * }
   *   Array with eligibility status, account object (if eligible), reason (if not eligible),
   *   and Connect account type when known.
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
          'account_type' => NULL,
        ];
      }

      $accountType = $this->normalizeConnectAccountType($account);

      // Validate: account.deleted !== true.
      if (isset($account->deleted) && $account->deleted === TRUE) {
        return [
          'eligible' => FALSE,
          'account' => $account,
          'reason' => 'Account has been deleted',
          'account_type' => $accountType,
        ];
      }

      // Validate: account.details_submitted === true.
      if (empty($account->details_submitted) || $account->details_submitted !== TRUE) {
        return [
          'eligible' => FALSE,
          'account' => $account,
          'reason' => 'Account details not yet submitted',
          'account_type' => $accountType,
        ];
      }

      // Validate: account.charges_enabled === true.
      if (empty($account->charges_enabled) || $account->charges_enabled !== TRUE) {
        return [
          'eligible' => FALSE,
          'account' => $account,
          'reason' => 'Account charges not yet enabled',
          'account_type' => $accountType,
        ];
      }

      // All checks passed - account is eligible.
      return [
        'eligible' => TRUE,
        'account' => $account,
        'reason' => NULL,
        'account_type' => $accountType,
      ];
    }
    catch (ApiErrorException $e) {
      $this->logStripeApiError($e);
      // Account retrieval failed - log error and return not eligible.
      $this->safeLog('error', 'Failed to retrieve Stripe account @id for eligibility check: @message', [
        '@id' => self::maskAccountId($accountId),
        '@message' => $e->getMessage(),
      ]);

      return [
        'eligible' => FALSE,
        'account' => NULL,
        'reason' => 'Failed to retrieve account',
        'account_type' => NULL,
      ];
    }
  }

  /**
   * Resolves how /stripe/manage should route for a direct-charge account.
   *
   * Uses the direct-charge responsibility check so an incompatible legacy
   * account can never fall through to an Express Dashboard LoginLink.
   *
   * @param string $accountId
   *   The Stripe Connect account ID (acct_xxx).
   *
   * @return array{destination: string, account_type: string|null, reason: string|null}
   *   Destination constant, account type when known, and eligibility reason.
   */
  public function resolveStripeManageDestination(string $accountId): array {
    $eligibility = $this->validateDirectChargeAccountEligibility($accountId);

    return [
      'destination' => self::resolveDirectChargeManageDestinationFromEligibility($eligibility),
      'account_type' => $eligibility['account_type'] ?? NULL,
      'reason' => $eligibility['reason'] ?? NULL,
    ];
  }

  /**
   * Maps direct-charge eligibility to a safe manage-route destination.
   *
   * @param array{
   *   eligible: bool,
   *   configuration_compatible: bool|null,
   *   reason: string|null,
   *   account_type?: string|null
   * } $eligibility
   *   Result from validateDirectChargeAccountEligibility().
   *
   * @return string
   *   One of the MANAGE_DEST_* constants.
   */
  public static function resolveDirectChargeManageDestinationFromEligibility(array $eligibility): string {
    if (($eligibility['configuration_compatible'] ?? NULL) === FALSE) {
      return self::MANAGE_DEST_RECONNECT;
    }

    if (($eligibility['configuration_compatible'] ?? NULL) !== TRUE) {
      return self::MANAGE_DEST_UNSUPPORTED;
    }

    if (!empty($eligibility['eligible'])) {
      return self::MANAGE_DEST_STRIPE_DASHBOARD;
    }

    if (($eligibility['reason'] ?? NULL) === 'The connected Stripe account has been deleted.') {
      return self::MANAGE_DEST_UNSUPPORTED;
    }

    return self::MANAGE_DEST_ONBOARDING;
  }

  /**
   * Maps eligibility to a manage-route destination (testable, no API calls).
   *
   * @param array{
   *   eligible: bool,
   *   account: Account|null,
   *   reason: string|null,
   *   account_type?: string|null
   * } $eligibility
   *   Result from validateAccountDashboardEligibility().
   *
   * @return string
   *   One of the MANAGE_DEST_* constants.
   */
  public static function resolveManageDestinationFromEligibility(array $eligibility): string {
    if (empty($eligibility['eligible'])) {
      $reason = (string) ($eligibility['reason'] ?? '');
      if ($reason === 'Account details not yet submitted' || $reason === 'Account charges not yet enabled') {
        return self::MANAGE_DEST_ONBOARDING;
      }
      return self::MANAGE_DEST_UNSUPPORTED;
    }

    $type = strtolower((string) ($eligibility['account_type'] ?? ''));
    return match ($type) {
      'express' => self::MANAGE_DEST_LOGIN_LINK,
      'standard' => self::MANAGE_DEST_STRIPE_DASHBOARD,
      default => self::MANAGE_DEST_UNSUPPORTED,
    };
  }

  /**
   * Normalizes Stripe Connect account type from a retrieved Account.
   */
  private function normalizeConnectAccountType(Account $account): ?string {
    if (!isset($account->type) || !is_string($account->type) || $account->type === '') {
      return NULL;
    }
    return strtolower($account->type);
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

    // LoginLink is Express-only; Standard accounts use the vendor Dashboard URL.
    if (($eligibility['account_type'] ?? '') !== 'express') {
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
   * Creates a direct-charge PaymentIntent for a ticket sale.
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
        'metadata' => ['mel_charge_model' => 'organiser_direct_charge'] + $metadata,
      ];

      $paymentIntent = $client->paymentIntents->create($params, [
        'stripe_account' => $stripeAccountId,
      ]);

      $this->safeLog('info', 'Created direct-charge PaymentIntent @id for ticket sale: @amount @currency on account @account (MEL fee: @fee)', [
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
