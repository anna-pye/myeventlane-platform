<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_attendees\Unit;

use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\myeventlane_event_attendees\Service\VendorAttendeePresentationService;
use Drupal\Tests\UnitTestCase;

/**
 * Regression coverage for attendee custom-answer normalisation.
 *
 * @group myeventlane_event_attendees
 */
final class VendorAttendeePresentationServiceTest extends UnitTestCase {

  public function testNormalizesScalarAndMultiSelectAnswers(): void {
    $attendee = $this->createAttendee([
      'dietary' => [
        'label' => 'Dietary needs',
        'value' => 'Vegetarian',
      ],
      'access' => [
        'label' => 'Access requirements',
        'value' => ['Step-free entry', 'Front-row seating'],
      ],
    ]);

    $this->assertSame([
      [
        'key' => 'dietary',
        'label' => 'Dietary needs',
        'value' => 'Vegetarian',
      ],
      [
        'key' => 'access',
        'label' => 'Access requirements',
        'value' => 'Step-free entry, Front-row seating',
      ],
    ], $this->service()->normalizeCustomAnswers($attendee));
  }

  public function testIgnoresNestedOperationalMetadata(): void {
    $attendee = $this->createAttendee([
      'attendees' => [
        [
          'name' => 'Anna',
          'email' => 'anna@example.com',
        ],
      ],
      'source_note' => 'Imported RSVP',
    ]);

    $this->assertSame([
      [
        'key' => 'source_note',
        'label' => 'source_note',
        'value' => 'Imported RSVP',
      ],
    ], $this->service()->normalizeCustomAnswers($attendee));
  }

  private function service(): VendorAttendeePresentationService {
    $reflection = new \ReflectionClass(VendorAttendeePresentationService::class);
    return $reflection->newInstanceWithoutConstructor();
  }

  /**
   * @param array<string, mixed> $extraData
   */
  private function createAttendee(array $extraData): EventAttendee {
    $attendee = $this->getMockBuilder(EventAttendee::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getExtraDataMap'])
      ->getMock();
    $attendee->method('getExtraDataMap')->willReturn($extraData);
    return $attendee;
  }

}
