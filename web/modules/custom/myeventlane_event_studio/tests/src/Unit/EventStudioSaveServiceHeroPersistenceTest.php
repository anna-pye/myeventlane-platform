<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_event_studio\Service\EventStudioSaveService;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * @group myeventlane_event_studio
 */
final class EventStudioSaveServiceHeroPersistenceTest extends UnitTestCase {

  /**
   * @covers ::shouldApplyHeroImagePayload
   */
  public function testShouldApplyHeroImagePayloadSkipsWorkspaceSections(): void {
    $service = $this->buildPartialSaveService();
    $method = new ReflectionMethod(EventStudioSaveService::class, 'shouldApplyHeroImagePayload');
    $method->setAccessible(TRUE);

    $this->assertFalse($method->invoke($service, ['studio_section' => 'content']));
    $this->assertFalse($method->invoke($service, ['studio_section' => 'information']));
    $this->assertTrue($method->invoke($service, ['studio_section' => '']));
  }

  /**
   * @covers ::brandingHeroExplicitRemovalRequested
   */
  public function testBrandingHeroExplicitRemovalRequestedRequiresEmptyFids(): void {
    $service = $this->buildPartialSaveService();
    $method = new ReflectionMethod(EventStudioSaveService::class, 'brandingHeroExplicitRemovalRequested');
    $method->setAccessible(TRUE);

    $this->assertFalse($method->invoke($service, NULL));
    $this->assertFalse($method->invoke($service, [
      0 => ['focal_point' => '50,50'],
    ]));
    $this->assertTrue($method->invoke($service, [
      0 => ['fids' => ''],
    ]));
  }

  private function buildPartialSaveService(): EventStudioSaveService {
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translate')->willReturnCallback(
      static fn (string $string, array $args = [], array $options = []): string => $string,
    );

    $service = (new ReflectionClass(EventStudioSaveService::class))->newInstanceWithoutConstructor();
    $this->setPrivateProperty($service, 'entityTypeManager', $this->createMock(EntityTypeManagerInterface::class));
    $this->setPrivateProperty($service, 'fileSystem', $this->createMock(FileSystemInterface::class));
    $this->setPrivateProperty($service, 'stringTranslation', $translation);
    $this->setPrivateProperty($service, 'logger', $this->createMock(LoggerInterface::class));

    return $service;
  }

  private function setPrivateProperty(object $object, string $property, mixed $value): void {
    $ref = new ReflectionProperty($object, $property);
    $ref->setAccessible(TRUE);
    $ref->setValue($object, $value);
  }

}
