<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_surface\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards consolidation of the legacy customer /my-events surface.
 *
 * @group myeventlane_surface
 */
final class CustomerMyEventsConsolidationContractTest extends TestCase {

  /**
   * The legacy route remains a private, authentication-aware redirect.
   */
  public function testLegacyRouteRedirectContract(): void {
    $repository_root = dirname(__DIR__, 7);
    $module_root = $repository_root . '/web/modules/custom/myeventlane_dashboard';
    $routing = file_get_contents($module_root . '/myeventlane_dashboard.routing.yml');
    $controller = file_get_contents($module_root . '/src/Controller/DashboardRedirectController.php');

    $this->assertNotFalse($routing);
    $this->assertNotFalse($controller);
    $this->assertStringContainsString('myeventlane_dashboard.customer:', $routing);
    $this->assertStringContainsString("path: '/my-events'", $routing);
    $this->assertStringContainsString('DashboardRedirectController::customerEvents', $routing);
    $this->assertStringContainsString("Url::fromRoute('myeventlane_account.dashboard')", $controller);
    $this->assertStringContainsString("Url::fromRoute('user.login'", $controller);
    $this->assertStringContainsString("'destination'", $controller);
    $this->assertStringContainsString("addCacheControlDirective('no-store')", $controller);
  }

  /**
   * No active customer caller still sends users to the compatibility route.
   */
  public function testActiveCallersUseCanonicalDestinations(): void {
    $repository_root = dirname(__DIR__, 7);
    $canonical_callers = [
      'web/modules/custom/myeventlane_account/src/Service/AccountLinksService.php',
      'web/modules/custom/myeventlane_core/src/Controller/CustomerOnboardMyTicketsController.php',
      'web/modules/custom/myeventlane_surface/src/MelCustomerContinuityPresenter.php',
      'web/modules/custom/myeventlane_surface/src/MelExperienceRegistry.php',
      'web/modules/custom/myeventlane_surface/src/MelWorkflowRegistry.php',
    ];

    foreach ($canonical_callers as $relative_path) {
      $contents = file_get_contents($repository_root . '/' . $relative_path);
      $this->assertNotFalse($contents);
      $this->assertStringNotContainsString('myeventlane_dashboard.customer', $contents, $relative_path);
      $this->assertStringNotContainsString('/my-events', $contents, $relative_path);
    }

    $onboarding = file_get_contents($repository_root . '/web/modules/custom/myeventlane_core/src/Controller/CustomerOnboardMyTicketsController.php');
    $continuity = file_get_contents($repository_root . '/web/modules/custom/myeventlane_surface/src/MelCustomerContinuityPresenter.php');
    $workflow = file_get_contents($repository_root . '/web/modules/custom/myeventlane_surface/src/MelWorkflowRegistry.php');
    $experience = file_get_contents($repository_root . '/web/modules/custom/myeventlane_surface/src/MelExperienceRegistry.php');

    $this->assertStringContainsString("Url::fromRoute('myeventlane_account.dashboard')", (string) $onboarding);
    $this->assertStringContainsString("Url::fromRoute('myeventlane_account.dashboard')", (string) $continuity);
    $this->assertStringContainsString("primaryCtaRouteName: 'view.upcoming_events.page_events'", (string) $workflow);
    $this->assertStringContainsString("activationExactRoutes: ['view.mel_saved_events.page_1']", (string) $experience);
  }

  /**
   * The duplicate presentation implementation is physically retired.
   */
  public function testDuplicatePresentationIsRemoved(): void {
    $repository_root = dirname(__DIR__, 7);
    $module_root = $repository_root . '/web/modules/custom/myeventlane_dashboard';
    $this->assertFileDoesNotExist($module_root . '/src/Controller/CustomerDashboardController.php');
    $this->assertFileDoesNotExist($module_root . '/templates/myeventlane-customer-dashboard.html.twig');
  }

}
