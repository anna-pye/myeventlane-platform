<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\StripeService;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Controller for Stripe Connect onboarding and management.
 */
final class StripeConnectController extends ControllerBase {

  /**
   * The Stripe service.
   */
  private readonly StripeService $stripeService;

  /**
   * Constructs a StripeConnectController.
   *
   * @param \Drupal\myeventlane_core\Service\StripeService $stripeService
   *   The Stripe service.
   */
  public function __construct(StripeService $stripeService) {
    $this->stripeService = $stripeService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.stripe'),
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
    if ($vendor && $vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
      $store = $vendor->get('field_vendor_store')->entity;
      if ($store instanceof StoreInterface) {
        return $store;
      }
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

    // Fallback: try to find default store or any store.
    // In a multi-vendor setup, this might not be ideal, but provides fallback.
    $defaultStores = $storeStorage->loadByProperties(['is_default' => TRUE]);
    if (!empty($defaultStores)) {
      return reset($defaultStores);
    }

    return NULL;
  }

  /**
   * Builds absolute return and refresh URLs for Stripe AccountLink.
   *
   * return_url: Stripe redirects here after onboarding. refresh_url: Stripe uses
   * if the link expires. Both always carry the same `destination` query
   * parameter (may be empty) for callback/refresh continuity.
   */
  private function buildAccountLinkUrls(string $destination = ''): array {
    $returnUrl = Url::fromRoute('myeventlane_vendor.stripe_onboard_return', [], [
      'absolute' => TRUE,
      'query' => ['destination' => $destination],
    ])->toString();
    $refreshUrl = Url::fromRoute('myeventlane_vendor.stripe_onboard_refresh', [], [
      'absolute' => TRUE,
      'query' => ['destination' => $destination],
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
    $vendorIds = $vendorStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $userId)
      ->range(0, 1)
      ->execute();

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
   * Starts Stripe Connect onboarding.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to Stripe onboarding or dashboard.
   */
  public function connect(): RedirectResponse {
    \Drupal::logger('mel_debug')->notice('STRIPE CONNECT HIT');
    $currentUser = $this->currentUser();
    if ($currentUser->isAnonymous()) {
      throw new AccessDeniedHttpException('You must be logged in to connect Stripe.');
    }

    $request = \Drupal::request();
    $destination = $request->query->get('destination');

    $store = $this->getStoreForConnect();
    if (!$store) {
      $this->messenger()->addError($this->t('No store found for your account. Please contact support.'));
      if ($destination) {
        return new RedirectResponse($destination);
      }
      return new RedirectResponse(Url::fromRoute('myeventlane_vendor.console.dashboard')->toString());
    }

    $accountId = NULL;
    if ($store->hasField('field_stripe_account_id') && !$store->get('field_stripe_account_id')->isEmpty()) {
      $accountId = $store->get('field_stripe_account_id')->value;
    }

    // If account exists, refresh status from Stripe. Store may have account_id but
    // missing field_stripe_charges_enabled (e.g. callback never ran). Event flow
    // checks charges_enabled, so we must align.
    if (!empty($accountId)) {
      try {
        $status = $this->stripeService->getAccountStatus($accountId);
        if ($store->hasField('field_stripe_status')) {
          $store->set('field_stripe_status', $status['status']);
        }
        if ($store->hasField('field_stripe_connected')) {
          // Aligned with assertStripeConnected: seller-ready when charges are on.
          $store->set('field_stripe_connected', (bool) $status['charges_enabled']);
        }
        if ($store->hasField('field_stripe_charges_enabled')) {
          $store->set('field_stripe_charges_enabled', $status['charges_enabled']);
        }
        if ($store->hasField('field_stripe_payouts_enabled')) {
          $store->set('field_stripe_payouts_enabled', $status['payouts_enabled']);
        }
        $store->save();

        if ($status['charges_enabled']) {
          $this->messenger()->addStatus($this->t('Stripe is already connected.'));
          if ($destination) {
            return new RedirectResponse($destination);
          }
          return new RedirectResponse(Url::fromRoute('myeventlane_vendor.console.dashboard')->toString());
        }
      }
      catch (\Exception $e) {
        $this->getLogger('myeventlane_vendor')->warning('Could not refresh Stripe status, will create AccountLink: @m', ['@m' => $e->getMessage()]);
      }
    }

    try {
      $userEmail = $currentUser->getEmail();
      if (empty($userEmail)) {
        $this->messenger()->addError($this->t('Your account must have an email address to connect Stripe.'));
        return new RedirectResponse(Url::fromRoute('myeventlane_vendor.console.dashboard')->toString());
      }

      if (empty($accountId)) {
        // Create new Connect account.
        $account = $this->stripeService->createConnectAccount($userEmail, 'AU', 'standard');
        $accountId = $account->id;

        // Save account ID to store.
        if ($store->hasField('field_stripe_account_id')) {
          $store->set('field_stripe_account_id', $accountId);
          if ($store->hasField('field_stripe_status')) {
            $store->set('field_stripe_status', 'pending');
          }
          if ($store->hasField('field_stripe_connected')) {
            $store->set('field_stripe_connected', FALSE);
          }
          $store->save();
        }

        // Also save to vendor entity if it exists.
        $vendor = $this->getCurrentUserVendor();
        if ($vendor && $vendor->hasField('field_stripe_account_id')) {
          $vendor->set('field_stripe_account_id', $accountId);
          if ($vendor->hasField('field_stripe_status')) {
            $vendor->set('field_stripe_status', 'pending');
          }
          $vendor->save();
        }
      }

      // Create AccountLink: return + refresh are absolute, with optional
      // ?destination=… so the callback can redirect to /create-event, onboard, etc.
      $reqQuery = \Drupal::request();
      $destination = $reqQuery->query->get('destination');
      $destStr = is_string($destination) ? $destination : '';

      [$returnUrl, $refreshUrl] = $this->buildAccountLinkUrls($destStr);
      $accountLink = $this->stripeService->createAccountLink($accountId, $returnUrl, $refreshUrl);
      if (empty($accountLink->url) || !str_starts_with($accountLink->url, 'https://connect.stripe.com')) {
        $this->getLogger('myeventlane_vendor')->error('Invalid Stripe account link URL: @url', [
          '@url' => $accountLink->url ?? '',
        ]);
        throw new \RuntimeException('Invalid Stripe account link: ' . ($accountLink->url ?? ''));
      }
      \Drupal::logger('mel_debug')->notice('Stripe URL: @url', [
        '@url' => $accountLink->url,
      ]);

      // Off-site: SecuredRedirectResponse::setTargetUrl() only allows external
      // URLs that TrustedRedirectResponse marks as trusted; setTrustedTargetUrl()
      // registers the final URL after any normalization.
      $response = new TrustedRedirectResponse($accountLink->url);
      $response->setTrustedTargetUrl($accountLink->url);
      $this->applyOffsiteStripeRedirectHeaders($response);
      return $response;
    }
    catch (\Exception $e) {
      if ($e instanceof \RuntimeException && str_contains($e->getMessage(), 'Invalid Stripe account link')) {
        throw $e;
      }
      $this->getLogger('myeventlane_vendor')->error('Stripe Connect onboarding failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Failed to start Stripe onboarding. Please try again or contact support.'));
      if (!empty($destination) && is_string($destination)) {
        return new RedirectResponse($destination);
      }
      return new RedirectResponse(Url::fromRoute('myeventlane_vendor.console.dashboard')->toString());
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
    \Drupal::logger('mel_debug')->notice('STRIPE CALLBACK HIT');
    $currentUser = $this->currentUser();
    if ($currentUser->isAnonymous()) {
      throw new AccessDeniedHttpException('You must be logged in.');
    }

    $destination = $request->query->get('destination');

    $store = $this->getStoreForConnect();
    if (!$store) {
      $this->messenger()->addError($this->t('No store found for your account.'));
      // Redirect to destination if provided, otherwise dashboard.
      if ($destination) {
        return new RedirectResponse($destination);
      }
      return new RedirectResponse(Url::fromRoute('myeventlane_vendor.console.dashboard')->toString());
    }

    // Check if account ID exists.
    if (!$store->hasField('field_stripe_account_id') || $store->get('field_stripe_account_id')->isEmpty()) {
      $this->messenger()->addWarning($this->t('Stripe account not found. Please start the connection process again.'));
      return new RedirectResponse(Url::fromRoute('myeventlane_vendor.stripe_connect')->toString());
    }

    $accountId = $store->get('field_stripe_account_id')->value;

    try {
      // Get account status from Stripe.
      $status = $this->stripeService->getAccountStatus($accountId);

      // Update store with status.
      if ($store->hasField('field_stripe_status')) {
        $store->set('field_stripe_status', $status['status']);
      }
      if ($store->hasField('field_stripe_connected')) {
        $store->set('field_stripe_connected', (bool) $status['charges_enabled']);
      }
      if ($store->hasField('field_stripe_charges_enabled')) {
        $store->set('field_stripe_charges_enabled', $status['charges_enabled']);
      }
      if ($store->hasField('field_stripe_payouts_enabled')) {
        $store->set('field_stripe_payouts_enabled', $status['payouts_enabled']);
      }
      $store->save();
      \Drupal::logger('mel_debug')->notice('STRIPE CALLBACK: store saved; field_stripe_connected=@c charges_enabled=@ch payouts=@p', [
        '@c' => $store->hasField('field_stripe_connected') && !$store->get('field_stripe_connected')->isEmpty()
          ? (string) (int) (bool) $store->get('field_stripe_connected')->value
          : 'n/a',
        '@ch' => (string) (int) (bool) ($status['charges_enabled'] ?? FALSE),
        '@p' => (string) (int) (bool) ($status['payouts_enabled'] ?? FALSE),
      ]);

      // Also update vendor entity if it exists.
      $vendor = $this->getCurrentUserVendor();
      if ($vendor) {
        if ($vendor->hasField('field_stripe_account_id')) {
          $vendor->set('field_stripe_account_id', $accountId);
        }
        if ($vendor->hasField('field_stripe_status')) {
          $vendor->set('field_stripe_status', $status['status']);
        }
        if ($vendor->hasField('field_stripe_connected')) {
          $vendor->set('field_stripe_connected', (bool) $status['charges_enabled']);
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
    catch (\Exception $e) {
      $this->getLogger('myeventlane_vendor')->error('Stripe Connect callback failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Failed to verify Stripe account status. Please try again.'));
    }

    // Redirect to destination if provided (e.g., onboarding flow), otherwise dashboard.
    if ($destination) {
      return new RedirectResponse($destination);
    }
    return new RedirectResponse(Url::fromRoute('myeventlane_vendor.console.dashboard')->toString());
  }

  /**
   * Creates a login link to Stripe dashboard for the vendor.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to Stripe dashboard or error.
   */
  public function manage(): RedirectResponse {
    $logger = $this->getLogger('myeventlane_vendor');
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
      return new RedirectResponse(Url::fromRoute('myeventlane_vendor.console.dashboard')->toString());
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
        return new RedirectResponse(Url::fromRoute('myeventlane_vendor.console.dashboard')->toString());
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
      return new RedirectResponse(Url::fromRoute('myeventlane_vendor.console.dashboard')->toString());
    }
  }

}
