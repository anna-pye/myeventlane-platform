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

    // Check if Stripe is already connected.
    $isConnected = FALSE;
    if ($store->hasField('field_stripe_account_id') && !$store->get('field_stripe_account_id')->isEmpty()) {
      $accountId = $store->get('field_stripe_account_id')->value;
      if (!empty($accountId)) {
        // Check status.
        try {
          $status = $this->stripeService->getAccountStatus($accountId);
          $isConnected = $status['status'] === 'complete' && $status['charges_enabled'];
        }
        catch (\Exception $e) {
          // If we can't verify, assume not connected.
        }
      }
    }

    // Non-blocking: load/refresh onboarding state; advance when Stripe already connected.
    try {
      $user = $this->entityTypeManager()->getStorage('user')->load((int) $currentUser->id());
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

    if ($isConnected) {
      $content['status'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-alert', 'mel-alert-success'],
        ],
        'message' => [
          '#markup' => '<p><strong>' . $this->t('Stripe is already connected!') . '</strong> ' . $this->t('You can accept payments for your events.') . '</p>',
        ],
      ];

      $content['continue'] = [
        '#type' => 'link',
        '#title' => $this->t('Continue to create your first event'),
        '#url' => Url::fromRoute('myeventlane_vendor.onboard.first_event'),
        '#attributes' => [
          'class' => ['mel-btn', 'mel-btn-primary', 'mel-btn-lg'],
        ],
      ];
    }
    else {
      $content['intro'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-onboard-stripe-intro'],
        ],
        'text' => [
          '#markup' => '<p>' . $this->t('To accept payments for your events, you need to connect a Stripe account. Stripe is a secure payment processor used by millions of businesses worldwide.') . '</p>',
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

      $content['connect'] = [
        '#type' => 'link',
        '#title' => $this->t('Connect Stripe account'),
        '#url' => Url::fromRoute('myeventlane_vendor.stripe_connect', [], [
          'query' => ['destination' => '/vendor/onboard/stripe'],
        ]),
        '#attributes' => [
          'class' => ['mel-btn', 'mel-btn-primary', 'mel-btn-lg'],
        ],
      ];

      $content['skip'] = [
        '#type' => 'link',
        '#title' => $this->t('Skip for now'),
        '#url' => Url::fromRoute('myeventlane_vendor.onboard.first_event'),
        '#attributes' => [
          'class' => ['mel-btn', 'mel-btn-secondary'],
        ],
      ];

      $content['skip_note'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-onboard-stripe-skip-note'],
        ],
        'text' => [
          '#markup' => '<p class="mel-text-muted">' . $this->t('You can connect Stripe later from your dashboard. Note: You\'ll need Stripe to accept paid ticket sales.') . '</p>',
        ],
      ];
    }

    return [
      '#theme' => 'vendor_onboard_step',
      '#step_number' => 3,
      '#total_steps' => 7,
      '#step_title' => $this->t('Set up payments'),
      '#step_description' => $this->t('Connect your Stripe account to accept payments for your events. This process takes about 5 minutes.'),
      '#content' => $content,
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

}
