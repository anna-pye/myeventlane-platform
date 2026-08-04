<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\media\MediaSourceInterface;
use Drupal\media\MediaTypeInterface;
use Drupal\myeventlane_event_studio\Service\EventCoverMediaManager;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

require_once dirname(__DIR__, 3) . '/src/Service/EventCoverMediaManager.php';

/**
 * Tests organiser-scoped event cover Media capture.
 *
 * @group myeventlane_event_studio
 */
final class EventCoverMediaManagerTest extends UnitTestCase {

  /**
   * Direct uploads create Media owned by the event organiser.
   */
  public function testCaptureCreatesMediaOwnedByEventOrganiser(): void {
    $file = $this->createMock(FileInterface::class);
    $file->method('getFilename')->willReturn('community-day.jpg');
    $file->method('isTemporary')->willReturn(FALSE);

    $media = $this->createMock(MediaInterface::class);
    $media->method('id')->willReturn('81');
    $media->expects($this->once())->method('save');

    $media_storage = $this->createMock(EntityStorageInterface::class);
    $media_storage->expects($this->once())
      ->method('loadByProperties')
      ->with([
        'bundle' => 'image',
        'uid' => 23,
        'field_media_image.target_id' => 55,
      ])
      ->willReturn([]);
    $media_storage->expects($this->once())
      ->method('create')
      ->with([
        'bundle' => 'image',
        'uid' => 23,
        'name' => 'community-day.jpg',
        'field_media_image' => [
          'target_id' => 55,
          'alt' => 'People gathering at Community Day',
          'title' => '',
        ],
      ])
      ->willReturn($media);

    $manager = new EventCoverMediaManager($this->entityTypeManager($file, $media_storage));
    $result = $manager->capture($this->event(23, 55, 'People gathering at Community Day'));

    self::assertTrue($result['created']);
    self::assertSame($media, $result['media']);
  }

  /**
   * Existing Media is reused only for the matching organiser and source file.
   */
  public function testCaptureReusesOnlySameOrganiserAndFile(): void {
    $file = $this->createMock(FileInterface::class);
    $existing = $this->createMock(MediaInterface::class);

    $media_storage = $this->createMock(EntityStorageInterface::class);
    $media_storage->expects($this->once())
      ->method('loadByProperties')
      ->with([
        'bundle' => 'image',
        'uid' => 48,
        'field_media_image.target_id' => 55,
      ])
      ->willReturn([$existing]);
    $media_storage->expects($this->never())->method('create');

    $manager = new EventCoverMediaManager($this->entityTypeManager($file, $media_storage));
    $result = $manager->capture($this->event(48, 55, 'Cover alt'));

    self::assertFalse($result['created']);
    self::assertSame($existing, $result['media']);
  }

  /**
   * Builds storage mocks for event cover capture.
   */
  private function entityTypeManager(FileInterface $file, EntityStorageInterface $media_storage): EntityTypeManagerInterface {
    $file_storage = $this->createMock(EntityStorageInterface::class);
    $file_storage->method('load')->with(55)->willReturn($file);

    $source = $this->createMock(MediaSourceInterface::class);
    $source->method('getConfiguration')->willReturn(['source_field' => 'field_media_image']);
    $media_type = $this->createMock(MediaTypeInterface::class);
    $media_type->method('getSource')->willReturn($source);
    $media_type_storage = $this->createMock(EntityStorageInterface::class);
    $media_type_storage->method('load')->with('image')->willReturn($media_type);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturnMap([
      ['file', $file_storage],
      ['media', $media_storage],
      ['media_type', $media_type_storage],
    ]);
    return $entity_type_manager;
  }

  /**
   * Builds an event mock with a legacy cover field value.
   */
  private function event(int $owner_id, int $fid, string $alt): NodeInterface {
    $item = $this->createMock(FieldItemInterface::class);
    $item->method('getValue')->willReturn([
      'target_id' => $fid,
      'alt' => $alt,
      'title' => '',
    ]);
    $cover = $this->createMock(FieldItemListInterface::class);
    $cover->method('isEmpty')->willReturn(FALSE);
    $cover->method('first')->willReturn($item);

    $event = $this->createMock(NodeInterface::class);
    $event->method('bundle')->willReturn('event');
    $event->method('hasField')->with('field_event_image')->willReturn(TRUE);
    $event->method('get')->with('field_event_image')->willReturn($cover);
    $event->method('getOwnerId')->willReturn($owner_id);
    $event->method('label')->willReturn('Community Day');
    return $event;
  }

}
