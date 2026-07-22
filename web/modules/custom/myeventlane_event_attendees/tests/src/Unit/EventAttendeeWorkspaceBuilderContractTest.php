<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_attendees\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contract tests for VX2 attendee workspace builder surface.
 *
 * @group myeventlane_event_attendees
 */
final class EventAttendeeWorkspaceBuilderContractTest extends TestCase {

  /**
   * Asserts the workspace builder service is registered.
   */
  public function testBuilderServiceIsRegistered(): void {
    $services = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_event_attendees.services.yml');
    $this->assertNotFalse($services);
    $this->assertStringContainsString('myeventlane_event_attendees.attendee_workspace_builder', $services);
    $this->assertStringContainsString('EventAttendeeWorkspaceBuilder', $services);
  }

  /**
   * Asserts filter vocabulary matches the product story.
   */
  public function testFilterVocabularyMatchesProductStory(): void {
    $builder = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventAttendeeWorkspaceBuilder.php');
    $this->assertNotFalse($builder);
    foreach (['ticket', 'rsvp', 'waitlist', 'checked_in', 'not_checked_in', 'refunded', 'cancelled'] as $filter) {
      $this->assertStringContainsString("'$filter'", $builder);
    }
  }

  /**
   * Asserts card Check in is gated to registered operational state only.
   */
  public function testCheckInActionRequiresRegisteredOperationalState(): void {
    $builder = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventAttendeeWorkspaceBuilder.php');
    $this->assertNotFalse($builder);
    $this->assertStringContainsString('MelAttendeeExportBuilder::STATE_REGISTERED', $builder);
    $this->assertStringContainsString('vendor_checkin', $builder);
    $this->assertStringNotContainsString(
      '!$attendee->isCheckedIn() && $status !== EventAttendee::STATUS_CANCELLED',
      $builder,
    );
  }

}
