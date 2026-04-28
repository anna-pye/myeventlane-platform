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
 * Controller for Stripe Connect Express onboarding and management.
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
   * Starts or resumes Stripe Connect Express onboarding (Account Links).
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
      $this->messenger()->addError($this->t('No vendor profile found for your account. Please contact support.'));
      return $this->redirectToDashboard();
    }

    $store = $this->getStoreForConnect($vendor);
    if (!$store) {
      $log->error('Stripe connect: no store for vendor @vid uid @uid', [
        '@vid' => (string) $vendor->id(),
        '@uid' => (string) $currentUser->id(),
      ]);
      $this->messenger()->addError($this->t('Your vendor store is not ready yet. Complete vendor onboarding, then return to connect Stripe.'));
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
      $this->messenger()->addError($this->t('Stripe account mismatch. Please start Connect again or contact support.'));
      return $this->redirectToDashboard();
    }

    if (!empty($accountId)) {
      try {
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
        $this->messenger()->addError($this->t('Stripe is not configured for this environment. The platform secret key is missing. Set MEL_STRIPE_SECRET_KEY in the web PHP process (for example the PHP-FPM pool, your hosting environment variables, or nginx/httpd fastcgi_param for PHP)—not only in an SSH session—then restart PHP or the web server, run drush cr, and try again. For DDEV, set web_environment in a gitignored .ddev/config.local.yaml.'));
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

    $destination = $request->query->get('destination');
    $dest = is_string($destination) ? $destination : '';
    if ($dest !== '' && !str_starts_with($dest, '/')) {
      $dest = '';
    }

    $vendor = $this->getCurrentUserVendor();
    if (!$vendor) {
      $this->messenger()->addError($this->t('No vendor profile found for your account.'));
      return $this->redirectToDashboard();
    }

    $store = $this->getStoreForConnect($vendor);
    if (!$store) {
      $this->messenger()->addError($this->t('No store found for your account.'));
      return $this->redirectToDashboard();
    }
    $this->syncVendorStoreReference($vendor, $store);

    $qid = $request->query->get('account_id');
    $qidStr = is_string($qid) ? $qid : '';
    $accountId = $this->resolveValidatedAccountId($qidStr !== '' ? $qidStr : NULL, $store);
    if ($accountId === NULL) {
      if ($qidStr !== '') {
        $this->messenger()->addError($this->t('Stripe account mismatch. Please use Connect from your dashboard again.'));
      }
      else {
        $this->messenger()->addWarning($this->t('Stripe account not found. Please start the connection process again.'));
      }
      return new RedirectResponse(Url::fromRoute('myeventlane_vendor.stripe_connect', [], [
        'query' => $dest ? ['destination' => $dest] : [],
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
      $this->messenger()->addError($this->t('Failed to verify Stripe account status. Please try again.'));
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
      $this->messenger()->addWarning($this->t('Stripe account status: @status.', [
        '@status' => (string) ($status['status'] ?? 'unknown'),
      ]));
    }

    if ($dest !== '') {
      return new RedirectResponse($dest);
    }
    return $this->redirectToDashboard();
  }

  /**
   * Login link to the vendor’s Express Stripe Dashboard.
   */
  public function manage(): RedirectResponse {
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
      $loginLink = $this->stripeService->createLoginLinkIfEligible($accountId);
      if ($loginLink === NULL) {
        $eligibility = $this->stripeService->validateAccountDashboardEligibility($accountId);
        $reason = (string) ($eligibility['reason'] ?? 'Unknown');
        $logger->notice('Stripe manage: ineligible for login link, account @acct, reason @reason', [
          '@acct' => StripeService::maskAccountId($accountId),
          '@reason' => $reason,
        ]);
        $this->messenger()->addWarning($this->t('Stripe onboarding is not complete enough to open the dashboard yet. Continue setup in Connect.'));
        return new RedirectResponse(Url::fromRoute('myeventlane_vendor.stripe_connect')->toString());
      }

      if (empty($loginLink->url)) {
        $this->messenger()->addError($this->t('Failed to generate a Stripe link. Please try again.'));
        return $this->redirectToDashboard();
      }

      $url = (string) $loginLink->url;
      if (!str_starts_with($url, 'https://connect.stripe.com') && !str_starts_with($url, 'https://dashboard.stripe.com')) {
        $logger->error('Stripe manage: unexpected host for login link, account @acct', [
          '@acct' => StripeService::maskAccountId($accountId),
        ]);
        $this->messenger()->addError($this->t('Invalid Stripe link. Please try again or contact support.'));
        return $this->redirectToDashboard();
      }

      $response = new TrustedRedirectResponse($url);
      $response->setTrustedTargetUrl($url);
      $this->applyOffsiteStripeRedirectHeaders($response);
      return $response;
    }
    catch (ApiErrorException $e) {
      if ($vendor instanceof Vendor) {
        $this->logConnectApiError($e, $vendor, $store, $accountId);
      }
      else {
        $logger->error('Stripe manage API: uid @uid, type @t', [
          '@uid' => (string) $currentUser->id(),
          '@t' => (string) ($e->getStripeCode() ?? $e->getCode()),
        ]);
      }
      $this->messenger()->addError($this->t('We could not open your Stripe account. Try again in a few minutes, or open Stripe from the Connect link if setup is still in progress.'));
      return $this->redirectToDashboard();
    }
    catch (\Exception $e) {
      $logger->error('Stripe manage: @m', [
        '@m' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Failed to open Stripe. Please try again or contact support.'));
      return $this->redirectToDashboard();
    }
  }

}
