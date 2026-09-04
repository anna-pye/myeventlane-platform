<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_core\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\myeventlane_core\Service\EventDateTimeResolver;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_core\Service\EventDateTimeResolver
 * @group myeventlane_core
 */
final class EventDateTimeResolverTest extends UnitTestCase {

  /**
   * @covers ::parseValue
   * @covers ::getTimezoneId
   * @dataProvider australianWallClockProvider
   */
  public function testResolvesAustralianWallClockInstants(string $timezone, string $value, string $expected): void {
    $event = $this->eventWithTimezone($timezone);
    $date = $this->resolver()->parseValue($value, $event);

    $this->assertNotNull($date);
    $this->assertSame($expected, $date->format(\DateTimeInterface::ATOM));
  }

  /**
   * Provides Australian wall-clock examples.
   *
   * @return array<string, array{string, string, string}>
   *   Timezone, wall-clock value, and expected ISO value.
   */
  public static function australianWallClockProvider(): array {
    return [
      'Sydney standard time' => ['Australia/Sydney', '2026-08-31T17:00:00', '2026-08-31T17:00:00+10:00'],
      'Sydney daylight time' => ['Australia/Sydney', '2026-12-10T18:30:00', '2026-12-10T18:30:00+11:00'],
      'Brisbane has no daylight shift' => ['Australia/Brisbane', '2026-12-10T18:30:00', '2026-12-10T18:30:00+10:00'],
      'Adelaide daylight time' => ['Australia/Adelaide', '2026-12-10T18:30:00', '2026-12-10T18:30:00+10:30'],
      'Darwin standard time' => ['Australia/Darwin', '2026-12-10T18:30:00', '2026-12-10T18:30:00+09:30'],
      'Perth standard time' => ['Australia/Perth', '2026-12-10T18:30:00', '2026-12-10T18:30:00+08:00'],
      'Eucla local time' => ['Australia/Eucla', '2026-12-10T18:30:00', '2026-12-10T18:30:00+08:45'],
      'Lord Howe daylight time' => ['Australia/Lord_Howe', '2026-12-10T18:30:00', '2026-12-10T18:30:00+11:00'],
    ];
  }

  /**
   * @covers ::getTimezoneId
   */
  public function testInfersLegacyEventTimezoneFromAustralianState(): void {
    $event = $this->createMock(NodeInterface::class);
    $timezone = $this->emptyField();
    $location = $this->createMock(FieldItemListInterface::class);
    $location->method('isEmpty')->willReturn(FALSE);
    $location->method('getValue')->willReturn([['administrative_area' => 'QLD']]);
    $event->method('hasField')->willReturnMap([
      ['field_series_timezone', TRUE],
      ['field_location', TRUE],
    ]);
    $event->method('get')->willReturnCallback(static fn (string $field) => $field === 'field_location' ? $location : $timezone);

    $this->assertSame('Australia/Brisbane', $this->resolver()->getTimezoneId($event));
  }

  /**
   * @covers ::parseValue
   */
  public function testRejectsNonexistentDaylightSavingWallClockTime(): void {
    $event = $this->eventWithTimezone('Australia/Sydney');
    $this->assertNull($this->resolver()->parseValue('2026-10-04T02:30:00', $event));
  }

  /**
   * @covers ::formatFieldForIcalendar
   */
  public function testFormatsUtcCalendarValueAcrossDaylightSaving(): void {
    $event = $this->eventWithTimezone('Australia/Adelaide', '2026-12-10T18:30:00');
    $this->assertSame('20261210T080000Z', $this->resolver()->formatFieldForIcalendar($event, 'field_event_start'));
  }

  /**
   * Creates the event date and time resolver under test.
   */
  private function resolver(string $siteTimezone = 'Australia/Sydney'): EventDateTimeResolver {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('timezone.default')->willReturn($siteTimezone);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('system.date')->willReturn($config);
    return new EventDateTimeResolver($factory);
  }

  /**
   * Creates an event mock with timezone and optional start values.
   */
  private function eventWithTimezone(string $timezone, ?string $start = NULL): NodeInterface {
    $event = $this->createMock(NodeInterface::class);
    $timezoneField = $this->valueField($timezone);
    $startField = $start === NULL ? $this->emptyField() : $this->valueField($start);
    $location = $this->emptyField();
    $event->method('hasField')->willReturnCallback(static fn (string $field): bool => in_array($field, [
      'field_series_timezone',
      'field_location',
      'field_event_start',
    ], TRUE));
    $event->method('get')->willReturnCallback(static fn (string $field) => match ($field) {
      'field_series_timezone' => $timezoneField,
      'field_event_start' => $startField,
      default => $location,
    });
    return $event;
  }

  /**
   * Creates a populated field item list mock.
   */
  private function valueField(string $value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__get')->with('value')->willReturn($value);
    $field->method('getValue')->willReturn([['value' => $value]]);
    return $field;
  }

  /**
   * Creates an empty field item list mock.
   */
  private function emptyField(): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(TRUE);
    return $field;
  }

}
