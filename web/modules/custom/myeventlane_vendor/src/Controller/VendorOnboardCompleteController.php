<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\OnboardingManager;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for vendor onboarding completion.
 */
final class VendorOnboardCompleteController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly OnboardingManager $onboardingManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_onboarding.manager'),
    );
  }

  /**
   * Step 6: Onboarding complete - promote and redirect.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect response.
   */
  public function complete(): RedirectResponse {
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
    if (!$vendor) {
      $vendor = $this->onboardingManager->ensureVendorExists($currentUser->getAccount());
    }

    // Ensure completion invariant: vendor_id must exist for completed state.
    if ($state->getVendorId() !== (int) $vendor->id()) {
      $state->setVendorId((int) $vendor->id());
    }
    $state->setStage('complete');
    $state->setCompleted(TRUE);
    $state->save();

    // Grant vendor role (idempotent) at completion only.
    $this->onboardingManager->ensureVendorAccess($currentUser->getAccount());

    // After completion, route directly to event creation wizard.
    return new RedirectResponse(
      Url::fromRoute('myeventlane_event.wizard.create')->toString()
    );
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
