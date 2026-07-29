<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_checkin\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects legacy check-in visibility for active ticket attendees.
 *
 * @group myeventlane_checkin
 */
final class CheckInRepositoryActiveStatusContractTest extends TestCase {

  public function testTicketRepositoryRetainsCheckedInAttendees(): void {
    $root = dirname(__DIR__, 7);
    $repository = file_get_contents($root . '/web/modules/custom/myeventlane_attendee/src/Repository/TicketAttendeeRepository.php');

    self::assertIsString($repository);
    self::assertGreaterThanOrEqual(
      5,
      substr_count($repository, 'EventAttendee::STATUS_CHECKED_IN'),
    );
    self::assertStringContainsString(
      "EventAttendee::STATUS_CHECKED_IN,\n        ], 'IN')",
      $repository,
    );
    self::assertStringContainsString(
      "->condition('status', EventAttendee::STATUS_CHECKED_IN)",
      $repository,
    );
    self::assertStringNotContainsString(
      "->condition('checked_in', TRUE)",
      $repository,
    );
  }

}
