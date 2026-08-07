<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_admin_dashboard\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards venue discovery from the Platform Control Centre.
 *
 * @group myeventlane_admin_dashboard
 */
final class AdminVenueNavigationContractTest extends TestCase {

  /**
   * Ensures staff can reach the canonical venue list from the sidebar.
   */
  public function testVenueCollectionIsInPlatformControlNavigation(): void {
    $source = file_get_contents(
      dirname(__DIR__, 3) . '/src/Plugin/Block/MelAdminSidebarNavBlock.php',
    );

    self::assertIsString($source);
    self::assertStringContainsString(
      "'entity.myeventlane_venue.collection' => 'Venues'",
      $source,
    );
    self::assertStringContainsString(
      'checkNamedRoute($route, [], $this->currentUser, TRUE)',
      $source,
    );

    $eventsPosition = strpos($source, "'myeventlane_reporting.admin.events' => 'Events'");
    $venuesPosition = strpos($source, "'entity.myeventlane_venue.collection' => 'Venues'");
    $financePosition = strpos($source, "'myeventlane_reporting.admin.finance' => 'Finance'");

    self::assertIsInt($eventsPosition);
    self::assertIsInt($venuesPosition);
    self::assertIsInt($financePosition);
    self::assertGreaterThan($eventsPosition, $venuesPosition);
    self::assertLessThan($financePosition, $venuesPosition);
  }

}
