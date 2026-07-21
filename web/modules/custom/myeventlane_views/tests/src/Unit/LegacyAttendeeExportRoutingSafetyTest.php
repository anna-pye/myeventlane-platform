<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_views\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Static routing safety for legacy attendee CSV export.
 *
 * @group myeventlane_views
 */
final class LegacyAttendeeExportRoutingSafetyTest extends TestCase {

  public function testLegacyExportUsesCustomAccessNotAccessContent(): void {
    $path = dirname(__DIR__, 3) . '/myeventlane_views.routing.yml';
    $this->assertFileExists($path);
    $routes = Yaml::parseFile($path);
    $this->assertIsArray($routes);
    $this->assertArrayHasKey('myeventlane_views.attendee_csv', $routes);
    $requirements = $routes['myeventlane_views.attendee_csv']['requirements'] ?? [];
    $this->assertSame(
      'myeventlane_views.access.attendee_csv_export:access',
      $requirements['_custom_access'] ?? NULL,
    );
    $this->assertArrayNotHasKey('_permission', $requirements);
  }

}
