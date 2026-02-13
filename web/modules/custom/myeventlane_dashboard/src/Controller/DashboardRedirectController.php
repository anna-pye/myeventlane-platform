<?php

declare(strict_types=1);

namespace Drupal\myeventlane_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Role-aware redirect controller for legacy /dashboard path.
 *
 * Routes users to the appropriate dashboard based on permissions:
 * - Vendors (access vendor console) → /vendor/dashboard
 * - Logged-in customers → /my-account
 * - Anonymous → /user/login
 */
final class DashboardRedirectController extends ControllerBase {

  /**
   * Redirects /dashboard to the appropriate destination by role.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to vendor dashboard, customer dashboard, or login.
   */
  public function build(): RedirectResponse {
    $account = $this->currentUser();

    if ($account->hasPermission('access vendor console')) {
      $url = Url::fromRoute('myeventlane_vendor.console.dashboard');
    }
    elseif ($account->isAuthenticated()) {
      $url = Url::fromRoute('myeventlane_account.dashboard');
    }
    else {
      $url = Url::fromRoute('user.login');
    }

    return new RedirectResponse($url->toString(), 302);
  }

}
