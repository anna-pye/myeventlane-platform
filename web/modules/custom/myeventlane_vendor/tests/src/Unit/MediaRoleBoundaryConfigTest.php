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

    self::assertContains('access files overview', $permissions);
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
    self::assertNotContains('access files overview', $permissions);
    self::assertNotContains('access content overview', $permissions);
    self::assertNotContains('access toolbar', $permissions);
  }

  /**
   * Verifies the core Files overview is permission-gated, not owner-filtered.
   */
  public function testFilesOverviewPermissionRemainsStaffOnly(): void {
    $root = dirname(__DIR__, 7);
    $path = $root . '/web/core/modules/file/config/optional/views.view.files.yml';
    self::assertFileExists($path);

    $data = Yaml::parseFile($path);
    self::assertIsArray($data);
    self::assertSame('admin/content/files', $data['display']['page_1']['display_options']['path'] ?? NULL);
    self::assertSame(
      'access files overview',
      $data['display']['default']['display_options']['access']['options']['perm'] ?? NULL,
    );

    self::assertNotContains('access files overview', $this->rolePermissions('vendor'));
    self::assertContains('access files overview', $this->rolePermissions('content_editor'));
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
