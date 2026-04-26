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
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Stripe\Exception\ApiErrorException;

/**
 * Controller for Stripe Connect onboarding and management.
 */
final class StripeConnectController extends ControllerBase {

  /**
   * The Stripe service.
   */
  private readonly StripeService $stripeService;

  /**
   * The vendor store subscriber/service.
   */
  private readonly VendorStoreSubscriber $vendorStoreSubscriber;

  /**
   * The logger channel factory.
   */
  private readonly LoggerChannelFactoryInterface $loggerChannelFactory;

  /**
   * Constructs a StripeConnectController.
   *
   * @param \Drupal\myeventlane_core\Service\StripeService $stripeService
   *   The Stripe service.
   * @param \Drupal\myeventlane_vendor\EventSubscriber\VendorStoreSubscriber $vendorStoreSubscriber
   *   The vendor store subscriber/service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger channel factory.
   */
  public function __construct(StripeService $stripeService, VendorStoreSubscriber $vendorStoreSubscriber, LoggerChannelFactoryInterface $loggerFactory) {
    $this->stripeService = $stripeService;
    $this->vendorStoreSubscriber = $vendorStoreSubscriber;
    $this->loggerChannelFactory = $loggerFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.stripe'),
      $container->get('myeventlane_vendor.vendor_store_subscriber'),
      $container->get('logger.factory'),
    );
  }

  /**
   * Gets the store to use for Stripe Connect.
   *
   * Prefers the vendor's field_vendor_store (same store assertStripeConnected
   * uses) so connect and event flow stay aligned.
   *
   * @return \Drupal\commerce_store\Entity\StoreInterface|null
   *   The store entity, or NULL if not found.
   */
  private function getStoreForConnect(): ?StoreInterface {
    $vendor = $this->getCurrentUserVendor();
    if ($vendor) {
      return $this->vendorStoreSubscriber->ensureStoreForVendor($vendor);
    }
    return $this->getCurrentUserStore();
  }

  /**
   * Gets the store for the current user (by uid).
   *
   * @return \Drupal\commerce_store\Entity\StoreInterface|null
   *   The store entity, or NULL if not found.
   */
  private function getCurrentUserStore(): ?StoreInterface {
    $currentUser = $this->currentUser();
    $userId = (int) $currentUser->id();

    if ($userId === 0) {
      return NULL;
    }

    // Try to find a store owned by this user.
    $storeStorage = $this->entityTypeManager()->getStorage('commerce_store');
    $storeIds = $storeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $userId)
      ->range(0, 1)
      ->execute();

    if (!empty($storeIds)) {
      $store = $storeStorage->load(reset($storeIds));
      if ($store instanceof StoreInterface) {
        return $store;
      }
    }

    return NULL;
  }

  /**
   * Ensures the vendor points at the same Commerce store Stripe updates.
   */
  private function syncVendorStoreReference(Vendor $vendor, StoreInterface $store): void {
    if (!$vendor->hasField('field_vendor_store')) {
      return;
    }

    $currentTargetId = !$vendor->get('field_vendor_store')->isEmpty()
      ? (string) $vendor->get('field_vendor_store')->target_id
      : '';
    if ($currentTargetId === (string) $store->id()) {
      return;
    }

    $vendor->set('field_vendor_store', $store->id());
    $vendor->save();
  }

  /**
   * Builds absolute return and refresh URLs for Stripe AccountLink.
   *
   * return_url: Stripe redirects here after onboarding. refresh_url points back
   * to the connect route if the AccountLink expires. Both carry account_id so
   * the callback can verify the exact Stripe account with the platform API.
   */
  private function buildAccountLinkUrls(string $accountId, string $destination = ''): array {
    $query = ['account_id' => $accountId];
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

  /**
   * Gets the vendor entity for the current user.
   *
   * @return \Drupal\myeventlane_vendor\Entity\Vendor|null
   *   The vendor entity, or NULL if not found.
   */
  private function getCurrentUserVendor(): ?Vendor {
    $currentUser = $this->currentUser();
    $userId = (int) $currentUser->id();

    if ($userId === 0) {
      return NULL;
    }

    $vendorStorage = $this->entityTypeManager()->getStorage('myeventlane_vendor');
    $query = $vendorStorage->getQuery()
      ->accessCheck(FALSE)
      ->range(0, 1);
    $group = $query->orConditionGroup()
      ->condition('uid', $userId)
      ->condition('field_vendor_users', $userId);
    $vendorIds = $query->condition($group)->execute();

    if (!empty($vendorIds)) {
      $vendor = $vendorStorage->load(reset($vendorIds));
      if ($vendor instanceof Vendor) {
        return $vendor;
      }
    }

    return NULL;
  }

  /**
   * Disables edge caching of off-site Stripe redirect responses.
   */
  private function applyOffsiteStripeRedirectHeaders(TrustedRedirectResponse $response): TrustedRedirectResponse {
    $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
    $response->headers->set('Pragma', 'no-cache');
    return $response;
  }

  /**
   * Builds a redirect response back to the vendor dashboard.
   */
  private function redirectToDashboard(): RedirectResponse {
    return new RedirectResponse(Url::fromRoute('myeventlane_vendor.console.dashboard')->toString());
  }

  /**
   * Starts Stripe Connect onboarding.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to Stripe onboarding or dashboard.
   */
  public function connect(Request $request): RedirectResponse {
    $this->loggerChannelFactory->get('stripe_debug')->notice('Stripe connect route hit');
    $this->loggerChannelFactory->get('mel_debug')->notice('STRIPE CONNECT HIT');
    $currentUser = $this->currentUser();
    if ($currentUser->isAnonymous()) {
      throw new AccessDeniedHttpException('You must be logged in to connect Stripe.');
    }

    $vendor = $this->getCurrentUserVendor();
    if (!$vendor) {
      $this->loggerChannelFactory->get('stripe_debug')->error('Stripe connect failed: no vendor entity for uid @uid', [
        '@uid' => (string) $currentUser->id(),
      ]);
      $this->messenger()->addError($this->t('No vendor profile found for your account. Please contact support.'));
      return $this->redirectToDashboard();
    }

    $store = $this->getStoreForConnect();
    if (!$store) {
      $this->loggerChannelFactory->get('stripe_debug')->error('Stripe connect failed: no store for vendor @vendor_id uid @uid', [
        '@vendor_id' => (string) $vendor->id(),
        '@uid' => (string) $currentUser->id(),
      ]);
      $this->messenger()->addError($this->t('No store found for your account. Please contact support.'));
      return $this->redirectToDashboard();
    }
    $this->syncVendorStoreReference($vendor, $store);

    try {
      $accountId = $this->stripeService->getOrCreateAccount($vendor, $store);
      $this->loggerChannelFactory->get('stripe_debug')->notice('Account ID: @id', ['@id' => $accountId]);

      if ($accountId === '') {
        throw new \RuntimeException('Stripe account ID missing');
      }

      try {
        $status = $this->stripeService->getAccountStatus($accountId);
      }
      catch (ApiErrorException $e) {
        $this->loggerChannelFactory->get('stripe_error')->error($e->getMessage());
        throw $e;
      }

      if (empty($status)) {
        $this->loggerChannelFactory->get('stripe_debug')->error('Missing account status');
        return $this->redirectToDashboard();
      }

      $connected = (bool) ($status['details_submitted'] && $status['charges_enabled']);
      if ($store->hasField('field_stripe_status')) {
        $store->set('field_stripe_status', $status['status']);
      }
      if ($store->hasField('field_stripe_connected')) {
        $store->set('field_stripe_connected', $connected);
      }
      if ($store->hasField('field_stripe_charges_enabled')) {
        $store->set('field_stripe_charges_enabled', $status['charges_enabled']);
      }
      if ($store->hasField('field_stripe_payouts_enabled')) {
        $store->set('field_stripe_payouts_enabled', $status['payouts_enabled']);
      }
      $store->save();

      if ($connected) {
        $this->messenger()->addStatus($this->t('Stripe is already connected.'));
        return $this->redirectToDashboard();
      }

      // Create AccountLink: return + refresh are absolute and include account_id
      // so the callback can verify the same Express account after Stripe returns.
      $destination = $request->query->get('destination');
      $destStr = is_string($destination) ? $destination : '';

      [$returnUrl, $refreshUrl] = $this->buildAccountLinkUrls($accountId, $destStr);
      try {
        $accountLink = $this->stripeService->createAccountLink($accountId, $returnUrl, $refreshUrl);
      }
      catch (ApiErrorException $e) {
        $this->loggerChannelFactory->get('stripe_error')->error($e->getMessage());
        $this->loggerChannelFactory->get('stripe_debug')->error('Stripe error: @msg', [
          '@msg' => $e->getMessage(),
        ]);
        throw $e;
      }

      $this->loggerChannelFactory->get('stripe_debug')->notice('AccountLink URL: @url', [
        '@url' => $accountLink->url ?? 'NULL',
      ]);

      if (empty($accountLink->url)) {
        throw new \Exception('Stripe AccountLink URL missing');
      }

      $url = (string) $accountLink->url;
      $this->loggerChannelFactory->get('mel_debug')->notice('Stripe redirecting to: @url', [
        '@url' => $url,
      ]);

      $response = new TrustedRedirectResponse($url);
      $response->setTrustedTargetUrl($url);
      $this->applyOffsiteStripeRedirectHeaders($response);
      return $response;
    }
    catch (\Exception $e) {
      $this->loggerChannelFactory->get('stripe_debug')->error('Stripe connect failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->loggerChannelFactory->get('myeventlane_vendor')->error('Stripe Connect onboarding failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Handles Stripe Connect callback after onboarding.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to vendor dashboard.
   */
  public function callback(Request $request): RedirectResponse {
    $this->loggerChannelFactory->get('mel_debug')->notice('STRIPE CALLBACK HIT');
    $currentUser = $this->currentUser();
    if ($currentUser->isAnonymous()) {
      throw new AccessDeniedHttpException('You must be logged in.');
    }

    $store = $this->getStoreForConnect();
    if (!$store) {
      $this->messenger()->addError($this->t('No store found for your account.'));
      return $this->redirectToDashboard();
    }
    $vendor = $this->getCurrentUserVendor();
    if ($vendor) {
      $this->syncVendorStoreReference($vendor, $store);
    }

    $queryAccountId = $request->query->get('account_id');
    $accountId = is_string($queryAccountId) && str_starts_with($queryAccountId, 'acct_')
      ? $queryAccountId
      : NULL;
    if ($accountId === NULL && $store->hasField('field_stripe_account_id') && !$store->get('field_stripe_account_id')->isEmpty()) {
      $accountId = trim((string) $store->get('field_stripe_account_id')->value);
    }

    // Check if account ID exists.
    if (empty($accountId)) {
      $this->loggerChannelFactory->get('stripe_debug')->error('Stripe callback missing account_id');
      $this->messenger()->addWarning($this->t('Stripe account not found. Please start the connection process again.'));
      return new RedirectResponse(Url::fromRoute('myeventlane_vendor.stripe_connect')->toString());
    }

    try {
      // Get account status from Stripe.
      $status = $this->stripeService->getAccountStatus($accountId);
      if (empty($status)) {
        $this->loggerChannelFactory->get('stripe_debug')->error('Missing account status');
        return $this->redirectToDashboard();
      }
      $connected = (bool) ($status['details_submitted'] && $status['charges_enabled']);

      // Update store with status.
      if ($store->hasField('field_stripe_account_id')) {
        $store->set('field_stripe_account_id', $accountId);
      }
      if ($store->hasField('field_stripe_status')) {
        $store->set('field_stripe_status', $status['status']);
      }
      if ($store->hasField('field_stripe_connected')) {
        $store->set('field_stripe_connected', $connected);
      }
      if ($store->hasField('field_stripe_charges_enabled')) {
        $store->set('field_stripe_charges_enabled', $status['charges_enabled']);
      }
      if ($store->hasField('field_stripe_payouts_enabled')) {
        $store->set('field_stripe_payouts_enabled', $status['payouts_enabled']);
      }
      $store->save();
      $this->loggerChannelFactory->get('stripe_debug')->notice('Stripe saved to store @id', ['@id' => (string) $store->id()]);
      $this->loggerChannelFactory->get('mel_debug')->notice('STRIPE CALLBACK: store saved; field_stripe_connected=@c charges_enabled=@ch details_submitted=@d payouts=@p', [
        '@c' => $store->hasField('field_stripe_connected') && !$store->get('field_stripe_connected')->isEmpty()
          ? (string) (int) (bool) $store->get('field_stripe_connected')->value
          : 'n/a',
        '@ch' => (string) (int) (bool) ($status['charges_enabled'] ?? FALSE),
        '@d' => (string) (int) (bool) ($status['details_submitted'] ?? FALSE),
        '@p' => (string) (int) (bool) ($status['payouts_enabled'] ?? FALSE),
      ]);

      // Also update vendor entity if it exists.
      if ($vendor) {
        if ($vendor->hasField('field_stripe_account_id')) {
          $vendor->set('field_stripe_account_id', $accountId);
        }
        if ($vendor->hasField('field_stripe_status')) {
          $vendor->set('field_stripe_status', $status['status']);
        }
        if ($vendor->hasField('field_stripe_connected')) {
          $vendor->set('field_stripe_connected', $connected);
        }
        $vendor->save();
      }

      if ($status['status'] === 'complete') {
        $this->messenger()->addStatus($this->t('Stripe account connected successfully! You can now accept payments.'));
      }
      elseif ($status['status'] === 'pending') {
        $this->messenger()->addWarning($this->t('Stripe account is pending. Please complete the onboarding process.'));
      }
      else {
        $this->messenger()->addWarning($this->t('Stripe account status: @status. Some features may be limited.', [
          '@status' => $status['status'],
        ]));
      }
    }
    catch (ApiErrorException $e) {
      $this->loggerChannelFactory->get('stripe_error')->error($e->getMessage());
      $this->loggerChannelFactory->get('myeventlane_vendor')->error('Stripe Connect callback failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Failed to verify Stripe account status. Please try again.'));
    }
    catch (\Exception $e) {
      $this->loggerChannelFactory->get('myeventlane_vendor')->error('Stripe Connect callback failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Failed to verify Stripe account status. Please try again.'));
    }

    return $this->redirectToDashboard();
  }

  /**
   * Creates a login link to Stripe dashboard for the vendor.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to Stripe dashboard or error.
   */
  public function manage(): RedirectResponse {
    $logger = $this->loggerChannelFactory->get('myeventlane_vendor');
    $currentUser = $this->currentUser();

    if ($currentUser->isAnonymous()) {
      throw new AccessDeniedHttpException('You must be logged in to manage Stripe.');
    }

    $store = $this->getStoreForConnect();
    if (!$store) {
      $logger->warning('Stripe manage: No store found for user @uid', [
        '@uid' => $currentUser->id(),
      ]);
      $this->messenger()->addError($this->t('No store found for your account.'));
      return $this->redirectToDashboard();
    }

    // VALIDATE STRIPE ACCOUNT ID: Check if account ID exists and is valid.
    $accountId = NULL;
    if ($store->hasField('field_stripe_account_id') && !$store->get('field_stripe_account_id')->isEmpty()) {
      $accountId = trim($store->get('field_stripe_account_id')->value);
    }

    // If account ID is missing or empty, do NOT attempt link creation.
    if (empty($accountId)) {
      $logger->warning('Stripe manage: Missing or empty account ID for user @uid, store @store_id', [
        '@uid' => $currentUser->id(),
        '@store_id' => $store->id(),
      ]);
      $this->messenger()->addWarning($this->t('Stripe is not connected. Please connect your Stripe account first.'));
      return new RedirectResponse(Url::fromRoute('myeventlane_vendor.stripe_connect')->toString());
    }

    // Validate account ID format (should start with 'acct_').
    if (!str_starts_with($accountId, 'acct_')) {
      $logger->error('Stripe manage: Invalid account ID format for user @uid, account_id: @account_id', [
        '@uid' => $currentUser->id(),
        '@account_id' => $accountId,
      ]);
      $this->messenger()->addError($this->t('Invalid Stripe account ID. Please reconnect your Stripe account.'));
      return new RedirectResponse(Url::fromRoute('myeventlane_vendor.stripe_connect')->toString());
    }

    // Attempt to create login link only if account is eligible.
    // This method validates eligibility BEFORE calling Stripe API to prevent errors.
    try {
      $logger->info('Stripe manage: Checking eligibility and creating login link for account @account_id, user @uid', [
        '@account_id' => $accountId,
        '@uid' => $currentUser->id(),
      ]);

      $loginLink = $this->stripeService->createLoginLinkIfEligible($accountId);

      // If login link is NULL, account is not eligible.
      if ($loginLink === NULL) {
        // Get eligibility details for logging.
        $eligibility = $this->stripeService->validateAccountDashboardEligibility($accountId);
        $reason = $eligibility['reason'] ?? 'Unknown reason';

        // Log at NOTICE level (eligibility failures are expected for incomplete accounts).
        $logger->notice('Stripe manage: Account @account_id is not eligible for dashboard login link. Reason: @reason', [
          '@account_id' => $accountId,
          '@reason' => $reason,
        ]);

        // Show clear UI message about incomplete onboarding.
        $this->messenger()->addWarning($this->t('Stripe onboarding incomplete. Your account is not yet ready for dashboard access. Please complete the Stripe onboarding process.'));
        return new RedirectResponse(Url::fromRoute('myeventlane_vendor.stripe_connect')->toString());
      }

      if (empty($loginLink->url)) {
        // This is an unexpected error (link created but URL missing).
        $logger->error('Stripe manage: Login link created but URL is empty for account @account_id', [
          '@account_id' => $accountId,
        ]);
        $this->messenger()->addError($this->t('Failed to generate Stripe dashboard link. Please try again.'));
        return $this->redirectToDashboard();
      }

      $logger->info('Stripe manage: Successfully created login link for account @account_id', [
        '@account_id' => $accountId,
      ]);

      $response = new TrustedRedirectResponse($loginLink->url);
      $response->setTrustedTargetUrl($loginLink->url);
      $this->applyOffsiteStripeRedirectHeaders($response);
      return $response;
    }
    catch (\Exception $e) {
      // Unexpected failures during API calls - log as ERROR.
      $error_message = $e->getMessage();
      $logger->error('Stripe manage: Unexpected error creating login link for account @account_id: @message', [
        '@account_id' => $accountId,
        '@message' => $error_message,
      ]);

      $this->messenger()->addError($this->t('Failed to open Stripe dashboard. Please try again or contact support.'));
      return $this->redirectToDashboard();
    }
  }

}
