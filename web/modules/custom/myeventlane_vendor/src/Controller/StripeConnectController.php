<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\StripeService;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\myeventlane_vendor\EventSubscriber\VendorStoreSubscriber;
use Drupal\myeventlane_vendor\Service\UserVendorMembershipQuery;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Controller for Stripe Connect onboarding and management.
 */
final class StripeConnectController extends ControllerBase {

  public function __construct(
    private readonly StripeService $stripeService,
    private readonly LoggerChannelFactoryInterface $loggerChannelFactory,
    private readonly UserVendorMembershipQuery $userVendorMembershipQuery,
    private readonly VendorStoreSubscriber $vendorStoreSubscriber,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.stripe'),
      $container->get('logger.factory'),
      $container->get('myeventlane_vendor.user_vendor_membership_query'),
      $container->get('myeventlane_vendor.vendor_store_subscriber'),
    );
  }

  /**
   * Resolves a vendor for the current user (owner or field_vendor_users).
   */
  private function getCurrentUserVendor(): ?Vendor {
    $userId = (int) $this->currentUser()->id();
    if ($userId === 0) {
      return NULL;
    }

    $ids = $this->userVendorMembershipQuery->getVendorIdsForUser($userId);
    if ($ids === []) {
      return NULL;
    }

    $storage = $this->entityTypeManager()->getStorage('myeventlane_vendor');
    $vendors = $storage->loadMultiple($ids);
    $fallback = NULL;
    foreach ($vendors as $vendor) {
      if ($vendor instanceof Vendor) {
        if ($vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
          return $vendor;
        }
        $fallback = $vendor;
      }
    }

    return $fallback instanceof Vendor ? $fallback : NULL;
  }

  /**
   * Resolves the vendor’s Commerce store without platform default store fallback.
   */
  private function getStoreForConnect(?Vendor $vendor): ?StoreInterface {
    if (!$vendor) {
      return NULL;
    }
    return $this->vendorStoreSubscriber->ensureStoreForVendor($vendor);
  }

  /**
   * Ensures the vendor entity references the same store Stripe updates.
   */
  private function syncVendorStoreReference(Vendor $vendor, StoreInterface $store): void {
    if (!$vendor->hasField('field_vendor_store')) {
      return;
    }
    $current = !$vendor->get('field_vendor_store')->isEmpty()
      ? (string) $vendor->get('field_vendor_store')->target_id
      : '';
    if ($current === (string) $store->id()) {
      return;
    }
    $vendor->set('field_vendor_store', $store->id());
    $vendor->save();
  }

  /**
   * Copies Stripe id/status fields from the store to the vendor when present.
   */
  private function syncStripeAccountFieldsToVendor(StoreInterface $store, Vendor $vendor): void {
    if (!$store->hasField('field_stripe_account_id') || $store->get('field_stripe_account_id')->isEmpty()) {
      return;
    }
    $id = trim((string) $store->get('field_stripe_account_id')->value);
    if ($id === '' || !str_starts_with($id, 'acct_')) {
      return;
    }
    if ($vendor->hasField('field_stripe_account_id')) {
      $vendor->set('field_stripe_account_id', $id);
    }
    if ($vendor->hasField('field_stripe_status') && $store->hasField('field_stripe_status') && !$store->get('field_stripe_status')->isEmpty()) {
      $vendor->set('field_stripe_status', (string) $store->get('field_stripe_status')->value);
    }
    if ($vendor->hasField('field_stripe_connected') && $store->hasField('field_stripe_connected') && !$store->get('field_stripe_connected')->isEmpty()) {
      $vendor->set('field_stripe_connected', (bool) $store->get('field_stripe_connected')->value);
    }
    $vendor->save();
  }

  /**
   * Builds return and refresh URLs for Account Links.
   *
   * The store’s field_stripe_account_id is set before the redirect; the
   * callback resolves the account from the store so the ID is not placed in
   * query strings (logs, referrers, history).
   */
  private function buildAccountLinkUrls(string $destination = ''): array {
    $query = [];
    if ($destination !== '') {
      $query['destination'] = $destination;
    }
    $returnUrl = Url::fromRoute('myeventlane_vendor.stripe_callback', [], [
      'absolute' => TRUE,
      'query' => $query,
    ])->toString();
    $refreshUrl = Url::fromRoute('myeventlane_vendor.stripe_connect', [], [
      'absolute' => TRUE,
      'query' => $query,
    ])->toString();

    return [$returnUrl, $refreshUrl];
  }

  private function applyOffsiteStripeRedirectHeaders(TrustedRedirectResponse $response): TrustedRedirectResponse {
    $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
    $response->headers->set('Pragma', 'no-cache');
    return $response;
  }

  private function redirectToDashboard(): RedirectResponse {
    return new RedirectResponse(Url::fromRoute('myeventlane_vendor.console.dashboard')->toString());
  }

  private function redirectToVendorSettings(): RedirectResponse {
    return new RedirectResponse(Url::fromRoute('myeventlane_vendor.console.settings')->toString());
  }

  private function redirectToPayments(): RedirectResponse {
    return new RedirectResponse(Url::fromRoute('myeventlane_vendor.console.payments')->toString());
  }

  /**
   * Validates query account_id against the store’s Connect account; returns usable id.
   */
  private function resolveValidatedAccountId(?string $queryAccountId, StoreInterface $store): ?string {
    $fromStore = $store->hasField('field_stripe_account_id') && !$store->get('field_stripe_account_id')->isEmpty()
      ? trim((string) $store->get('field_stripe_account_id')->value)
      : '';

    if (is_string($queryAccountId) && str_starts_with($queryAccountId, 'acct_')) {
      if ($fromStore !== '' && $fromStore !== $queryAccountId) {
        $this->loggerChannelFactory->get('myeventlane_vendor')->error(
          'Stripe connect: account_id query @q does not match store @sid account @s',
          [
            '@q' => StripeService::maskAccountId($queryAccountId),
            '@sid' => (string) $store->id(),
            '@s' => StripeService::maskAccountId($fromStore),
          ]
        );
        return NULL;
      }
      return $queryAccountId;
    }

    return $fromStore !== '' ? $fromStore : NULL;
  }

  /**
   * Resolves the pending replacement account without exposing it in a URL.
   */
  private function resolvePendingReplacementAccountId(StoreInterface $store): ?string {
    if (!$store->hasField('field_stripe_replacement_id') || $store->get('field_stripe_replacement_id')->isEmpty()) {
      return NULL;
    }
    $accountId = trim((string) $store->get('field_stripe_replacement_id')->value);
    return str_starts_with($accountId, 'acct_') ? $accountId : NULL;
  }

  /**
   * Starts or resumes Stripe Connect onboarding (Account Links).
   */
  public function connect(Request $request): RedirectResponse {
    $log = $this->loggerChannelFactory->get('myeventlane_vendor');
    $currentUser = $this->currentUser();
    if ($currentUser->isAnonymous()) {
      throw new AccessDeniedHttpException('You must be logged in to connect Stripe.');
    }

    $vendor = $this->getCurrentUserVendor();
    if (!$vendor) {
      $log->error('Stripe connect: no vendor profile for user @uid', [
        '@uid' => (string) $currentUser->id(),
      ]);
      $this->messenger()->addError($this->t('We couldn\'t find your organiser profile. Please contact Support.'));
      return $this->redirectToDashboard();
    }

    $store = $this->getStoreForConnect($vendor);
    if (!$store) {
      $log->error('Stripe connect: no store for vendor @vid uid @uid', [
        '@vid' => (string) $vendor->id(),
        '@uid' => (string) $currentUser->id(),
      ]);
      $this->messenger()->addError($this->t('Your organiser account isn\'t ready yet. Finish onboarding, then return to connect Stripe.'));
      return $this->redirectToDashboard();
    }
    $this->syncVendorStoreReference($vendor, $store);

    $destination = $request->query->get('destination');
    $destStr = is_string($destination) ? $destination : '';
    $queryAccount = $request->query->get('account_id');
    $queryAccountStr = is_string($queryAccount) ? $queryAccount : '';

    $accountId = $this->resolveValidatedAccountId(
      $queryAccountStr !== '' ? $queryAccountStr : NULL,
      $store
    );
    if ($queryAccountStr !== '' && $accountId === NULL) {
      $this->messenger()->addError($this->t('Something didn\'t match with your Stripe account. Please start the Stripe connection again, or contact Support.'));
      return $this->redirectToDashboard();
    }

    if (!empty($accountId)) {
      try {
        $eligibility = $this->stripeService->validateDirectChargeAccountEligibility($accountId);
        if ($eligibility['configuration_compatible'] === FALSE) {
          $this->messenger()->addWarning($this->t('Your current Stripe connection cannot be used for organiser direct charges. Reconnect using the approved Stripe configuration. Your existing account remains recorded until the replacement is ready.'));
          return new RedirectResponse(Url::fromRoute('myeventlane_vendor.stripe_reconnect', [], [
            'query' => $destStr !== '' ? ['return_to' => $destStr] : [],
          ])->toString());
        }
        if ($eligibility['configuration_compatible'] === NULL) {
          $this->messenger()->addError($this->t('We could not verify your Stripe account configuration. No account was changed. Please try again or contact support.'));
          return $this->redirectToDashboard();
        }

        $status = $this->stripeService->getAccountStatus($accountId);
        $this->stripeService->applyConnectStatusToCommerceStore($store, $status);
        $this->syncStripeAccountFieldsToVendor($store, $vendor);

        if (!empty($status['charges_enabled'])) {
          $this->messenger()->addStatus($this->t('Stripe is already connected and ready to accept card payments.'));
          return $this->redirectToDashboard();
        }
      }
      catch (\Exception $e) {
        if ($e instanceof ApiErrorException) {
          $this->logConnectApiError($e, $vendor, $store, (string) $accountId);
          $this->messenger()->addError($this->t('We could not refresh your Stripe status. Please try again in a few minutes or contact support.'));
          return $this->redirectToDashboard();
        }
        $log->warning('Stripe connect: refresh status for resume link: @m', [
          '@m' => $e->getMessage(),
        ]);
      }
    }

    $userEmail = (string) $currentUser->getEmail();
    if (trim($userEmail) === '') {
      $this->messenger()->addError($this->t('Your account must have an email address to connect Stripe.'));
      return $this->redirectToDashboard();
    }

    try {
      if (empty($accountId)) {
        $accountId = $this->stripeService->ensureConnectAccountIdForStore($store, $userEmail, 'AU');
        $this->syncStripeAccountFieldsToVendor($store, $vendor);
      }
      if ($accountId === '') {
        throw new \RuntimeException('Stripe account id missing after ensure.');
      }

      [$returnUrl, $refreshUrl] = $this->buildAccountLinkUrls($destStr);
      $accountLink = $this->stripeService->createAccountLink($accountId, $returnUrl, $refreshUrl);
      if (empty($accountLink->url) || !is_string($accountLink->url) || $accountLink->url === '') {
        $log->error('Stripe connect: empty AccountLink for vendor @vid store @sid', [
          '@vid' => (string) $vendor->id(),
          '@sid' => (string) $store->id(),
        ]);
        throw new \RuntimeException('Stripe onboarding could not be started (no link).');
      }
      $url = $accountLink->url;
      if (!str_starts_with($url, 'https://connect.stripe.com')) {
        $log->error('Stripe connect: unexpected redirect host for vendor @vid', ['@vid' => (string) $vendor->id()]);
        throw new \RuntimeException('Invalid Stripe URL.');
      }

      $log->notice('Stripe Account Link created: vendor @vid, store @sid, account @acct', [
        '@vid' => (string) $vendor->id(),
        '@sid' => (string) $store->id(),
        '@acct' => StripeService::maskAccountId($accountId),
      ]);

      $response = new TrustedRedirectResponse($url);
      $response->setTrustedTargetUrl($url);
      $this->applyOffsiteStripeRedirectHeaders($response);
      return $response;
    }
    catch (ApiErrorException $e) {
      $this->logConnectApiError($e, $vendor, $store, (string) ($accountId ?? ''));
      $this->messenger()->addError($this->t('We could not start Stripe onboarding. If this continues, the platform may need a Stripe review for Connect, or you can try again later.'));
      return $this->redirectToDashboard();
    }
    catch (\Exception $e) {
      $this->getLogger('myeventlane_vendor')->error('Stripe connect: @m', [
        '@m' => $e->getMessage(),
        'exception' => $e,
      ]);

      $msg = $e->getMessage();
      if (str_contains($msg, 'Platform Stripe secret key is not configured')) {
        $this->messenger()->addError($this->t('Stripe Connect is not configured for this environment. Set MEL_CONNECT_STRIPE_SECRET_KEY in the web PHP process—not only in an SSH session—then restart PHP or the web server, run drush cr, and try again. The legacy MEL_STRIPE_SECRET_KEY is supported only as a temporary fallback.'));
        return $this->redirectToDashboard();
      }
      if (str_contains($msg, 'Invalid Stripe URL') || str_contains($msg, 'no link')) {
        $this->messenger()->addError($this->t('Stripe returned an unexpected onboarding link. Check recent logs, then try again or contact support.'));
        return $this->redirectToDashboard();
      }
      if ($e instanceof \InvalidArgumentException && str_contains($msg, 'Email is required')) {
        $this->messenger()->addError($this->t('Your account must have an email address to connect Stripe.'));
        return $this->redirectToDashboard();
      }

      $this->messenger()->addError($this->t('Failed to start Stripe onboarding. Please try again or contact support.'));
      return $this->redirectToDashboard();
    }
  }

  private function logConnectApiError(ApiErrorException $e, Vendor $vendor, StoreInterface $store, string $accountId): void {
    $stripe = $e->getStripeCode() ?? $e->getCode();
    $this->loggerChannelFactory->get('myeventlane_vendor')->error(
      'Stripe API error: vendor @vid, uid @uid, store @sid, account @acct, type @type, @message',
      [
        '@vid' => (string) $vendor->id(),
        '@uid' => (string) $this->currentUser()->id(),
        '@sid' => (string) $store->id(),
        '@acct' => $accountId !== '' ? StripeService::maskAccountId($accountId) : 'n/a',
        '@type' => is_string($stripe) || is_int($stripe) || is_float($stripe) ? (string) $stripe : 'unknown',
        '@message' => $e->getMessage(),
      ]
    );
  }

  /**
   * Handles Stripe return after Connect onboarding (callback route).
   */
  public function callback(Request $request): RedirectResponse {
    $log = $this->loggerChannelFactory->get('myeventlane_vendor');
    $currentUser = $this->currentUser();
    if ($currentUser->isAnonymous()) {
      throw new AccessDeniedHttpException('You must be logged in.');
    }

    $destination = $request->query->get('return_to');
    if (!is_string($destination) || $destination === '') {
      // Backwards compatibility for Account Links created before this fix.
      $destination = $request->query->get('destination');
    }
    $dest = is_string($destination) ? $destination : '';
    if ($dest !== '' && (!str_starts_with($dest, '/') || str_starts_with($dest, '//'))) {
      $dest = '';
    }

    $vendor = $this->getCurrentUserVendor();
    if (!$vendor) {
      $this->messenger()->addError($this->t('We couldn\'t find your organiser profile.'));
      return $this->redirectToDashboard();
    }

    $store = $this->getStoreForConnect($vendor);
    if (!$store) {
      $this->messenger()->addError($this->t('No store found for your account.'));
      return $this->redirectToDashboard();
    }
    $this->syncVendorStoreReference($vendor, $store);

    $isReplacement = $request->query->get('replacement') === '1';
    $qid = $request->query->get('account_id');
    $qidStr = is_string($qid) ? $qid : '';
    $accountId = $isReplacement
      ? $this->resolvePendingReplacementAccountId($store)
      : $this->resolveValidatedAccountId($qidStr !== '' ? $qidStr : NULL, $store);
    if ($accountId === NULL) {
      if ($isReplacement) {
        $this->messenger()->addError($this->t('We could not find a pending Stripe reconnection. No existing account was replaced.'));
      }
      elseif ($qidStr !== '') {
        $this->messenger()->addError($this->t('Something didn\'t match with your Stripe account. Please start the Stripe connection again from your dashboard.'));
      }
      else {
        $this->messenger()->addWarning($this->t('We couldn\'t find your Stripe account. Please start the Stripe connection again.'));
      }
      $restartQueryName = $isReplacement ? 'return_to' : 'destination';
      $restartQuery = $dest ? [$restartQueryName => $dest] : [];
      return new RedirectResponse(Url::fromRoute($isReplacement ? 'myeventlane_vendor.stripe_reconnect' : 'myeventlane_vendor.stripe_connect', [], [
        'query' => $restartQuery,
      ])->setAbsolute()->toString());
    }

    try {
      $status = $this->stripeService->getAccountStatus($accountId);
    }
    catch (ApiErrorException $e) {
      $this->loggerChannelFactory->get('stripe_error')->error($e->getMessage());
      $this->logConnectApiError($e, $vendor, $store, $accountId);
      $this->messenger()->addError($this->t('We could not verify your Stripe account. Please try again.'));
      if ($dest !== '') {
        return new RedirectResponse($dest);
      }
      return $this->redirectToDashboard();
    }
    catch (\Exception $e) {
      $log->error('Stripe callback: @m', ['@m' => $e->getMessage()]);
      $this->messenger()->addError($this->t('We couldn\'t check your Stripe account status. Please try again, or contact Support if it keeps happening.'));
      if ($dest !== '') {
        return new RedirectResponse($dest);
      }
      return $this->redirectToDashboard();
    }

    if ($isReplacement) {
      $eligibility = $this->stripeService->validateDirectChargeAccountEligibility($accountId);
      if ($eligibility['configuration_compatible'] !== TRUE) {
        $this->messenger()->addError($this->t('The replacement Stripe account does not match the approved direct-charge configuration. No existing account was replaced. Please contact support.'));
        return $this->redirectToVendorSettings();
      }
      if (!$eligibility['eligible']) {
        $this->messenger()->addWarning($this->t('Finish the remaining Stripe verification steps. Your existing account remains recorded until the replacement can accept direct ticket payments.'));
        return new RedirectResponse(Url::fromRoute('myeventlane_vendor.stripe_reconnect', [], [
          'query' => $dest !== '' ? ['return_to' => $dest] : [],
        ])->toString());
      }

      try {
        $this->stripeService->promoteConnectAccountReplacement($store, $accountId, $status);
        $this->syncStripeAccountFieldsToVendor($store, $vendor);
      }
      catch (\Throwable $exception) {
        $log->error('Stripe replacement promotion failed for store @sid: @message', [
          '@sid' => (string) $store->id(),
          '@message' => $exception->getMessage(),
        ]);
        $this->messenger()->addError($this->t('Stripe setup completed, but MyEventLane could not safely switch the account. No existing account was replaced. Please contact support.'));
        return $this->redirectToVendorSettings();
      }

      $log->notice('Stripe replacement promoted: store @sid, account @acct.', [
        '@sid' => (string) $store->id(),
        '@acct' => StripeService::maskAccountId($accountId),
      ]);
      $this->messenger()->addStatus($this->t('Stripe reconnection complete. Your account is ready for direct ticket payments.'));
      if ($dest !== '') {
        return new RedirectResponse($dest);
      }
      return $this->redirectToDashboard();
    }

    if ($store->hasField('field_stripe_account_id')) {
      $store->set('field_stripe_account_id', $accountId);
    }
    $this->stripeService->applyConnectStatusToCommerceStore($store, $status);
    $this->syncStripeAccountFieldsToVendor($store, $vendor);

    $log->notice('Stripe callback saved: store @sid, account @acct, charges @ch, payouts @p, details @d', [
      '@sid' => (string) $store->id(),
      '@acct' => StripeService::maskAccountId($accountId),
      '@ch' => !empty($status['charges_enabled']) ? '1' : '0',
      '@p' => !empty($status['payouts_enabled']) ? '1' : '0',
      '@d' => !empty($status['details_submitted']) ? '1' : '0',
    ]);

    if (($status['status'] ?? '') === 'complete') {
      $this->messenger()->addStatus($this->t('Stripe account connected. You can accept card payments when charges are active.'));
    }
    elseif (!empty($status['charges_enabled'])) {
      $this->messenger()->addStatus($this->t('Stripe can process charges. Check your Stripe Dashboard for any remaining items.'));
    }
    elseif (($status['status'] ?? '') === 'pending' || empty($status['details_submitted'] ?? NULL)) {
      $this->messenger()->addWarning($this->t('Please complete the remaining steps in your Stripe account setup.'));
    }
    else {
      // Internal status is logged separately; show a calm next-step message
      // to organisers instead of leaking raw Stripe state strings.
      $this->messenger()->addWarning($this->t('Your Stripe account isn\'t fully set up yet. Open your Stripe dashboard to finish onboarding.'));
    }

    if ($dest !== '') {
      return new RedirectResponse($dest);
    }
    return $this->redirectToDashboard();
  }

  /**
   * Opens Stripe management for the vendor’s direct-charge connected account.
   *
   * Compatible accounts open the full Stripe Dashboard. Incompatible legacy
   * accounts enter the protected replacement flow, and incomplete compatible
   * accounts resume Connect onboarding.
   */
  public function manage(): RedirectResponse|TrustedRedirectResponse {
    $logger = $this->loggerChannelFactory->get('myeventlane_vendor');
    $currentUser = $this->currentUser();

    if ($currentUser->isAnonymous()) {
      throw new AccessDeniedHttpException('You must be logged in to manage Stripe.');
    }

    $vendor = $this->getCurrentUserVendor();
    $store = $this->getStoreForConnect($vendor);
    if (!$store) {
      $logger->warning('Stripe manage: no store for user @uid', [
        '@uid' => (string) $currentUser->id(),
      ]);
      $this->messenger()->addError($this->t('No store found for your account.'));
      return $this->redirectToDashboard();
    }
    if ($vendor) {
      $this->syncVendorStoreReference($vendor, $store);
    }

    $accountId = $store->hasField('field_stripe_account_id') && !$store->get('field_stripe_account_id')->isEmpty()
      ? trim((string) $store->get('field_stripe_account_id')->value)
      : '';

    if ($accountId === '' || !str_starts_with($accountId, 'acct_')) {
      $this->messenger()->addWarning($this->t('Stripe is not connected. Please connect your Stripe account first.'));
      return new RedirectResponse(Url::fromRoute('myeventlane_vendor.stripe_connect')->toString());
    }

    try {
      $resolution = $this->stripeService->resolveStripeManageDestination($accountId);
      $destination = (string) ($resolution['destination'] ?? StripeService::MANAGE_DEST_UNSUPPORTED);
      $accountType = isset($resolution['account_type']) && is_string($resolution['account_type'])
        ? $resolution['account_type']
        : 'unknown';
      $reason = (string) ($resolution['reason'] ?? '');

      if ($destination === StripeService::MANAGE_DEST_RECONNECT) {
        $logger->notice('Stripe manage: incompatible account requires reconnection, store @sid, uid @uid, type @type', [
          '@sid' => (string) $store->id(),
          '@uid' => (string) $currentUser->id(),
          '@type' => $accountType,
        ]);
        $this->messenger()->addWarning($this->t('Your current Stripe connection cannot be used for organiser direct charges. Reconnect using the approved Stripe configuration. Your existing account remains recorded until the replacement is ready.'));
        return new RedirectResponse(Url::fromRoute('myeventlane_vendor.stripe_reconnect', [], [
          'query' => ['return_to' => '/vendor/payments'],
        ])->toString());
      }

      if ($destination === StripeService::MANAGE_DEST_ONBOARDING) {
        $logger->notice('Stripe manage: resume onboarding, store @sid, uid @uid, type @type, reason @reason', [
          '@sid' => (string) $store->id(),
          '@uid' => (string) $currentUser->id(),
          '@type' => $accountType,
          '@reason' => $reason !== '' ? $reason : 'incomplete',
        ]);
        $this->messenger()->addWarning($this->t('Stripe onboarding is not complete enough to open the dashboard yet. Continue setup in Connect.'));
        return new RedirectResponse(Url::fromRoute('myeventlane_vendor.stripe_connect')->toString());
      }

      if ($destination === StripeService::MANAGE_DEST_UNSUPPORTED) {
        $logger->warning('Stripe manage: unsupported destination, store @sid, uid @uid, type @type, reason @reason', [
          '@sid' => (string) $store->id(),
          '@uid' => (string) $currentUser->id(),
          '@type' => $accountType,
          '@reason' => $reason !== '' ? $reason : 'unknown',
        ]);
        $this->messenger()->addError($this->t('We could not open Stripe management. We could not confirm which Stripe dashboard this account uses. Please try again or contact support.'));
        return $this->redirectToVendorSettings();
      }

      if ($destination === StripeService::MANAGE_DEST_STRIPE_DASHBOARD) {
        $url = StripeService::VENDOR_STRIPE_DASHBOARD_URL;
        $response = new TrustedRedirectResponse($url);
        $response->setTrustedTargetUrl($url);
        $this->applyOffsiteStripeRedirectHeaders($response);
        return $response;
      }

      $logger->error('Stripe manage: unhandled destination, store @sid, uid @uid, destination @destination', [
        '@sid' => (string) $store->id(),
        '@uid' => (string) $currentUser->id(),
        '@destination' => $destination,
      ]);
      $this->messenger()->addError($this->t('We could not open Stripe management. Please try again or contact support.'));
      return $this->redirectToVendorSettings();
    }
    catch (ApiErrorException $e) {
      if ($vendor instanceof Vendor) {
        $this->logConnectApiError($e, $vendor, $store, $accountId);
      }
      else {
        $logger->error('Stripe manage API: uid @uid, store @sid, type @t', [
          '@uid' => (string) $currentUser->id(),
          '@sid' => (string) $store->id(),
          '@t' => (string) ($e->getStripeCode() ?? $e->getCode()),
        ]);
      }
      $this->messenger()->addError($this->t('We could not open Stripe management. We could not confirm which Stripe dashboard this account uses. Please try again or contact support.'));
      return $this->redirectToVendorSettings();
    }
    catch (\Exception $e) {
      $logger->error('Stripe manage: store @sid, uid @uid, @m', [
        '@sid' => (string) $store->id(),
        '@uid' => (string) $currentUser->id(),
        '@m' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('We could not open Stripe management. We could not confirm which Stripe dashboard this account uses. Please try again or contact support.'));
      return $this->redirectToVendorSettings();
    }
  }

  /**
   * Opens the previous Express Dashboard during a guarded replacement.
   *
   * The active account remains authoritative until the pending replacement is
   * eligible and promoted. This route never accepts an account ID from the URL
   * and cannot create, promote, disconnect, or delete either account.
   */
  public function managePrevious(): RedirectResponse|TrustedRedirectResponse {
    $logger = $this->loggerChannelFactory->get('myeventlane_vendor');
    $currentUser = $this->currentUser();

    if ($currentUser->isAnonymous()) {
      throw new AccessDeniedHttpException('You must be logged in to manage Stripe.');
    }

    $vendor = $this->getCurrentUserVendor();
    $store = $this->getStoreForConnect($vendor);
    if (!$store) {
      $logger->warning('Previous Stripe manage: no store for user @uid', [
        '@uid' => (string) $currentUser->id(),
      ]);
      $this->messenger()->addError($this->t('No store found for your account.'));
      return $this->redirectToDashboard();
    }
    if ($vendor) {
      $this->syncVendorStoreReference($vendor, $store);
    }

    $previousAccountId = $this->resolveValidatedAccountId(NULL, $store);
    $pendingAccountId = $this->resolvePendingReplacementAccountId($store);
    if ($previousAccountId === NULL || $pendingAccountId === NULL || $previousAccountId === $pendingAccountId) {
      $logger->warning('Previous Stripe manage: no distinct pending replacement for store @sid, uid @uid', [
        '@sid' => (string) $store->id(),
        '@uid' => (string) $currentUser->id(),
      ]);
      $this->messenger()->addWarning($this->t('A previous Stripe account is available here only while a replacement connection is in progress.'));
      return $this->redirectToPayments();
    }

    try {
      $loginLink = $this->stripeService->createLoginLinkIfEligible($previousAccountId);
      if ($loginLink === NULL || empty($loginLink->url) || !is_string($loginLink->url)) {
        $logger->warning('Previous Stripe manage: account is not eligible for Express Dashboard access, store @sid, uid @uid', [
          '@sid' => (string) $store->id(),
          '@uid' => (string) $currentUser->id(),
        ]);
        $this->messenger()->addError($this->t('We could not open the previous Stripe account. Sign in to Stripe directly or contact support.'));
        return $this->redirectToPayments();
      }

      $url = (string) $loginLink->url;
      if (!str_starts_with($url, 'https://connect.stripe.com')) {
        $logger->error('Previous Stripe manage: unexpected login-link host for store @sid, uid @uid', [
          '@sid' => (string) $store->id(),
          '@uid' => (string) $currentUser->id(),
        ]);
        $this->messenger()->addError($this->t('Stripe returned an invalid account link. Please try again or contact support.'));
        return $this->redirectToPayments();
      }

      $response = new TrustedRedirectResponse($url);
      $response->setTrustedTargetUrl($url);
      $this->applyOffsiteStripeRedirectHeaders($response);
      return $response;
    }
    catch (ApiErrorException $e) {
      if ($vendor instanceof Vendor) {
        $this->logConnectApiError($e, $vendor, $store, $previousAccountId);
      }
      $this->messenger()->addError($this->t('We could not open the previous Stripe account. Please try again or contact support.'));
      return $this->redirectToPayments();
    }
    catch (\Throwable $e) {
      $logger->error('Previous Stripe manage failed for store @sid, uid @uid: @message', [
        '@sid' => (string) $store->id(),
        '@uid' => (string) $currentUser->id(),
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('We could not open the previous Stripe account. Please try again or contact support.'));
      return $this->redirectToPayments();
    }
  }

}
