<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_checkin\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Static routing safety for legacy check-in mutation routes.
 *
 * @group myeventlane_checkin
 */
final class CheckinRoutingSafetyTest extends TestCase {

  public function testToggleRequiresPostAndDefinedPermission(): void {
    $path = dirname(__DIR__, 3) . '/myeventlane_checkin.routing.yml';
    $this->assertFileExists($path);
    $routes = Yaml::parseFile($path);
    $this->assertIsArray($routes);
    $this->assertArrayHasKey('myeventlane_checkin.toggle', $routes);
    $toggle = $routes['myeventlane_checkin.toggle'];
    $this->assertSame('toggle check-in status', $toggle['requirements']['_permission'] ?? NULL);
    $this->assertSame('POST', $toggle['requirements']['_method'] ?? NULL);
  }

  public function testRoutesUseDefinedPermissionStrings(): void {
    $routing = Yaml::parseFile(dirname(__DIR__, 3) . '/myeventlane_checkin.routing.yml');
    $permissions = Yaml::parseFile(dirname(__DIR__, 3) . '/myeventlane_checkin.permissions.yml');
    $defined = array_keys($permissions);
    foreach ($routing as $name => $route) {
      $permission = $route['requirements']['_permission'] ?? NULL;
      $this->assertNotNull($permission, $name);
      $this->assertContains($permission, $defined, $name);
      $this->assertStringNotContainsString('myeventlane_checkin.', (string) $permission, $name);
    }
  }

  public function testToggleControllerBindIsPresentInSource(): void {
    $path = dirname(__DIR__, 3) . '/src/Controller/CheckInController.php';
    $this->assertFileExists($path);
    $raw = (string) file_get_contents($path);
    $this->assertStringContainsString('attendeeBelongsToEvent', $raw);
    $this->assertStringContainsString('AccessDeniedHttpException', $raw);
    $this->assertStringContainsString('shouldConvergeToDoorMode', $raw);
    $this->assertStringContainsString('VendorConsoleTrust', $raw);
  }

}
