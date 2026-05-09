<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_checkout_flow\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Ensures canonical vendor operations + door routes stay registered.
 *
 * @group myeventlane_checkout_flow
 */
final class MelVendorOperationsRoutesYamlTest extends TestCase {

  public function testVendorDoorRoutesRegistered(): void {
    $path = dirname(__DIR__, 4) . '/myeventlane_event_attendees/myeventlane_event_attendees.routing.yml';
    self::assertFileIsReadable($path);
    $raw = (string) file_get_contents($path);
    self::assertStringContainsString('myeventlane_event_attendees.vendor_operations_door:', $raw);
    self::assertStringContainsString('/vendor/events/{node}/operations/door', $raw);
    self::assertStringContainsString('myeventlane_event_attendees.vendor_operations_door_validate:', $raw);
    self::assertStringContainsString('/vendor/events/{node}/operations/door/validate', $raw);
  }

}
