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
   * Redirects the legacy customer event list without losing login continuity.
   *
   * The response is deliberately temporary and private because its destination
   * varies by authentication state.
   */
  public function customerEvents(): RedirectResponse {
    if ($this->currentUser()->isAnonymous()) {
      $url = Url::fromRoute('user.login', [], [
        'query' => [
          'destination' => Url::fromRoute('myeventlane_account.dashboard')->toString(),
        ],
      ]);
    }
    else {
      $url = Url::fromRoute('myeventlane_account.dashboard');
    }

    $response = new RedirectResponse($url->toString(), 302);
    $response->setPrivate();
    $response->headers->addCacheControlDirective('no-store');
    return $response;
  }

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
