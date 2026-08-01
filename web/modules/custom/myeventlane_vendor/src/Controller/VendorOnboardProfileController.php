<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\OnboardingManager;
use Drupal\myeventlane_vendor\Form\VendorOnboardProfileForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Owns entry to the mandatory organiser-details step.
 */
final class VendorOnboardProfileController extends ControllerBase {

  public function __construct(
    private readonly OnboardingManager $onboardingManager,
  ) {}

  /**
   * Builds organiser details or returns completed organisers to event creation.
   */
  public function profile(): array|RedirectResponse {
    $account = $this->currentUser();
    if ($account->isAnonymous()) {
      return new RedirectResponse(Url::fromRoute('myeventlane_vendor.onboard.account')->toString());
    }

    $state = $this->onboardingManager->loadVendorStateByUid((int) $account->id());
    if ($state !== NULL && $this->onboardingManager->isCompleted($state)) {
      return new RedirectResponse(Url::fromRoute('myeventlane_vendor.create_event_gateway')->toString());
    }

    return $this->formBuilder()->getForm(VendorOnboardProfileForm::class);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('myeventlane_onboarding.manager'));
  }

}
