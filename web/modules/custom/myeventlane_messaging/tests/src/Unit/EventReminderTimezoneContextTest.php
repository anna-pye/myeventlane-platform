<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_messaging\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\myeventlane_core\Service\EventDateTimeResolver;
use Drupal\myeventlane_messaging\Service\EventReminderScheduler;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_messaging\Service\EventReminderScheduler
 * @group myeventlane_messaging
 */
final class EventReminderTimezoneContextTest extends UnitTestCase {

  /**
   * @covers ::appendEventDateTimeContext
   */
  public function testReminderCopyUsesTheEventsOwnTimezone(): void {
    $dateFormatter = $this->createMock(DateFormatterInterface::class);
    $dateFormatter->method('format')->willReturnCallback(
      static function (int $timestamp, string $type, string $format, ?string $timezone): string {
        return (new \DateTimeImmutable('@' . $timestamp))
          ->setTimezone(new \DateTimeZone($timezone ?? 'UTC'))
          ->format($format);
      },
    );

    $reflection = new \ReflectionClass(EventReminderScheduler::class);
    $scheduler = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('dateFormatter')->setValue($scheduler, $dateFormatter);
    $reflection->getProperty('eventDateTime')->setValue($scheduler, $this->eventDateTime());

    $context = [];
    $scheduler->appendEventDateTimeContext($context, $this->event());

    $this->assertSame('December 10, 2026 6:30pm AEST', $context['event_start']);
    $this->assertSame('December 10, 2026', $context['event_start_date']);
    $this->assertSame('6:30pm AEST', $context['event_start_time']);
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
   * Creates a Brisbane event mock.
   */
  private function event(): NodeInterface {
    $timezone = $this->valueField('Australia/Brisbane');
    $start = $this->valueField('2026-12-10T18:30:00');
    $location = $this->emptyField();
    $event = $this->createMock(NodeInterface::class);
    $event->method('hasField')->willReturnCallback(static fn (string $field): bool => in_array($field, [
      'field_series_timezone',
      'field_event_start',
      'field_location',
    ], TRUE));
    $event->method('get')->willReturnCallback(static fn (string $field) => match ($field) {
      'field_series_timezone' => $timezone,
      'field_event_start' => $start,
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
