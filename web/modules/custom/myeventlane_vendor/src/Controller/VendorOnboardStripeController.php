<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\OnboardingManager;
use Drupal\myeventlane_core\Service\StripeService;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for vendor onboarding step 3: Stripe Connect.
 */
final class VendorOnboardStripeController extends ControllerBase {

  /**
   * The Stripe service.
   */
  private readonly StripeService $stripeService;

  /**
   * The onboarding manager.
   */
  private readonly OnboardingManager $onboardingManager;

  /**
   * Constructs a VendorOnboardStripeController.
   */
  public function __construct(
    StripeService $stripe_service,
    OnboardingManager $onboarding_manager,
  ) {
    $this->stripeService = $stripe_service;
    $this->onboardingManager = $onboarding_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.stripe'),
      $container->get('myeventlane_onboarding.manager'),
    );
  }

  /**
   * Step 3: Stripe Connect onboarding.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   Render array or redirect.
   */
  public function stripe(): array|RedirectResponse {
    $currentUser = $this->currentUser();

    if ($currentUser->isAnonymous()) {
      return new RedirectResponse(
        Url::fromRoute('myeventlane_vendor.onboard.account')->toString()
      );
    }

    $vendor = $this->getCurrentUserVendor();
    if (!$vendor) {
      // No vendor yet, go back to profile step.
      return new RedirectResponse(
        Url::fromRoute('myeventlane_vendor.onboard.profile')->toString()
      );
    }

    $store = $this->getCurrentUserStore();
    if (!$store) {
      $this->messenger()->addError($this->t('No store found. Please contact support.'));
      return new RedirectResponse(
        Url::fromRoute('myeventlane_vendor.onboard.profile')->toString()
      );
    }

    // Stripe UI phases: not connected | Connect account incomplete (no charges yet) | ready to sell.
    // charges_enabled is sufficient for event creation (same as assertStripeConnected). Payouts can lag
    // or remain pending without blocking onboarding — do not treat payouts_enabled as the only "done" signal.
    $isConnected = FALSE;
    $payoutsEnabled = FALSE;
    $accountId = '';
    if ($store->hasField('field_stripe_payouts_enabled') && !$store->get('field_stripe_payouts_enabled')->isEmpty()) {
      $payoutsEnabled = (bool) $store->get('field_stripe_payouts_enabled')->value;
    }
    if ($store->hasField('field_stripe_account_id') && !$store->get('field_stripe_account_id')->isEmpty()) {
      $accountId = trim((string) $store->get('field_stripe_account_id')->value);
      if ($accountId !== '') {
        if ($store->hasField('field_stripe_charges_enabled') && !$store->get('field_stripe_charges_enabled')->isEmpty()) {
          $isConnected = (bool) $store->get('field_stripe_charges_enabled')->value;
        }
        if (!$isConnected) {
          try {
            $status = $this->stripeService->getAccountStatus($accountId);
            $isConnected = $status['charges_enabled'];
            if (!$payoutsEnabled) {
              $payoutsEnabled = $status['payouts_enabled'];
            }
          }
          catch (\Exception $e) {
            // API failed; rely on store fields if set.
          }
        }
      }
    }

    // Ready for onboarding: charges enabled OR payouts enabled (either implies seller path is usable).
    $stripe_ready_for_onboarding = $payoutsEnabled || $isConnected;
    $stripe_phase = 'needs_connect';
    if ($stripe_ready_for_onboarding) {
      $stripe_phase = 'payouts_ready';
    }
    elseif ($accountId !== '') {
      $stripe_phase = 'needs_completion';
    }

    $stripe_state = [
      'is_connected' => $accountId !== '',
      'payouts_enabled' => $payoutsEnabled,
      // Step complete when charges are on (matches advanceStage + pre-regression UX), not only payouts.
      'is_onboarding_complete' => $isConnected,
    ];

    $uid = (int) $currentUser->id();

    // Non-blocking: load/refresh onboarding state; advance when Stripe already connected.
    try {
      $user = $this->entityTypeManager()->getStorage('user')->load($uid);
      if ($user instanceof \Drupal\user\UserInterface && $vendor->id()) {
        $state = $this->onboardingManager->loadOrCreateVendor($user, $vendor);
        $this->onboardingManager->refreshFlags($state);
        if ($isConnected) {
          $this->onboardingManager->advanceStage($state, 'ask');
        }
      }
    }
    catch (\Throwable $e) {
      $this->getLogger('myeventlane_vendor')->warning('Onboarding load/refresh/advance failed on stripe step: @m', ['@m' => $e->getMessage()]);
    }

    $content = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-onboard-stripe'],
      ],
    ];

    $connectUrl = Url::fromRoute('myeventlane_vendor.stripe_connect', [], [
      'query' => [
        'destination' => '/create-event',
        't' => (string) time(),
      ],
      'absolute' => TRUE,
    ]);

    $back_url = Url::fromRoute('myeventlane_vendor.onboard.profile')->toString();
    $skip_url = Url::fromRoute('myeventlane_vendor.create_event_gateway')->toString();

    $onboard_footer = [
      'back_url' => $back_url,
      'continue_url' => $connectUrl->toString(),
      'continue_label' => $this->t('Connect Stripe'),
      'skip_url' => $skip_url,
      'skip_label' => $this->t('Skip for now'),
    ];

    if ($stripe_phase === 'payouts_ready') {
      $done_text = $this->t('✅ You\'re ready to accept payments');
      $content['done'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-stripe-onboard-card-copy'],
        ],
        'message' => [
          '#markup' => '<p>' . $done_text . '</p>',
        ],
      ];
      $onboard_footer['continue_url'] = Url::fromRoute('myeventlane_vendor.create_event_gateway')->toString();
      $onboard_footer['continue_label'] = $this->t('Continue to create your event');
      unset($onboard_footer['skip_url'], $onboard_footer['skip_label']);
    }
    elseif ($stripe_phase === 'needs_completion') {
      $content['status'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-stripe-phase', 'mel-stripe-phase--action'],
        ],
        'message' => [
          '#markup' => '<p><strong>' . $this->t('Finish setting up Stripe') . '</strong> ' . $this->t('Complete the remaining steps in Stripe to enable payouts.') . '</p>',
        ],
      ];
      $onboard_footer['continue_label'] = $this->t('Continue setting up payments');
    }
    else {
      $content['intro'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-onboard-stripe-intro'],
        ],
        'text' => [
          '#markup' => '<p>' . $this->t('💸 Want to sell tickets? Connect Stripe to get paid directly.') . '</p>',
        ],
      ];

      $content['benefits'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-onboard-stripe-benefits'],
        ],
        'list' => [
          '#theme' => 'item_list',
          '#items' => [
            $this->t('Accept credit and debit card payments'),
            $this->t('Secure, PCI-compliant processing'),
            $this->t('Automatic payouts to your bank account'),
            $this->t('Built-in fraud protection'),
          ],
        ],
      ];
    }

    return [
      '#theme' => 'vendor_onboard_step',
      '#step_number' => 3,
      '#total_steps' => 7,
      '#step_title' => $this->t('Get paid for your events 💳'),
      '#step_description' => $this->t('Connect Stripe to receive payments.'),
      '#content' => $content,
      '#stripe_state' => $stripe_state,
      '#onboard_footer' => $onboard_footer,
      '#attached' => [
        'library' => ['myeventlane_vendor/onboarding'],
      ],
    ];
  }

  /**
   * Gets the store for the current user.
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

    $vendor = $this->getCurrentUserVendor();
    if ($vendor && $vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
      $store = $vendor->get('field_vendor_store')->entity;
      if ($store instanceof StoreInterface) {
        return $store;
      }
    }

    // Fallback: find store by user ID.
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
   * Gets the vendor entity for the current user.
   *
   * Prefers owning vendor (uid), then membership (field_vendor_users), matching
   * OnboardingManager::ensureVendorExists and vendor theme onboarding stages.
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

    $owner_ids = $vendorStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $userId)
      ->range(0, 1)
      ->execute();
    if (!empty($owner_ids)) {
      $vendor = $vendorStorage->load(reset($owner_ids));
      if ($vendor instanceof Vendor) {
        return $vendor;
      }
    }

    $member_ids = $vendorStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_vendor_users', $userId)
      ->range(0, 1)
      ->execute();
    if (!empty($member_ids)) {
      $vendor = $vendorStorage->load(reset($member_ids));
      if ($vendor instanceof Vendor) {
        return $vendor;
      }
    }

    return NULL;
  }

}
