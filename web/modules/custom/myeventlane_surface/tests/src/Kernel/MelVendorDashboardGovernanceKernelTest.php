<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_surface\Kernel;

use Drupal\myeventlane_surface\MelObservabilityDiagnosticsAccess;
use Drupal\myeventlane_surface\MelSurfaceId;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\Routing\Route;

/**
 * Vendor shell governance payloads (observability + intelligence privacy envelopes).
 *
 * Mirrors adoption checks for /vendor/dashboard without asserting proprietary KPI copy.
 *
 * @group myeventlane_surface
 */
final class MelVendorDashboardGovernanceKernelTest extends MelSurfaceGovernanceKernelTestBase {

  public function testVendorShellOmitsStaffDiagnosticsAndObservabilityPanels(): void {
    $user = $this->createUserWithDiagnosticsPermission();
    $this->setCurrentUser($user);
    $this->pushRouteRequest('/vendor/dashboard', 'myeventlane_vendor.console.dashboard', new Route('/vendor/dashboard'));

    $intel = $this->container->get('myeventlane_surface.intelligence_manager')->buildPagePayload();
    $this->assertFalse($intel['privacy']['staff_diagnostic_only_on_staff']);
    $this->assertTrue($intel['privacy']['vendor_isolation']);

    $variables = ['#attached' => []];
    $this->container->get('myeventlane_surface.negotiator')->attachPageMetadata($variables);
    $this->assertNull($variables['mel_observability_panel']);
    $this->assertArrayNotHasKey('melObservability', $variables['#attached']['drupalSettings'] ?? []);
    $this->assertSame([], $variables['mel_observability']['traces']);

    $access = $this->container->get('myeventlane_surface.observability_diagnostics_access');
    $this->assertFalse($access->mayViewDiagnostics(MelSurfaceId::Vendor, $user));

    $this->popRequest();
  }

  private function createUserWithDiagnosticsPermission(): User {
    $rid = 'mel_obs_diag_vendor';
    if (!Role::load($rid)) {
      $role = Role::create(['id' => $rid, 'label' => 'MEL observability diagnostics (vendor test)']);
      $role->save();
      user_role_grant_permissions($rid, [MelObservabilityDiagnosticsAccess::PERMISSION]);
    }
    $user = User::create([
      'name' => $this->randomMachineName(),
      'mail' => $this->randomMachineName() . '@example.com',
      'status' => 1,
    ]);
    $user->addRole('authenticated');
    $user->addRole($rid);
    $user->save();
    return $user;
  }

}
