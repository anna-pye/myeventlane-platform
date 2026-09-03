<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_rsvp\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\myeventlane_core\Service\EventDateTimeResolver;
use Drupal\myeventlane_rsvp\Service\IcsGenerator;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\myeventlane_rsvp\Service\IcsGenerator
 * @group myeventlane_rsvp
 */
final class IcsGeneratorTimezoneTest extends UnitTestCase {

  /**
   * @covers ::generate
   * @dataProvider timezoneProvider
   */
  public function testEmitsDstSafeUtcCalendarTimes(string $timezone, string $expectedStart, string $expectedEnd): void {
    $generator = new IcsGenerator(
      $this->createMock(LoggerInterface::class),
      $this->eventDateTime(),
    );
    $ics = $generator->generate($this->event($timezone));

    $this->assertStringContainsString('DTSTART:' . $expectedStart, $ics);
    $this->assertStringContainsString('DTEND:' . $expectedEnd, $ics);
    $this->assertStringNotContainsString('TZID', $ics);
    $this->assertStringNotContainsString('VTIMEZONE', $ics);
  }

  /**
   * Provides representative Australian timezone expectations.
   *
   * @return array<string, array{string, string, string}>
   *   Timezone and expected UTC start and end values.
   */
  public static function timezoneProvider(): array {
    return [
      'Sydney daylight saving' => ['Australia/Sydney', '20261210T073000Z', '20261210T093000Z'],
      'Brisbane no daylight saving' => ['Australia/Brisbane', '20261210T083000Z', '20261210T103000Z'],
      'Adelaide daylight saving' => ['Australia/Adelaide', '20261210T080000Z', '20261210T100000Z'],
      'Perth standard time' => ['Australia/Perth', '20261210T103000Z', '20261210T123000Z'],
    ];
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
   * Creates an event mock in the requested timezone.
   */
  private function event(string $timezone): NodeInterface {
    $values = [
      'field_series_timezone' => $timezone,
      'field_event_start' => '2026-12-10T18:30:00',
      'field_event_end' => '2026-12-10T20:30:00',
      'field_location' => '',
      'body' => '',
    ];
    $fields = [];
    foreach ($values as $name => $value) {
      $field = $this->createMock(FieldItemListInterface::class);
      $field->method('isEmpty')->willReturn($value === '');
      $field->method('__get')->willReturnCallback(static fn (string $property) => $property === 'value' ? $value : NULL);
      $field->method('getValue')->willReturn($value === '' ? [] : [['value' => $value]]);
      $fields[$name] = $field;
    }

    $event = $this->createMock(NodeInterface::class);
    $event->method('id')->willReturn(99);
    $event->method('label')->willReturn('Timezone test event');
    $event->method('hasField')->willReturnCallback(static fn (string $field): bool => isset($fields[$field]));
    $event->method('get')->willReturnCallback(static fn (string $field) => $fields[$field]);
    return $event;
  }

}
