<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Gateway controller for event creation that enforces vendor onboarding.
 */
class CreateEventGatewayController extends ControllerBase {

  /**
   * Redirects users based on their vendor status.
   *
   * Logic:
   * - Anonymous users → login with destination back to /create-event
   * - Logged-in users without vendor → /vendor/onboard (vendor setup)
   * - Logged-in users with vendor → /vendor/dashboard.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response.
   */
  public function gateway(): RedirectResponse {
    $current_user = $this->currentUser();

    // Anonymous users: redirect to login with destination.
    if ($current_user->isAnonymous()) {
      // Use the request's current path to preserve any query parameters.
      $request = \Drupal::request();
      $destination = $request->getRequestUri();

      // Add a message explaining why login is needed.
      $this->messenger()->addWarning($this->t('To create events, you need to log in with a vendor/organiser account. If you don\'t have an account yet, you can create one after logging in.'));

      $login_url = Url::fromRoute('user.login', [], [
        'query' => ['destination' => $destination],
      ]);
      return new RedirectResponse($login_url->toString());
    }

    // Find vendors associated with the current user.
    $vendor_ids = $this->getUserVendors((int) $current_user->id());

    // No vendor found: redirect to onboarding (vendor setup).
    if (empty($vendor_ids)) {
      $onboard_url = Url::fromRoute('myeventlane_vendor.onboard.profile');
      return new RedirectResponse($onboard_url->toString());
    }

    // User has a vendor: redirect directly to event creation wizard.
    $create_url = Url::fromRoute('myeventlane_event.wizard.create');
    return new RedirectResponse($create_url->toString());
  }

  /**
   * Gets vendor IDs associated with a user.
   *
   * Checks both:
   * - uid (owner) field
   * - field_vendor_users (multi-user field)
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
