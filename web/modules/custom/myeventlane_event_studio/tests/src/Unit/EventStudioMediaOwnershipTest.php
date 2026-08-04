<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\focal_point\FocalPointManagerInterface;
use Drupal\media\MediaInterface;
use Drupal\myeventlane_event_studio\Service\EventHighlightHelper;
use Drupal\myeventlane_event_studio\Service\EventStudioSaveService;
use Drupal\myeventlane_event_studio\Service\PublishEligibilityEvaluator;
use Drupal\myeventlane_event_studio\Service\QuestionFieldTypeRegistry;
use Drupal\myeventlane_vendor\Service\OrganiserMediaAccess;
use Drupal\myeventlane_venue\Service\VenueManager;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests Event Studio Media Library ownership enforcement.
 *
 * @group myeventlane_event_studio
 */
final class EventStudioMediaOwnershipTest extends UnitTestCase {

  /**
   * Verifies that organisers cannot save a foreign Media Library cover.
   */
  public function testForeignCoverSelectionIsRejected(): void {
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('id')->willReturn(23);
    $account->method('hasPermission')
      ->with(OrganiserMediaAccess::ACCESS_ALL_PERMISSION)
      ->willReturn(FALSE);

    $media = $this->createMock(MediaInterface::class);
    $media->method('getOwnerId')->willReturn(99);

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturnMap([
      ['field_event_image', TRUE],
      ['field_mel_event_cover_media', TRUE],
    ]);

    $service = new EventStudioSaveService(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(VenueManager::class),
      $this->createMock(LoggerInterface::class),
      (new \ReflectionClass(EventHighlightHelper::class))->newInstanceWithoutConstructor(),
      (new \ReflectionClass(PublishEligibilityEvaluator::class))->newInstanceWithoutConstructor(),
      new QuestionFieldTypeRegistry(),
      $this->createMock(ImageFactory::class),
      $this->createMock(TranslationInterface::class),
      $this->createMock(FileSystemInterface::class),
      $this->createMock(FocalPointManagerInterface::class),
      fileRepository: $this->createMock(FileRepositoryInterface::class),
      organiserMediaAccess: new OrganiserMediaAccess($account),
    );

    $result = $service->applyBrandingCoverMedia($node, $media);

    self::assertNull($result['node']);
    self::assertSame(
      ['Choose an image uploaded by your organiser account.'],
      $result['errors'],
    );
  }

}
