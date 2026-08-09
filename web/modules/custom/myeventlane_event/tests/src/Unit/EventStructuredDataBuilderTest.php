<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event\Unit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
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
  public function testFormatsStoredWallClockWithoutTimezoneShift(string $value, ?string $expected): void {
    $reflection = new \ReflectionClass(EventStructuredDataBuilder::class);
    $builder = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('formatFieldValue');

    $this->assertSame($expected, $method->invoke($builder, $value));
  }

  /**
   * Provides stored wall-clock values and expected schema timestamps.
   *
   * @return array<string, array{string, string|null}>
   *   The stored value and expected formatted value.
   */
  public static function wallClockProvider(): array {
    return [
      'standard time' => [
        '2026-08-31T17:00:00',
        '2026-08-31T17:00:00+10:00',
      ],
      'daylight saving time' => [
        '2026-12-10T18:30:00',
        '2026-12-10T18:30:00+11:00',
      ],
      'invalid value' => ['not-a-date', NULL],
      'empty value' => ['', NULL],
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

}
