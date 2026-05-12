<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_event_studio\Service\EntityAutocompleteMelNormalizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\myeventlane_event_studio\Service\EntityAutocompleteMelNormalizer
 *
 * @group myeventlane_event_studio
 */
final class EntityAutocompleteMelNormalizerTest extends TestCase {

  /**
   * @covers ::normalizeSingle
   *
   * @dataProvider singleReferenceArrayProvider
   */
  public function testNormalizeSingleAcceptsSingleReferenceArrayShapes(array $value): void {
    $entity = $this->entity('commerce_product');
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('load')
      ->with(42)
      ->willReturn($entity);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('commerce_product')
      ->willReturn($storage);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('warning');

    $normalizer = new EntityAutocompleteMelNormalizer($entityTypeManager, $logger);

    $this->assertSame($entity, $normalizer->normalizeSingle($value, 'commerce_product', 'mel.field_product_target'));
  }

  /**
   * @return iterable<string, array{0: array<mixed>}>
   */
  public static function singleReferenceArrayProvider(): iterable {
    yield 'field item value' => [['target_id' => 42]];
    yield 'field item list value' => [[['target_id' => 42]]];
    yield 'single numeric list value' => [[42]];
  }

  /**
   * @covers ::normalizeSingle
   */
  public function testNormalizeSingleRejectsAmbiguousArrays(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->never())->method('getStorage');

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with(
        'Discarded invalid entity_autocomplete default for @key (expected entity type @type): @info',
        [
          '@key' => 'mel.field_product_target',
          '@type' => 'commerce_product',
          '@info' => 'array',
        ],
      );

    $normalizer = new EntityAutocompleteMelNormalizer($entityTypeManager, $logger);

    $this->assertNull($normalizer->normalizeSingle([
      ['target_id' => 42],
      ['target_id' => 43],
    ], 'commerce_product', 'mel.field_product_target'));
  }

  /**
   * @covers ::normalizeValuesForForm
   */
  public function testNormalizeValuesForFormUsesAutocompleteElementMetadata(): void {
    $venue = $this->entity('myeventlane_venue');
    $category = $this->entity('taxonomy_term');

    $venueStorage = $this->createMock(EntityStorageInterface::class);
    $venueStorage->expects($this->once())
      ->method('load')
      ->with(100)
      ->willReturn($venue);

    $termStorage = $this->createMock(EntityStorageInterface::class);
    $termStorage->expects($this->once())
      ->method('load')
      ->with(200)
      ->willReturn($category);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['myeventlane_venue', $venueStorage],
        ['taxonomy_term', $termStorage],
      ]);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('warning');

    $normalizer = new EntityAutocompleteMelNormalizer($entityTypeManager, $logger);
    $form = [
      'venue_saved' => [
        '#type' => 'entity_autocomplete',
        '#target_type' => 'myeventlane_venue',
      ],
      'field_category' => [
        '#type' => 'entity_autocomplete',
        '#target_type' => 'taxonomy_term',
        '#tags' => TRUE,
      ],
    ];

    $normalized = $normalizer->normalizeValuesForForm($form, [
      'venue_saved' => 'Main Hall (100)',
      'field_category' => 'Music (200)',
    ]);

    $this->assertSame($venue, $normalized['venue_saved']);
    $this->assertSame([$category], $normalized['field_category']);
  }

  /**
   * @covers ::normalizeDefaultValuesForForm
   */
  public function testNormalizeDefaultValuesForFormDropsMalformedAutocompleteDefaultsWithOperationalContext(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->never())->method('getStorage');

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with(
        'Discarded invalid entity_autocomplete replay value for @key (expected entity type @type): @info section=@section event=@event uid=@uid field=@field payload_type=@payload_type',
        [
          '@key' => 'mel.venue_saved',
          '@type' => 'myeventlane_venue',
          '@info' => 'array',
          '@section' => 'information',
          '@event' => '123',
          '@uid' => '456',
          '@field' => 'venue_saved',
          '@payload_type' => 'array',
        ],
      );

    $normalizer = new EntityAutocompleteMelNormalizer($entityTypeManager, $logger);
    $form = [
      'venue_saved' => [
        '#type' => 'entity_autocomplete',
        '#target_type' => 'myeventlane_venue',
        '#default_value' => [
          ['target_id' => 100],
          ['target_id' => 101],
        ],
      ],
    ];

    $normalizer->normalizeDefaultValuesForForm($form, [
      'section' => 'information',
      'event_id' => 123,
      'uid' => 456,
    ]);

    $this->assertNull($form['venue_saved']['#default_value']);
  }

  private function entity(string $entityTypeId): EntityInterface {
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn($entityTypeId);
    return $entity;
  }

}
