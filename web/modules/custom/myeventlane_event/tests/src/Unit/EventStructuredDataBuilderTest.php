<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\myeventlane_core\Service\EventDateTimeResolver;
use Drupal\myeventlane_event\Service\EventStructuredDataBuilder;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_event\Service\EventStructuredDataBuilder
 * @group myeventlane_event
 */
final class EventStructuredDataBuilderTest extends UnitTestCase {

  /**
   * @covers ::formatFieldValue
   * @dataProvider wallClockProvider
   */
  public function testFormatsStoredWallClockWithoutTimezoneShift(string $value, string $timezone, ?string $expected): void {
    $reflection = new \ReflectionClass(EventStructuredDataBuilder::class);
    $builder = $reflection->newInstanceWithoutConstructor();
    $property = $reflection->getProperty('eventDateTime');
    $property->setValue($builder, $this->eventDateTime());
    $method = $reflection->getMethod('formatFieldValue');

    $this->assertSame($expected, $method->invoke($builder, $value, $this->eventWithTimezone($timezone)));
  }

  /**
   * Provides stored wall-clock values and expected schema timestamps.
   *
   * @return array<string, array{string, string, string|null}>
   *   The stored value and expected formatted value.
   */
  public static function wallClockProvider(): array {
    return [
      'standard time' => [
        '2026-08-31T17:00:00',
        'Australia/Sydney',
        '2026-08-31T17:00:00+10:00',
      ],
      'daylight saving time' => [
        '2026-12-10T18:30:00',
        'Australia/Sydney',
        '2026-12-10T18:30:00+11:00',
      ],
      'Queensland summer time' => [
        '2026-12-10T18:30:00',
        'Australia/Brisbane',
        '2026-12-10T18:30:00+10:00',
      ],
      'invalid value' => ['not-a-date', 'Australia/Sydney', NULL],
      'empty value' => ['', 'Australia/Sydney', NULL],
    ];
  }

  /**
   * @covers ::resolveOrganizer
   */
  public function testOrganizerUsesReferencedPublicProfileLabel(): void {
    $vendor = $this->createMock(EntityInterface::class);
    $vendor->method('label')->willReturn('Westside Community Arts');

    $vendorField = $this->createMock(FieldItemListInterface::class);
    $vendorField->method('isEmpty')->willReturn(FALSE);
    $vendorField->method('__get')->with('entity')->willReturn($vendor);

    $event = $this->createMock(NodeInterface::class);
    $event->method('hasField')->with('field_event_vendor')->willReturn(TRUE);
    $event->method('get')->with('field_event_vendor')->willReturn($vendorField);

    $reflection = new \ReflectionClass(EventStructuredDataBuilder::class);
    $builder = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('resolveOrganizer');

    $this->assertSame([
      '@type' => 'Organization',
      'name' => 'Westside Community Arts',
    ], $method->invoke($builder, $event));
  }

  /**
   * Creates the event date and time resolver under test.
   */
  private function eventDateTime(): EventDateTimeResolver {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('timezone.default')->willReturn('Australia/Sydney');
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('system.date')->willReturn($config);
    return new EventDateTimeResolver($factory);
  }

  /**
   * Creates an event mock with a timezone.
   */
  private function eventWithTimezone(string $timezone): NodeInterface {
    $timezoneField = $this->createMock(FieldItemListInterface::class);
    $timezoneField->method('isEmpty')->willReturn(FALSE);
    $timezoneField->method('__get')->with('value')->willReturn($timezone);
    $timezoneField->method('getValue')->willReturn([['value' => $timezone]]);
    $locationField = $this->createMock(FieldItemListInterface::class);
    $locationField->method('isEmpty')->willReturn(TRUE);

    $event = $this->createMock(NodeInterface::class);
    $event->method('hasField')->willReturnCallback(
      static fn (string $field): bool => in_array(
        $field,
        ['field_series_timezone', 'field_location'],
        TRUE,
      ),
    );
    $event->method('get')->willReturnCallback(
      static fn (string $field) => $field === 'field_series_timezone'
        ? $timezoneField
        : $locationField,
    );
    return $event;
  }

}
