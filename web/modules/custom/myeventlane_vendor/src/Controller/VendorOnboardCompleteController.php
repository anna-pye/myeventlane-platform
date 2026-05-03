<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Entity\OnboardingStateInterface;
use Drupal\myeventlane_core\Service\OnboardingManager;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\myeventlane_vendor\EventSubscriber\VendorStoreSubscriber;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controller for vendor onboarding completion.
 */
final class VendorOnboardCompleteController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly OnboardingManager $onboardingManager,
    private readonly RequestStack $requestStack,
    private readonly VendorStoreSubscriber $vendorStoreSubscriber,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_onboarding.manager'),
      $container->get('request_stack'),
      $container->get('myeventlane_vendor.vendor_store_subscriber'),
    );
  }

  /**
   * Step 6: Onboarding complete - celebration page or redirect.
   *
   * The Vendor is created and linked to onboarding (vendor_id on state) first.
   * Only then is the state marked complete (required by OnboardingState::preSave()).
   * A follow-up call syncs the default Commerce store, matching the behaviour of
   * a vendor insert with onboarding already complete. Organiser name and legal
   * acceptance in state flags are copied to the vendor.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse|array
   *   Redirect or render array.
   */
  public function complete(): RedirectResponse|array {
    $currentUser = $this->currentUser();

    if ($currentUser->isAnonymous()) {
      return new RedirectResponse(
        Url::fromRoute('myeventlane_vendor.onboard.account')->toString()
      );
    }

    $uid = (int) $currentUser->id();
    if ($uid <= 0) {
      return new RedirectResponse(
        Url::fromRoute('myeventlane_vendor.onboard.account')->toString()
      );
    }

    $state = $this->onboardingManager->loadVendorStateByUid($uid);
    if ($state === NULL) {
      $state = $this->onboardingManager->createVendorStateForUid($uid);
    }

    $vendor = $this->getCurrentUserVendor();
    if ($vendor === NULL) {
      $vendor = $this->onboardingManager->ensureVendorExists($currentUser->getAccount());
    }

    $this->applyVendorDataFromStateFlags($vendor, $state);

    $this->onboardingManager->ensureVendorAccess($currentUser->getAccount());
    $user = $this->entityTypeManager()->getStorage('user')->load($uid);
    if (!($user instanceof UserInterface)) {
      $this->getLogger('myeventlane_vendor')->error(
        'Vendor onboard complete: user not loadable uid=@uid',
        ['@uid' => (string) $uid],
      );
      return new RedirectResponse(
        Url::fromRoute('myeventlane_vendor.onboard.account')->toString()
      );
    }

    $state = $this->onboardingManager->loadOrCreateVendor($user, $vendor);

    $state->setStage('complete');
    $state->setCompleted(TRUE);
    $this->onboardingManager->persistOnboardingState($state);

    $this->vendorStoreSubscriber->onVendorInsertFromHook($vendor);

    $request = $this->requestStack->getCurrentRequest();
    $auto_redirect = $request && (string) $request->query->get('auto') === '1';

    if ($auto_redirect) {
      return new RedirectResponse(
        Url::fromRoute('myeventlane_event_studio.create')->toString()
      );
    }

    return [
      '#theme' => 'vendor_onboard_complete_celebration',
      '#attached' => [
        'library' => ['myeventlane_vendor/onboarding'],
      ],
      '#create_event_url' => Url::fromRoute('myeventlane_event_studio.create')->toString(),
      '#dashboard_url' => Url::fromRoute('myeventlane_vendor.console.dashboard')->toString(),
    ];
  }

  /**
   * Copies organiser and legal data from state flags onto the vendor entity.
   */
  private function applyVendorDataFromStateFlags(Vendor $vendor, OnboardingStateInterface $state): void {
    $raw = $state->getFlags();
    $flags = is_array($raw) ? $raw : [];
    if (!empty($flags['organiser_name'])) {
      $vendor->setName((string) $flags['organiser_name']);
    }
    $this->onboardingManager->applyVendorLegalFieldsFromStateFlags($vendor, $flags);
    $vendor->save();
  }

  /**
   * Gets the vendor entity for the current user.
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
