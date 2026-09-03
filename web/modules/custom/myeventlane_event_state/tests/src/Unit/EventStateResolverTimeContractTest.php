<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_state\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\myeventlane_core\Service\EventDateTimeResolver;
use Drupal\myeventlane_event_state\Service\EventStateResolver;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_event_state\Service\EventStateResolver
 * @group myeventlane_event_state
 */
final class EventStateResolverTimeContractTest extends UnitTestCase {

  /**
   * @covers ::resolveState
   */
  public function testBrisbaneEventEndsAtItsLocalWallClockTime(): void {
    $event = $this->event([
      'field_series_timezone' => 'Australia/Brisbane',
      'field_event_start' => '2026-12-10T17:00:00',
      'field_event_end' => '2026-12-10T18:00:00',
    ]);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(strtotime('2026-12-10T09:00:00Z'));
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);

    $resolver = new EventStateResolver(
      $time,
      $this->createMock(EntityTypeManagerInterface::class),
      $cache,
      $this->eventDateTime(),
      NULL,
    );

    $this->assertSame(EventStateResolver::STATE_ENDED, $resolver->resolveState($event));
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
   * Creates an event mock from field values.
   *
   * @param array<string, string> $values
   *   Values keyed by event field name.
   */
  private function event(array $values): NodeInterface {
    $fields = [];
    foreach ($values as $name => $value) {
      $field = $this->createMock(FieldItemListInterface::class);
      $field->method('isEmpty')->willReturn(FALSE);
      $field->method('__get')->with('value')->willReturn($value);
      $field->method('getValue')->willReturn([['value' => $value]]);
      $fields[$name] = $field;
    }
    foreach (['field_event_state_override', 'field_sales_start', 'field_sales_end', 'field_location'] as $name) {
      $field = $this->createMock(FieldItemListInterface::class);
      $field->method('isEmpty')->willReturn(TRUE);
      $fields[$name] = $field;
    }

    $event = $this->createMock(NodeInterface::class);
    $event->method('id')->willReturn(42);
    $event->method('isPublished')->willReturn(TRUE);
    $event->method('getCreatedTime')->willReturn(0);
    $event->method('hasField')->willReturnCallback(static fn (string $name): bool => isset($fields[$name]));
    $event->method('get')->willReturnCallback(static fn (string $name) => $fields[$name]);
    return $event;
  }

}
