<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_surface\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards committed role YAML against Commerce order overview over-grants.
 *
 * @group myeventlane_surface
 */
final class MelCommerceOrderOverviewRoleConfigTest extends TestCase {

  private function rolePermissions(string $role_id): array {
    $path = dirname(__DIR__, 7) . '/config/sync/user.role.' . $role_id . '.yml';
    $this->assertFileExists($path);
    $data = Yaml::parseFile($path);
    $this->assertIsArray($data);
    $perms = $data['permissions'] ?? [];
    $this->assertIsArray($perms);
    return $perms;
  }

  public function testAnonymousRoleConfigOmitsCommerceOrderOverview(): void {
    $this->assertNotContains(
      'access commerce_order overview',
      $this->rolePermissions('anonymous'),
    );
  }

  public function testAuthenticatedRoleConfigOmitsCommerceOrderOverview(): void {
    $this->assertNotContains(
      'access commerce_order overview',
      $this->rolePermissions('authenticated'),
    );
  }

  public function testVendorRoleConfigRetainsCommerceOrderOverview(): void {
    $this->assertContains(
      'access commerce_order overview',
      $this->rolePermissions('vendor'),
    );
  }

}
