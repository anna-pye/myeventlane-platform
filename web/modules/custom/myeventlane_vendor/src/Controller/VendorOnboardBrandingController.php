<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\OnboardingManager;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\myeventlane_vendor\Form\VendorBrandingForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for vendor onboarding step 4: Branding/Images.
 */
final class VendorOnboardBrandingController extends ControllerBase {

  /**
   * The onboarding manager.
   */
  private readonly OnboardingManager $onboardingManager;

  /**
   * Constructs the controller.
   */
  public function __construct(OnboardingManager $onboarding_manager) {
    $this->onboardingManager = $onboarding_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_onboarding.manager'),
    );
  }

  /**
   * Step 4: Branding/images setup.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   Render array or redirect.
   */
  public function branding(): array|RedirectResponse {
    $currentUser = $this->currentUser();

    if ($currentUser->isAnonymous()) {
      return new RedirectResponse(
        Url::fromRoute('myeventlane_vendor.onboard.account')->toString()
      );
    }

    $vendor = $this->getCurrentUserVendor();
    if (!$vendor) {
      return new RedirectResponse(
        Url::fromRoute('myeventlane_vendor.onboard.profile')->toString()
      );
    }

    // Non-blocking: load/refresh onboarding state.
    if ($vendor->id()) {
      try {
        $user = $this->entityTypeManager()->getStorage('user')->load((int) $currentUser->id());
        if ($user instanceof \Drupal\user\UserInterface) {
          $state = $this->onboardingManager->loadOrCreateVendor($user, $vendor);
          $this->onboardingManager->refreshFlags($state);
        }
      }
      catch (\Throwable $e) {
        $this->getLogger('myeventlane_vendor')->warning('Onboarding load/refresh failed on branding step: @m', ['@m' => $e->getMessage()]);
      }
    }

    // Build the branding form.
    $formObject = $this->formBuilder()->getForm(VendorBrandingForm::class, $vendor);

    return [
      '#theme' => 'vendor_onboard_step',
      '#step_number' => 4,
      '#total_steps' => 7,
      '#step_title' => $this->t('Add your branding'),
      '#step_description' => $this->t('Upload your logo and banner image to make your organiser page stand out. You can skip this and add images later.'),
      '#content' => $formObject,
      '#attached' => [
        'library' => ['myeventlane_vendor/onboarding'],
      ],
    ];
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
