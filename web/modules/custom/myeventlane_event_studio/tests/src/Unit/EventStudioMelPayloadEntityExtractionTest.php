<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\myeventlane_event_studio\Service\EventHighlightHelper;
use Drupal\myeventlane_event_studio\Service\EventStudioMelPayloadService;
use Drupal\myeventlane_event_studio\Service\QuestionFieldTypeRegistry;
use Drupal\Tests\UnitTestCase;
use ReflectionMethod;

/**
 * @group myeventlane_event_studio
 */
final class EventStudioMelPayloadEntityExtractionTest extends UnitTestCase {

  /**
   * @covers ::extractMultipleEntityIds
   */
  public function testExtractMultipleEntityIdsAcceptsEntityInterfaceObjects(): void {
    $first = $this->createMock(EntityInterface::class);
    $first->method('id')->willReturn('12');
    $second = $this->createMock(EntityInterface::class);
    $second->method('id')->willReturn('34');

    $service = new EventStudioMelPayloadService(
      $this->createMock(EventHighlightHelper::class),
      $this->createMock(QuestionFieldTypeRegistry::class),
    );

    $method = new ReflectionMethod(EventStudioMelPayloadService::class, 'extractMultipleEntityIds');
    $method->setAccessible(TRUE);

    /** @var list<int> $ids */
    $ids = $method->invoke($service, [$first, $second]);

    $this->assertSame([12, 34], $ids);
  }

  /**
   * @covers ::extractMultipleEntityIds
   */
  public function testExtractMultipleEntityIdsPreservesTargetIdArrays(): void {
    $service = new EventStudioMelPayloadService(
      $this->createMock(EventHighlightHelper::class),
      $this->createMock(QuestionFieldTypeRegistry::class),
    );

    $method = new ReflectionMethod(EventStudioMelPayloadService::class, 'extractMultipleEntityIds');
    $method->setAccessible(TRUE);

    /** @var list<int> $ids */
    $ids = $method->invoke($service, [
      ['target_id' => 5],
      ['target_id' => 6],
    ]);

    $this->assertSame([5, 6], $ids);
  }

}
