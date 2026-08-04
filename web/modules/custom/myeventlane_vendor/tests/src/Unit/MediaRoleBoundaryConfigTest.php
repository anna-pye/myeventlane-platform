<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards staff and organiser media-management boundaries.
 *
 * @group myeventlane_vendor
 */
final class MediaRoleBoundaryConfigTest extends TestCase {

  /**
   * Verifies that staff can manage every media asset.
   */
  public function testContentEditorHasFullMediaManagementAccess(): void {
    $permissions = $this->rolePermissions('content_editor');

    self::assertContains('access media overview', $permissions);
    self::assertContains('access all myeventlane media assets', $permissions);
    self::assertContains('administer media', $permissions);
  }

  /**
   * Verifies that organisers retain the non-admin ownership boundary.
   */
  public function testVendorRoleOmitsAdministrationPermissions(): void {
    $permissions = $this->rolePermissions('vendor');

    self::assertNotContains('access all myeventlane media assets', $permissions);
    self::assertNotContains('administer media', $permissions);
    self::assertNotContains('access content overview', $permissions);
    self::assertNotContains('access toolbar', $permissions);
  }

  /**
   * Loads permissions from a synchronised role configuration file.
   *
   * @return list<string>
   *   Permission machine names assigned to the role.
   */
  private function rolePermissions(string $role): array {
    $path = dirname(__DIR__, 7) . '/config/sync/user.role.' . $role . '.yml';
    self::assertFileExists($path);

    $data = Yaml::parseFile($path);
    self::assertIsArray($data);
    $permissions = $data['permissions'] ?? [];
    self::assertIsArray($permissions);

    return array_values(array_filter($permissions, 'is_string'));
  }

}
