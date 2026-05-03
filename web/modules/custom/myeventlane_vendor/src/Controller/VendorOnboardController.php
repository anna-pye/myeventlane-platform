<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_vendor\Service\UserVendorMembershipQuery;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for vendor onboarding flow.
 */
class VendorOnboardController extends ControllerBase {

  public function __construct(
    private readonly UserVendorMembershipQuery $userVendorMembershipQuery,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_vendor.user_vendor_membership_query'),
    );
  }

  /**
   * Legacy onboarding page - redirects to new step-by-step flow.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to new onboarding flow.
   */
  public function onboard(): RedirectResponse {
    $current_user = $this->currentUser();

    // Anonymous users go to account step.
    if ($current_user->isAnonymous()) {
      return new RedirectResponse(
        Url::fromRoute('myeventlane_vendor.onboard.account')->toString()
      );
    }

    // Check if user already has a vendor.
    $vendor_ids = $this->userVendorMembershipQuery->getVendorIdsForUser((int) $current_user->id());
    if (!empty($vendor_ids)) {
      // User already has a vendor, redirect to create event.
      return new RedirectResponse(
        Url::fromRoute('myeventlane_event_studio.create')->toString()
      );
    }

    // User is logged in but no vendor - go to profile step.
    return new RedirectResponse(
      Url::fromRoute('myeventlane_vendor.onboard.profile')->toString()
    );
  }

  /**
   * Custom submit handler to redirect to create event after vendor creation.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function onboardFormSubmit(array $form, FormStateInterface $form_state): void {
    $vendor = $form_state->getFormObject()->getEntity();
    $current_user = $this->currentUser();

    // Ensure the current user is associated with the vendor.
    if ($vendor->hasField('field_vendor_users')) {
      $current_users = $vendor->get('field_vendor_users')->getValue();
      $user_ids = array_column($current_users, 'target_id');
      if (!in_array($current_user->id(), $user_ids, TRUE)) {
        $vendor->get('field_vendor_users')->appendItem(['target_id' => $current_user->id()]);
        $vendor->save();
      }
    }

    // Redirect to create event gateway.
    $form_state->setRedirect('myeventlane_event_studio.create');
  }

}
