<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_checkout_paragraph\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Static routing safety for checkout_paragraph export / attendee routes.
 *
 * @group myeventlane_checkout_paragraph
 */
final class CheckoutParagraphExportRoutingSafetyTest extends TestCase {

  public function testExportAndAttendeeRoutesUseCustomAccessNotAccessContent(): void {
    $path = dirname(__DIR__, 3) . '/myeventlane_checkout_paragraph.routing.yml';
    $this->assertFileExists($path);
    $routes = Yaml::parseFile($path);
    $this->assertIsArray($routes);

    $targets = [
      'myeventlane_checkout_paragraph.vendor_attendees' => '\Drupal\myeventlane_checkout_paragraph\Controller\VendorOrderController::access',
      'myeventlane_checkout_paragraph.export_csv' => '\Drupal\myeventlane_checkout_paragraph\Controller\AttendeeExportController::access',
      'myeventlane_checkout_paragraph.export_csv_queue' => '\Drupal\myeventlane_checkout_paragraph\Controller\AttendeeExportController::access',
    ];

    foreach ($targets as $name => $expectedAccess) {
      $this->assertArrayHasKey($name, $routes);
      $requirements = $routes[$name]['requirements'] ?? [];
      $this->assertSame($expectedAccess, $requirements['_custom_access'] ?? NULL, $name);
      $this->assertArrayNotHasKey('_permission', $requirements, $name);
    }
  }

}
