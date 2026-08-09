<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_attendees\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the legacy check-in retirement compatibility contract.
 *
 * @group myeventlane_event_attendees
 */
final class LegacyCheckinRetirementContractTest extends TestCase {

  /**
   * Legacy read routes remain access-controlled Door Mode redirects.
   */
  public function testLegacyReadRoutesRedirectToDoorMode(): void {
    $routing = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_event_attendees.routing.yml');
    $this->assertNotFalse($routing);

    foreach (['myeventlane_checkin.page', 'myeventlane_checkin.scan', 'myeventlane_checkin.list'] as $route) {
      $this->assertStringContainsString("{$route}:", $routing);
    }
    $this->assertStringContainsString('redirectCheckinToDoorMode', $routing);
    $this->assertStringContainsString('myeventlane_vendor.access.vendor_console:access', $routing);
  }

  /**
   * Superseded mutation and search routes are not retained.
   */
  public function testLegacyMutationRoutesAreRemoved(): void {
    $routing = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_event_attendees.routing.yml');
    $this->assertNotFalse($routing);
    $this->assertStringNotContainsString('myeventlane_checkin.toggle:', $routing);
    $this->assertStringNotContainsString('myeventlane_checkin.search:', $routing);
  }

  /**
   * Transition config keeps the empty shim while removing role dependencies.
   */
  public function testActiveConfigurationSafelyStagesLegacyModuleRemoval(): void {
    $repository_root = dirname(__DIR__, 7);
    $extension_config = file_get_contents($repository_root . '/config/sync/core.extension.yml');
    $this->assertNotFalse($extension_config);
    $this->assertStringContainsString('  myeventlane_checkin: 0', $extension_config);

    foreach (['anonymous', 'authenticated', 'vendor'] as $role) {
      $role_config = file_get_contents($repository_root . "/config/sync/user.role.{$role}.yml");
      $this->assertNotFalse($role_config);
      $this->assertStringNotContainsString('myeventlane_checkin', $role_config);
      $this->assertStringNotContainsString("'access check-in'", $role_config);
      $this->assertStringNotContainsString("'scan qr codes'", $role_config);
      $this->assertStringNotContainsString("'toggle check-in status'", $role_config);
    }
  }

  /**
   * The update hook removes active role dependencies before config import.
   */
  public function testUpdateHookCleansLegacyRoleState(): void {
    $install = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_event_attendees.install');
    $this->assertNotFalse($install);
    $this->assertStringContainsString('myeventlane_event_attendees_update_8003', $install);
    $this->assertStringContainsString("'myeventlane_checkin'", $install);
    $this->assertStringContainsString('revokePermission', $install);
    $this->assertStringContainsString('$role->save()', $install);
  }

}
