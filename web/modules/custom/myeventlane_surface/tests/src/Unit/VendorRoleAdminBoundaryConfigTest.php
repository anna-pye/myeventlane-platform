<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_surface\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the vendor role against Drupal administration access.
 *
 * @group myeventlane_surface
 */
final class VendorRoleAdminBoundaryConfigTest extends TestCase {

  /**
   * Verifies that organisers cannot access the content overview or toolbar.
   */
  public function testVendorRoleOmitsAdministrationPermissions(): void {
    $path = dirname(__DIR__, 7) . '/config/sync/user.role.vendor.yml';
    $this->assertFileExists($path);

    $data = Yaml::parseFile($path);
    $this->assertIsArray($data);
    $permissions = $data['permissions'] ?? [];
    $this->assertIsArray($permissions);

    $this->assertNotContains('access content overview', $permissions);
    $this->assertNotContains('access toolbar', $permissions);
  }

}
