<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_core\MelVendorOrganiserRole;
use Drupal\myeventlane_core\VendorConsoleTrust;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * @coversDefaultClass \Drupal\myeventlane_core\VendorConsoleTrust
 *
 * @group myeventlane_vendor
 */
final class VendorConsoleTrustKernelTest extends KernelTestBase {

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

  public function testAnonymousNotTrusted(): void {
    $this->assertFalse(VendorConsoleTrust::accountIsTrustedForVendorConsole(User::getAnonymousUser()));
  }

  public function testAuthenticatedBuyerWithoutVendorRoleOrConsolePermissionNotTrusted(): void {
    $user = User::create([
      'name' => $this->randomMachineName(),
      'mail' => $this->randomMachineName() . '@example.com',
      'status' => 1,
    ]);
    $user->addRole('authenticated');
    $user->save();
    $this->assertFalse(VendorConsoleTrust::accountIsTrustedForVendorConsole($user));
  }

  public function testVendorRoleOnlyTrusted(): void {
    $this->ensureVendorRoleExists();
    $user = User::create([
      'name' => $this->randomMachineName(),
      'mail' => $this->randomMachineName() . '@example.com',
      'status' => 1,
    ]);
    $user->addRole('authenticated');
    $user->addRole(MelVendorOrganiserRole::MACHINE_NAME);
    $user->save();
    $this->assertTrue(VendorConsoleTrust::accountIsTrustedForVendorConsole($user));
  }

  public function testAccessVendorConsolePermissionOnlyTrusted(): void {
    $this->ensureConsolePermissionRole();
    $user = User::create([
      'name' => $this->randomMachineName(),
      'mail' => $this->randomMachineName() . '@example.com',
      'status' => 1,
    ]);
    $user->addRole('authenticated');
    $user->addRole('console_perm_only');
    $user->save();
    $this->assertTrue(VendorConsoleTrust::accountIsTrustedForVendorConsole($user));
  }

  public function testSiteAdminPlusVendorRoleTrusted(): void {
    $this->ensureSiteAdminRole();
    $this->ensureVendorRoleExists();
    $user = User::create([
      'name' => $this->randomMachineName(),
      'mail' => $this->randomMachineName() . '@example.com',
      'status' => 1,
    ]);
    $user->addRole('authenticated');
    $user->addRole('site_admin');
    $user->addRole(MelVendorOrganiserRole::MACHINE_NAME);
    $user->save();
    $this->assertTrue(VendorConsoleTrust::accountIsTrustedForVendorConsole($user));
  }

  private function ensureVendorRoleExists(): void {
    if (Role::load(MelVendorOrganiserRole::MACHINE_NAME)) {
      return;
    }
    Role::create([
      'id' => MelVendorOrganiserRole::MACHINE_NAME,
      'label' => 'Vendor',
    ])->save();
  }

  private function ensureConsolePermissionRole(): void {
    if (Role::load('console_perm_only')) {
      return;
    }
    Role::create([
      'id' => 'console_perm_only',
      'label' => 'Console perm only',
    ])->save();
    user_role_grant_permissions('console_perm_only', ['access vendor console']);
  }

  private function ensureSiteAdminRole(): void {
    if (Role::load('site_admin')) {
      return;
    }
    Role::create([
      'id' => 'site_admin',
      'label' => 'Site admin',
    ])->save();
    user_role_grant_permissions('site_admin', ['administer site configuration']);
  }

}
