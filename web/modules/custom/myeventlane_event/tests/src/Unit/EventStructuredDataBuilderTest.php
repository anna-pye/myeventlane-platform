<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event\Unit;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\myeventlane_event\Service\BookingFlowResolver;
use Drupal\myeventlane_event\Service\EventStructuredDataBuilder;
use Drupal\myeventlane_event_state\Service\EventStateResolver;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_event\Service\EventStructuredDataBuilder
 * @group myeventlane_event
 */
final class EventStructuredDataBuilderTest extends UnitTestCase {

  /**
   * @covers ::resolveEventStatus
   */
  public function testCancelledEventsExposeCancelledSchemaStatus(): void {
    $stateField = $this->createMock(FieldItemListInterface::class);
    $stateField->method('isEmpty')->willReturn(FALSE);
    $stateField->method('__get')->with('value')->willReturn(EventStateResolver::STATE_CANCELLED);

    $event = $this->createMock(NodeInterface::class);
    $event->method('hasField')->with('field_event_state')->willReturn(TRUE);
    $event->method('get')->with('field_event_state')->willReturn($stateField);

    $builder = (new \ReflectionClass(EventStructuredDataBuilder::class))->newInstanceWithoutConstructor();
    $method = (new \ReflectionClass(EventStructuredDataBuilder::class))->getMethod('resolveEventStatus');

    self::assertSame('https://schema.org/EventCancelled', $method->invoke($builder, $event));
  }

  /**
   * @covers ::resolveOffer
   */
  public function testUnavailableAndExternalEventsDoNotExposeInternalOffers(): void {
    $event = $this->createMock(NodeInterface::class);
    $builder = (new \ReflectionClass(EventStructuredDataBuilder::class))->newInstanceWithoutConstructor();
    $method = (new \ReflectionClass(EventStructuredDataBuilder::class))->getMethod('resolveOffer');

    self::assertNull($method->invoke($builder, $event, [
      'mode' => BookingFlowResolver::MODE_UNAVAILABLE,
    ]));
    self::assertNull($method->invoke($builder, $event, [
      'mode' => BookingFlowResolver::MODE_EXTERNAL,
    ]));
  }

  /**
   * Confirms page tags and JSON-LD consume the same metadata service.
   */
  public function testSharedMetadataServiceContract(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $module = file_get_contents($moduleRoot . '/myeventlane_event.module');
    $services = file_get_contents($moduleRoot . '/myeventlane_event.services.yml');
    $builder = file_get_contents($moduleRoot . '/src/Service/EventStructuredDataBuilder.php');

    self::assertIsString($module);
    self::assertStringContainsString("service('myeventlane_event.metadata_builder')", $module);
    self::assertIsString($services);
    self::assertStringContainsString('myeventlane_event.metadata_builder:', $services);
    self::assertIsString($builder);
    self::assertStringContainsString('EventMetadataBuilderInterface $metadataBuilder', $builder);
  }

}
