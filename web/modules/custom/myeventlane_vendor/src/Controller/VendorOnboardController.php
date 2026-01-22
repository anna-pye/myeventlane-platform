<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for vendor onboarding flow.
 */
class VendorOnboardController extends ControllerBase {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static();
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
    $vendor_ids = $this->getUserVendors((int) $current_user->id());
    if (!empty($vendor_ids)) {
      // User already has a vendor, redirect to create event.
      return new RedirectResponse(
        Url::fromRoute('myeventlane_vendor.create_event_gateway')->toString()
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
    $form_state->setRedirect('myeventlane_vendor.create_event_gateway');
  }

  /**
   * Gets vendor IDs associated with a user.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return array
   *   Array of vendor IDs (as integers).
   */
  private function getUserVendors(int $uid): array {
    $storage = $this->entityTypeManager()->getStorage('myeventlane_vendor');

    // Check vendors where user is the owner (uid field).
    $owner_ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $uid)
      ->execute();

    // Check vendors where user is in field_vendor_users.
    $users_ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('field_vendor_users', $uid)
      ->execute();

    // Merge and return unique IDs, converting to integers.
    $all_ids = array_merge(
      $owner_ids ?: [],
      $users_ids ?: []
    );

    // Convert string keys to integer values and ensure uniqueness.
    return array_values(array_unique(array_map('intval', $all_ids)));
  }

}
