<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_surface\Kernel;

use Drupal\myeventlane_surface\MelObservabilityDiagnosticsAccess;
use Drupal\myeventlane_surface\MelObservabilityVisibilityLevel;
use Drupal\myeventlane_surface\MelSurfaceId;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\Routing\Route;

/**
 * Observability diagnostics gate (staff surface + permission).
 *
 * @group myeventlane_surface
 */
final class MelObservabilityAccessKernelTest extends MelSurfaceGovernanceKernelTestBase {

  public function testDiagnosticsAccessStaffWithPermission(): void {
    $access = $this->container->get('myeventlane_surface.observability_diagnostics_access');
    $this->assertInstanceOf(MelObservabilityDiagnosticsAccess::class, $access);
    $user = $this->createUserWithDiagnosticsPermission();
    $this->assertTrue($access->mayViewDiagnostics(MelSurfaceId::Staff, $user));
  }

  public function testDiagnosticsAccessStaffWithoutPermissionDenied(): void {
    $access = $this->container->get('myeventlane_surface.observability_diagnostics_access');
    $user = User::create([
      'name' => $this->randomMachineName(),
      'mail' => $this->randomMachineName() . '@example.com',
      'status' => 1,
    ]);
    $user->addRole('authenticated');
    $user->save();
    $this->assertFalse($access->mayViewDiagnostics(MelSurfaceId::Staff, $user));
  }

  public function testDiagnosticsAccessVendorDeniedRegardlessOfPermission(): void {
    $access = $this->container->get('myeventlane_surface.observability_diagnostics_access');
    $user = $this->createUserWithDiagnosticsPermission();
    $this->assertFalse($access->mayViewDiagnostics(MelSurfaceId::Vendor, $user));
  }

  public function testObservabilityManagerStaffWithPermissionReturnsTracesAndCacheContexts(): void {
    $user = $this->createUserWithDiagnosticsPermission();
    $this->setCurrentUser($user);
    $this->pushRouteRequest('/admin/config', 'system.admin_config', $this->adminRoute('/admin/config'));

    $state = $this->container->get('myeventlane_surface.state_manager')->buildPageInterpretation();
    $workflow = $this->container->get('myeventlane_surface.workflow_manager')->buildPageAttachments();
    $policy = $this->container->get('myeventlane_surface.operational_policy_manager')->buildPageInterpretation();
    $experience = $this->container->get('myeventlane_surface.experience_manager')->buildPageAttachments($policy);
    $intelligence = $this->container->get('myeventlane_surface.intelligence_manager')->buildPagePayload($experience, $policy);

    $payload = $this->container->get('myeventlane_surface.observability_manager')->buildPagePayload(
      $state,
      $workflow,
      $experience,
      $intelligence,
      $policy,
    );

    $this->assertSame(MelObservabilityVisibilityLevel::Diagnostic->value, $payload['visibility_tier_value']);
    $this->assertNotSame([], $payload['traces']);
    $contexts = $payload['#cache']['contexts'] ?? [];
    $this->assertContains('user.permissions', $contexts);

    $this->popRequest();
  }

  public function testObservabilityManagerStaffWithoutPermissionReturnsRestrictedPayload(): void {
    $user = User::create([
      'name' => $this->randomMachineName(),
      'mail' => $this->randomMachineName() . '@example.com',
      'status' => 1,
    ]);
    $user->addRole('authenticated');
    $user->save();
    $this->setCurrentUser($user);
    $this->pushRouteRequest('/admin/config', 'system.admin_config', $this->adminRoute('/admin/config'));

    $state = $this->container->get('myeventlane_surface.state_manager')->buildPageInterpretation();
    $workflow = $this->container->get('myeventlane_surface.workflow_manager')->buildPageAttachments();
    $policy = $this->container->get('myeventlane_surface.operational_policy_manager')->buildPageInterpretation();
    $experience = $this->container->get('myeventlane_surface.experience_manager')->buildPageAttachments($policy);
    $intelligence = $this->container->get('myeventlane_surface.intelligence_manager')->buildPagePayload($experience, $policy);
    $payload = $this->container->get('myeventlane_surface.observability_manager')->buildPagePayload(
      $state,
      $workflow,
      $experience,
      $intelligence,
      $policy,
    );

    $this->assertSame(MelObservabilityVisibilityLevel::None->value, $payload['visibility_tier_value']);
    $this->assertSame([], $payload['traces']);
    $this->assertArrayHasKey('diagnostics_permission_required', $payload['privacy']);
    $this->assertTrue($payload['privacy']['diagnostics_permission_required']);
    $contexts = $payload['#cache']['contexts'] ?? [];
    $this->assertContains('user.permissions', $contexts);

    $this->popRequest();
  }

