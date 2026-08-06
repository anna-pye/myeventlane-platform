<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\field\FieldConfigInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaSourceInterface;
use Drupal\media\MediaTypeInterface;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\myeventlane_vendor\Service\VendorBrandMediaManager;
use Drupal\myeventlane_vendor\Service\VendorImageFieldPolicy;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests compatibility handling for retained organiser brand images.
 */
#[Group('myeventlane_vendor')]
final class VendorBrandMediaCompatibilityTest extends UnitTestCase {

  /**
   * An unsupported legacy SVG is retained without clearing Media or throwing.
   */
  public function testUnsupportedLegacySvgIsReportedAndRetained(): void {
    $temporaryPath = tempnam(sys_get_temp_dir(), 'mel-brand-media-');
    self::assertIsString($temporaryPath);

    try {
      $item = $this->createMock(FieldItemInterface::class);
      $item->method('getValue')->willReturn(['target_id' => 41, 'alt' => 'Legacy logo']);

      $legacyItems = $this->createMock(FieldItemListInterface::class);
      $legacyItems->method('isEmpty')->willReturn(FALSE);
      $legacyItems->method('first')->willReturn($item);
      $legacyItems->method('__get')->with('target_id')->willReturn(41);

      $vendor = $this->createMock(Vendor::class);
      $vendor->method('id')->willReturn(7);
      $vendor->method('hasField')->willReturnCallback(static fn(string $field): bool => in_array($field, [
        VendorImageFieldPolicy::PUBLIC_LOGO_MEDIA_FIELD,
        'field_vendor_logo',
      ], TRUE));
      $vendor->method('get')->with('field_vendor_logo')->willReturn($legacyItems);
      $vendor->expects(self::never())->method('set');

      $file = $this->createMock(FileInterface::class);
      $file->method('id')->willReturn(41);
      $file->method('getFilename')->willReturn('legacy-logo.svg');
      $file->method('getFileUri')->willReturn('public://vendor_logos/legacy-logo.svg');

      $fileStorage = $this->createMock(EntityStorageInterface::class);
      $fileStorage->method('load')->with(41)->willReturn($file);

      $mediaSource = $this->createMock(MediaSourceInterface::class);
      $mediaSource->method('getConfiguration')->willReturn(['source_field' => 'field_media_image']);

      $mediaType = $this->createMock(MediaTypeInterface::class);
      $mediaType->method('getSource')->willReturn($mediaSource);

      $mediaTypeStorage = $this->createMock(EntityStorageInterface::class);
      $mediaTypeStorage->method('load')->with('image')->willReturn($mediaType);

      $fieldConfig = $this->createMock(FieldConfigInterface::class);
      $fieldConfig->method('getSetting')->with('file_extensions')->willReturn('png gif jpg jpeg webp');

      $fieldConfigStorage = $this->createMock(EntityStorageInterface::class);
      $fieldConfigStorage->method('load')->with('media.image.field_media_image')->willReturn($fieldConfig);

      $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
      $entityTypeManager->method('getStorage')->willReturnCallback(static fn(string $entityTypeId): EntityStorageInterface => match ($entityTypeId) {
        'file' => $fileStorage,
        'media_type' => $mediaTypeStorage,
        'field_config' => $fieldConfigStorage,
        default => throw new \LogicException('Unexpected storage: ' . $entityTypeId),
      });

      $fileSystem = $this->createMock(FileSystemInterface::class);
      $fileSystem->method('realpath')->with('public://vendor_logos/legacy-logo.svg')->willReturn($temporaryPath);

      $logger = $this->createMock(LoggerInterface::class);
      $logger->expects(self::once())
        ->method('warning')
        ->with(
          self::stringContains('retained without Media capture'),
          self::callback(static fn(array $context): bool => $context['@vendor_id'] === '7'
            && $context['@field'] === 'field_vendor_logo'
            && str_contains($context['@message'], '.svg')),
        );

      $manager = new VendorBrandMediaManager($entityTypeManager, $fileSystem, $logger);
      $result = $manager->synchroniseFromLegacy($vendor, [VendorBrandMediaManager::ASSET_PUBLIC_LOGO]);

      self::assertSame('unsupported', $result[VendorBrandMediaManager::ASSET_PUBLIC_LOGO]['status']);
      self::assertSame('field_vendor_logo', $result[VendorBrandMediaManager::ASSET_PUBLIC_LOGO]['source_field']);
      self::assertStringContainsString('.svg', $result[VendorBrandMediaManager::ASSET_PUBLIC_LOGO]['reason']);
    }
    finally {
      @unlink($temporaryPath);
    }
  }

}
