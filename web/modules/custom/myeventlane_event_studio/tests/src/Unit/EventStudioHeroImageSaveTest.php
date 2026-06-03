<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\file\FileInterface;
use Drupal\myeventlane_event_studio\Service\EventStudioSaveService;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * @group myeventlane_event_studio
 */
final class EventStudioHeroImageSaveTest extends UnitTestCase {

  /**
   * Unreadable hero files must fail save with a user-facing error, not clear silently.
   */
  public function testApplyHeroImagePayloadReturnsErrorForUnreadableFile(): void {
    $file = $this->createMock(FileInterface::class);
    $file->method('id')->willReturn('99');
    $file->method('getFileUri')->willReturn('public://missing-hero.jpg');
    $file->method('getMimeType')->willReturn('image/jpeg');

    $file_storage = $this->createMock(EntityStorageInterface::class);
    $file_storage->method('load')->with(99)->willReturn($file);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturnMap([
      ['file', $file_storage],
    ]);

    $file_system = $this->createMock(FileSystemInterface::class);
    $file_system->method('realpath')->with('public://missing-hero.jpg')->willReturn(FALSE);

    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translate')->willReturnCallback(
      static fn (string $string, array $args = [], array $options = []): string => $string,
    );

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->with('field_event_image')->willReturn(TRUE);
    $node->expects($this->never())->method('set');

    $service = $this->buildPartialSaveService($entity_type_manager, $file_system, $translation);
    $errors = $this->invokeApplyHeroImagePayload($service, $node, [
      'field_event_image' => 99,
      'field_event_image_alt' => 'Cover alt',
    ], FALSE);

    $this->assertCount(1, $errors);
    $this->assertStringContainsString('missing or unreadable', $errors[0]);
  }

  private function buildPartialSaveService(
    EntityTypeManagerInterface $entity_type_manager,
    FileSystemInterface $file_system,
    TranslationInterface $translation,
  ): EventStudioSaveService {
    $service = (new ReflectionClass(EventStudioSaveService::class))->newInstanceWithoutConstructor();
    $this->setPrivateProperty($service, 'entityTypeManager', $entity_type_manager);
    $this->setPrivateProperty($service, 'fileSystem', $file_system);
    $this->setPrivateProperty($service, 'stringTranslation', $translation);
    $this->setPrivateProperty($service, 'logger', $this->createMock(LoggerInterface::class));

    return $service;
  }

  private function setPrivateProperty(object $object, string $property, mixed $value): void {
    $ref = new ReflectionProperty($object, $property);
    $ref->setAccessible(TRUE);
    $ref->setValue($object, $value);
  }

  /**
   * @param array<string, mixed> $payload
   *
   * @return list<string>
   */
  private function invokeApplyHeroImagePayload(
    EventStudioSaveService $service,
    NodeInterface $node,
    array $payload,
    bool $draft,
  ): array {
    $method = new ReflectionMethod(EventStudioSaveService::class, 'applyHeroImagePayload');
    $method->setAccessible(TRUE);

    /** @var list<string> $errors */
    $errors = $method->invoke($service, $node, $payload, $draft);
    return $errors;
  }

}
