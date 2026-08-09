<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_account\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Protects the private account and public organiser profile boundary.
 *
 * @group myeventlane_account
 */
final class LegacyUserProfileRouteContractTest extends UnitTestCase {

  /**
   * Confirms own user routes do not expose an administrator-only bypass.
   */
  public function testOwnUserRoutesRedirectRegardlessOfAdministratorRole(): void {
    $root = dirname(__DIR__, 3);
    $accountSubscriber = file_get_contents($root . '/src/EventSubscriber/CustomerAccountRouteRedirectSubscriber.php');
    $surfaceSubscriber = file_get_contents(dirname($root) . '/myeventlane_surface/src/EventSubscriber/SurfaceRouteSubscriber.php');
    $following = file_get_contents(dirname($root, 4) . '/config/sync/flag.flag.following.yml');

    self::assertIsString($accountSubscriber);
    self::assertStringNotContainsString("hasPermission('administer users')", $accountSubscriber);
    self::assertStringContainsString("'myeventlane_account.dashboard'", $accountSubscriber);

    self::assertIsString($surfaceSubscriber);
    self::assertStringNotContainsString("hasPermission('administer users')", $surfaceSubscriber);
    self::assertStringContainsString("'myeventlane_account.dashboard'", $surfaceSubscriber);

    self::assertIsString($following);
    self::assertStringContainsString('show_as_field: false', $following);
  }

}