  public function testCustomerSurfaceRestrictedObservabilityPayload(): void {
    $user = $this->createUserWithDiagnosticsPermission();
    $this->setCurrentUser($user);
    $route = new Route('/my-account');
    $this->pushRouteRequest('/my-account', 'myeventlane_account.dashboard', $route);

    $state = $this->container->get('myeventlane_surface.state_manager')->buildPageInterpretation();
    $workflow = $this->container->get('myeventlane_surface.workflow_manager')->buildPageAttachments();
    $policy = $this->container->get('myeventlane_surface.operational_policy_manager')->buildPageInterpretation();
    $experience = $this->container->get('myeventlane_surface.experience_manager')->buildPageAttachments($policy);
    $intelligence = $this->container->get('myeventlane_surface.intelligence_manager')->buildPagePayload($experience, $policy);
    $payload = $this->container->get('myeventlane_surface.observability_manager')->buildPagePayload(
      $state,
      $workflow,
      $experience,
      $intelligence,
      $policy,
    );

    $this->assertSame(MelObservabilityVisibilityLevel::None->value, $payload['visibility_tier_value']);
    $this->assertSame([], $payload['traces']);

    $this->popRequest();
  }

  public function testVendorSurfaceRestrictedObservabilityPayload(): void {
    $user = $this->createUserWithDiagnosticsPermission();
    $this->setCurrentUser($user);
    $route = new Route('/vendor/dashboard');
    $this->pushRouteRequest('/vendor/dashboard', 'myeventlane_vendor.console.dashboard', $route);

    $state = $this->container->get('myeventlane_surface.state_manager')->buildPageInterpretation();
    $workflow = $this->container->get('myeventlane_surface.workflow_manager')->buildPageAttachments();
    $policy = $this->container->get('myeventlane_surface.operational_policy_manager')->buildPageInterpretation();
    $experience = $this->container->get('myeventlane_surface.experience_manager')->buildPageAttachments($policy);
    $intelligence = $this->container->get('myeventlane_surface.intelligence_manager')->buildPagePayload($experience, $policy);
    $payload = $this->container->get('myeventlane_surface.observability_manager')->buildPagePayload(
      $state,
      $workflow,
      $experience,
      $intelligence,
      $policy,
    );

    $this->assertSame(MelObservabilityVisibilityLevel::None->value, $payload['visibility_tier_value']);
    $this->assertSame([], $payload['traces']);

    $this->popRequest();
  }

  public function testNegotiatorOmitsDrupalSettingsMelObservabilityWhenRestricted(): void {
    $user = User::create([
      'name' => $this->randomMachineName(),
      'mail' => $this->randomMachineName() . '@example.com',
      'status' => 1,
    ]);
    $user->addRole('authenticated');
    $user->save();
    $this->setCurrentUser($user);
    $this->pushRouteRequest('/admin/config', 'system.admin_config', $this->adminRoute('/admin/config'));

    $variables = ['#attached' => []];
    $this->container->get('myeventlane_surface.negotiator')->attachPageMetadata($variables);
    $this->assertArrayNotHasKey('melObservability', $variables['#attached']['drupalSettings'] ?? []);

    $this->popRequest();
  }

  public function testNegotiatorAttachesMelObservabilityDrupalSettingsWhenStaffDiagnosticsAllowedAndTracesExist(): void {
    $user = $this->createUserWithDiagnosticsPermission();
    $this->setCurrentUser($user);
    $this->pushRouteRequest('/admin/config', 'system.admin_config', $this->adminRoute('/admin/config'));

    $variables = ['#attached' => []];
    $this->container->get('myeventlane_surface.negotiator')->attachPageMetadata($variables);
    $settings = $variables['#attached']['drupalSettings']['melObservability'] ?? NULL;
    $this->assertIsArray($settings);
    $this->assertTrue($settings['enabled']);
    $this->assertNotSame('', $settings['tier']);

    $this->popRequest();
  }

  private function createUserWithDiagnosticsPermission(): User {
    $rid = 'mel_obs_diag';
    if (!Role::load($rid)) {
      $role = Role::create(['id' => $rid, 'label' => 'MEL observability diagnostics']);
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
