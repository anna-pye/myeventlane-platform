<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Kernel;

use Drupal\Core\Routing\RouteMatch;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_core\MelVendorOrganiserRole;
use Drupal\myeventlane_vendor\Access\VendorConsoleAccess;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * @coversDefaultClass \Drupal\myeventlane_vendor\Access\VendorConsoleAccess
 *
 * @group myeventlane_vendor
 */
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
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['user']);
  }

  /**
   * @covers ::access
   */
  public function testAnonymousDenied(): void {
    $this->pushDashboardRequest();
    $route_match = $this->dashboardRouteMatch();
    $anon = User::getAnonymousUser();
    $result = VendorConsoleAccess::access($route_match, $anon);
    $this->assertTrue($result->isForbidden());
    $this->popRequest();
  }

  /**
   * @covers ::access
   */
  public function testAuthenticatedBuyerWithoutConsolePermissionOrVendorRoleDenied(): void {
    $this->pushDashboardRequest();
    $user = $this->createUserWithRoles(['authenticated']);
    $result = VendorConsoleAccess::access($this->dashboardRouteMatch(), $user);
    $this->assertTrue($result->isForbidden());
    $this->popRequest();
  }

  /**
   * @covers ::access
   */
  public function testAuthenticatedUserWithVendorRoleOnlyAllowed(): void {
    $this->ensureVendorRoleExists();
    $this->pushDashboardRequest();
    $user = $this->createUserWithRoles(['authenticated', MelVendorOrganiserRole::MACHINE_NAME]);
    $result = VendorConsoleAccess::access($this->dashboardRouteMatch(), $user);
    $this->assertTrue($result->isAllowed());
    $this->popRequest();
  }

  /**
   * @covers ::access
   */
  public function testAuthenticatedUserWithAccessVendorConsolePermissionOnlyAllowed(): void {
    $this->ensureConsolePermissionRole();
    $this->pushDashboardRequest();
    $user = $this->createUserWithRoles(['authenticated', 'console_perm_only']);
    $result = VendorConsoleAccess::access($this->dashboardRouteMatch(), $user);
    $this->assertTrue($result->isAllowed());
    $this->popRequest();
  }

  /**
   * @covers ::access
   */
  public function testAuthenticatedAdminPlusVendorRoleAllowed(): void {
    $this->ensureSiteAdminRole();
    $this->ensureVendorRoleExists();
    $this->pushDashboardRequest();
    $user = $this->createUserWithRoles(['authenticated', 'site_admin', MelVendorOrganiserRole::MACHINE_NAME]);
    $result = VendorConsoleAccess::access($this->dashboardRouteMatch(), $user);
    $this->assertTrue($result->isAllowed());
    $this->popRequest();
  }

  private function dashboardRouteMatch(): RouteMatch {
    $route = new Route('/vendor/dashboard');
    return new RouteMatch('myeventlane_vendor.console.dashboard', [], $route);
  }

  private function pushDashboardRequest(): void {
    $request = Request::create('/vendor/dashboard', 'GET', [], [], [], [
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
    if (Role::load(MelVendorOrganiserRole::MACHINE_NAME)) {
      return;
    }
    $role = Role::create([
      'id' => MelVendorOrganiserRole::MACHINE_NAME,
      'label' => 'Vendor',
    ]);
    $role->save();
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
