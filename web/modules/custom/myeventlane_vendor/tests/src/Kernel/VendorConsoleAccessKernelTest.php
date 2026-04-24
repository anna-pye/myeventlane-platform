<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Kernel;

use Drupal\Core\Routing\RouteMatch;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_core\MelVendorOrganiserRole;
use Drupal\myeventlane_core\VendorConsoleTrust;
use Drupal\myeventlane_vendor\Access\VendorConsoleAccess;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Vendor console route access (VendorConsoleAccess) for /vendor/dashboard.
 *
 * Scenarios mirror VendorConsoleTrust plus anonymous denial; gate redirects are
 * covered by VendorConsoleGateRedirectSubscriberKernelTest.
 *
 * @coversDefaultClass \Drupal\myeventlane_vendor\Access\VendorConsoleAccess
 *
 * @group myeventlane_vendor
 */
#[\Drupal\Tests\RunTestsInSeparateProcesses]
final class VendorConsoleAccessKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'flag',
    'myeventlane_core',
    'myeventlane_boost',
    'myeventlane_vendor',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('user_role');
    $this->installEntitySchema('myeventlane_onboarding_state');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['user']);
  }

  /**
   * Anonymous user: access denied (browser flow redirects via gate subscriber).
   *
   * @covers ::access
   */
  public function testAnonymousDenied(): void {
    $this->pushPathRequest('/vendor/dashboard');
    $route_match = $this->dashboardRouteMatch();
    $anon = User::getAnonymousUser();
    $this->assertFalse(VendorConsoleTrust::accountIsTrustedForVendorConsole($anon));
    $result = $this->access()->access($route_match, $anon);
    $this->assertTrue($result->isForbidden());
    $this->popRequest();
  }

  /**
   * Authenticated buyer without vendor role or console permission → forbidden.
   *
   * @covers ::access
   */
  public function testAuthenticatedBuyerWithoutConsolePermissionOrVendorRoleDenied(): void {
    $this->pushPathRequest('/vendor/dashboard');
    $user = $this->createUserWithRoles(['authenticated']);
    $this->assertFalse(VendorConsoleTrust::accountIsTrustedForVendorConsole($user));
    $result = $this->access()->access($this->dashboardRouteMatch(), $user);
    $this->assertTrue($result->isForbidden());
    $this->popRequest();
  }

  /**
   * Vendor role only → allowed.
   *
   * @covers ::access
   */
  public function testAuthenticatedUserWithVendorRoleOnlyAllowed(): void {
    $this->ensureVendorRoleExists();
    $this->pushPathRequest('/vendor/dashboard');
    $user = $this->createUserWithRoles(['authenticated', MelVendorOrganiserRole::MACHINE_NAME]);
    $this->assertTrue(VendorConsoleTrust::accountIsTrustedForVendorConsole($user));
    $result = $this->access()->access($this->dashboardRouteMatch(), $user);
    $this->assertTrue($result->isAllowed());
    $this->popRequest();
  }

  /**
   * access vendor console but not "access vendor dashboard" → dashboard forbidden.
   *
   * @covers ::access
   */
  public function testConsolePermissionWithoutDashboardPermissionOnDashboardDenied(): void {
    $this->ensureConsolePermissionRole();
    $this->pushPathRequest('/vendor/dashboard');
    $user = $this->createUserWithRoles(['authenticated', 'console_perm_only']);
    $this->assertTrue(VendorConsoleTrust::accountIsTrustedForVendorConsole($user));
    $this->assertFalse($user->hasPermission('access vendor dashboard'));
    $this->assertTrue(
      $this->access()->access($this->dashboardRouteMatch(), $user)->isForbidden()
    );
    $this->popRequest();
  }

  /**
   * "access vendor dashboard" only (no trust) → allowed on /vendor/dashboard.
   *
   * @covers ::access
   */
  public function testAuthenticatedUserWithAccessVendorDashboardPermissionOnlyAllowedOnDashboard(): void {
    $this->ensureDashboardPermissionRole();
    $this->pushPathRequest('/vendor/dashboard');
    $user = $this->createUserWithRoles(['authenticated', 'dashboard_perm_only']);
    $this->assertFalse(VendorConsoleTrust::accountIsTrustedForVendorConsole($user));
    $this->assertTrue(
      $this->access()->access($this->dashboardRouteMatch(), $user)->isAllowed()
    );
    $this->popRequest();
  }

  /**
   * Site admin + vendor role → allowed.
   *
   * @covers ::access
   */
  public function testAuthenticatedAdminPlusVendorRoleAllowed(): void {
    $this->ensureSiteAdminRole();
    $this->ensureVendorRoleExists();
    $this->pushPathRequest('/vendor/dashboard');
    $user = $this->createUserWithRoles(['authenticated', 'site_admin', MelVendorOrganiserRole::MACHINE_NAME]);
    $this->assertTrue(VendorConsoleTrust::accountIsTrustedForVendorConsole($user));
    $result = $this->access()->access($this->dashboardRouteMatch(), $user);
    $this->assertTrue($result->isAllowed());
    $this->popRequest();
  }

  /**
   * Vendor role + non-completed onboarding: dashboard forbidden.
   *
   * @covers ::access
   */
  public function testAuthenticatedVendorWithIncompleteOnboardingConsoleForbidden(): void {
    $this->ensureVendorRoleExists();
    $user = $this->createUserWithRoles(['authenticated', MelVendorOrganiserRole::MACHINE_NAME]);
    $onboarding = $this->container->get('myeventlane_onboarding.manager');
    $onboarding->createVendorStateForUid((int) $user->id());

    $this->pushPathRequest('/vendor/dashboard');
    $this->assertTrue(
      $this->access()->access($this->dashboardRouteMatch(), $user)->isForbidden()
    );
    $this->popRequest();

    $this->pushPathRequest('/vendor/onboard/profile');
    $profile_match = new RouteMatch('myeventlane_vendor.onboard.profile', [], new Route('/vendor/onboard/profile'));
    $this->assertTrue(
      $this->access()->access($profile_match, $user)->isAllowed()
    );
    $this->popRequest();
  }

  private function access(): VendorConsoleAccess {
    return $this->container->get('myeventlane_vendor.access.vendor_console');
  }

  private function dashboardRouteMatch(): RouteMatch {
    $route = new Route('/vendor/dashboard');
    return new RouteMatch('myeventlane_vendor.console.dashboard', [], $route);
  }

  private function pushPathRequest(string $path): void {
    $request = Request::create($path, 'GET', [], [], [], [
      'HTTP_HOST' => 'vendor.example.com',
    ]);
    \Drupal::requestStack()->push($request);
  }

  private function popRequest(): void {
    \Drupal::requestStack()->pop();
  }

  /**
   * @return \Drupal\user\UserInterface
   */
  private function createUserWithRoles(array $roles) {
    $user = User::create([
      'name' => $this->randomMachineName(),
      'mail' => $this->randomMachineName() . '@example.com',
      'status' => 1,
    ]);
    foreach ($roles as $role) {
      $user->addRole($role);
    }
    $user->save();
    return $user;
  }

  private function ensureVendorRoleExists(): void {
    $id = MelVendorOrganiserRole::MACHINE_NAME;
    $role = Role::load($id);
    if ($role) {
      if (!$role->hasPermission('access vendor dashboard')) {
        $role->grantPermission('access vendor dashboard');
        $role->save();
      }
      return;
    }
    $role = Role::create([
      'id' => $id,
      'label' => 'Vendor',
    ]);
    $role->save();
    user_role_grant_permissions($id, [
      'access vendor console',
      'access vendor dashboard',
    ]);
  }

  private function ensureConsolePermissionRole(): void {
    if (Role::load('console_perm_only')) {
      return;
    }
    $role = Role::create([
      'id' => 'console_perm_only',
      'label' => 'Console perm only',
    ]);
    $role->save();
    user_role_grant_permissions('console_perm_only', ['access vendor console']);
  }

  private function ensureDashboardPermissionRole(): void {
    if (Role::load('dashboard_perm_only')) {
      return;
    }
    $role = Role::create([
      'id' => 'dashboard_perm_only',
      'label' => 'Dashboard perm only',
    ]);
    $role->save();
    user_role_grant_permissions('dashboard_perm_only', ['access vendor dashboard']);
  }

  private function ensureSiteAdminRole(): void {
    if (Role::load('site_admin')) {
      return;
    }
    $role = Role::create([
      'id' => 'site_admin',
      'label' => 'Site admin',
    ]);
    $role->save();
    user_role_grant_permissions('site_admin', ['administer site configuration']);
  }

}
