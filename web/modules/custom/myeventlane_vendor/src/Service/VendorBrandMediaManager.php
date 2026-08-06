<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\media\MediaTypeInterface;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\myeventlane_vendor\Exception\UnsupportedBrandMediaFileException;
use Psr\Log\LoggerInterface;

/**
 * Captures and resolves organiser brand images as reusable Image Media.
 */
final class VendorBrandMediaManager {

  public const ASSET_PUBLIC_LOGO = 'public_logo';

  public const ASSET_BANNER = 'banner';

  public const ASSET_EMAIL_LOGO = 'email_logo';

  /**
   * Canonical Media fields and their legacy direct-file sources.
   */
  private const ASSET_FIELDS = [
    self::ASSET_PUBLIC_LOGO => [
      'media' => VendorImageFieldPolicy::PUBLIC_LOGO_MEDIA_FIELD,
      'legacy' => VendorImageFieldPolicy::PUBLIC_LOGO_FIELDS,
      'label' => 'organiser logo',
    ],
    self::ASSET_BANNER => [
      'media' => VendorImageFieldPolicy::BANNER_MEDIA_FIELD,
      'legacy' => ['field_banner_image'],
      'label' => 'organiser banner',
    ],
    self::ASSET_EMAIL_LOGO => [
      'media' => VendorImageFieldPolicy::EMAIL_LOGO_MEDIA_FIELD,
      'legacy' => ['field_msg_logo'],
      'label' => 'email logo',
    ],
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Captures one legacy organiser asset as owner-scoped Image Media.
   *
   * @return array{status: 'captured', media: \Drupal\media\MediaInterface, created: bool, source_field: string, conflict: bool}
   *   Capture result and provenance details.
   */
  public function capture(Vendor $vendor, string $assetType): array {
    $definition = $this->assetDefinition($assetType);
    $source = $this->legacySource($vendor, $assetType);
    if ($source === NULL) {
      throw new \InvalidArgumentException(sprintf('A legacy %s image is required.', $definition['label']));
    }

    $imageValue = $source['items']->first()?->getValue() ?? [];
    $fid = (int) ($imageValue['target_id'] ?? 0);
    $file = $fid > 0
      ? $this->entityTypeManager->getStorage('file')->load($fid)
      : NULL;
    if (!$file instanceof FileInterface) {
      throw new \RuntimeException(sprintf('%s file %d could not be loaded.', ucfirst($definition['label']), $fid));
    }

    $realpath = $this->fileSystem->realpath($file->getFileUri());
    if ($realpath === FALSE || !is_file($realpath)) {
      throw new \RuntimeException(sprintf('%s file %d is missing from storage.', ucfirst($definition['label']), $fid));
    }

    [$mediaType, $sourceField] = $this->imageMediaSource();
    $this->assertAllowedExtension($file, $sourceField);

    $ownerId = max(0, (int) $vendor->getOwnerId());
    $mediaStorage = $this->entityTypeManager->getStorage('media');
    $existing = $mediaStorage->loadByProperties([
      'bundle' => $mediaType->id(),
      'uid' => $ownerId,
      $sourceField . '.target_id' => $fid,
    ]);
    $media = $existing ? reset($existing) : NULL;
    if ($media instanceof MediaInterface) {
      return [
        'status' => 'captured',
        'media' => $media,
        'created' => FALSE,
        'source_field' => $source['field'],
        'conflict' => $source['conflict'],
      ];
    }

    if ($file->isTemporary()) {
      $file->setPermanent();
      $file->save();
    }

    $vendorLabel = trim((string) $vendor->label());
    $alt = trim((string) ($imageValue['alt'] ?? ''));
    if ($alt === '') {
      $alt = trim($vendorLabel . ' ' . $definition['label']);
    }
    if ($alt === '') {
      $alt = $file->getFilename();
    }

    $media = $mediaStorage->create([
      'bundle' => $mediaType->id(),
      'uid' => $ownerId,
      'name' => trim($vendorLabel . ' ' . $definition['label']),
      $sourceField => [
        'target_id' => $fid,
        'alt' => $alt,
        'title' => (string) ($imageValue['title'] ?? ''),
      ],
    ]);
    if (!$media instanceof MediaInterface) {
      throw new \RuntimeException(sprintf('The %s Image Media item could not be created.', $definition['label']));
    }
    $media->save();

    return [
      'status' => 'captured',
      'media' => $media,
      'created' => TRUE,
      'source_field' => $source['field'],
      'conflict' => $source['conflict'],
    ];
  }

  /**
   * Synchronises selected Media fields from the retained legacy forms.
   *
   * @param \Drupal\myeventlane_vendor\Entity\Vendor $vendor
   *   The organiser whose direct fields were edited.
   * @param string[] $assetTypes
   *   Asset constants to synchronise.
   * @param bool $clearWhenEmpty
   *   Clear the Media reference when the corresponding form cleared its file.
   *
   * @return array<string, array{status: 'captured', media: \Drupal\media\MediaInterface, created: bool, source_field: string, conflict: bool}|array{status: 'unsupported', source_field: string, reason: string}|null>
   *   Results keyed by asset type. Unsupported files retain their legacy
   *   fallback; NULL means the reference was cleared/empty.
   */
  public function synchroniseFromLegacy(Vendor $vendor, array $assetTypes, bool $clearWhenEmpty = TRUE): array {
    $results = [];
    foreach ($assetTypes as $assetType) {
      $definition = $this->assetDefinition($assetType);
      $mediaField = $definition['media'];
      if (!$vendor->hasField($mediaField)) {
        continue;
      }

      $legacySource = $this->legacySource($vendor, $assetType);
      if ($legacySource === NULL) {
        if ($clearWhenEmpty) {
          $vendor->set($mediaField, []);
        }
        $results[$assetType] = NULL;
        continue;
      }

      try {
        $capture = $this->capture($vendor, $assetType);
      }
      catch (UnsupportedBrandMediaFileException $e) {
        $this->logger->warning(
          'Organiser @vendor_id legacy @asset in @field was retained without Media capture: @message',
          [
            '@vendor_id' => (string) $vendor->id(),
            '@asset' => $definition['label'],
            '@field' => $legacySource['field'],
            '@message' => $e->getMessage(),
          ],
        );
        $results[$assetType] = [
          'status' => 'unsupported',
          'source_field' => $legacySource['field'],
          'reason' => $e->getMessage(),
        ];
        continue;
      }
      $vendor->set($mediaField, [['target_id' => (int) $capture['media']->id()]]);
      $results[$assetType] = $capture;
    }

    return $results;
  }

  /**
   * Resolves the Media-first image field items for rendering.
   */
  public function imageItemsForAsset(ContentEntityInterface $vendor, string $assetType): ?FieldItemListInterface {
    $definition = $this->assetDefinition($assetType);
    $mediaField = $definition['media'];
    if ($vendor->hasField($mediaField) && !$vendor->get($mediaField)->isEmpty()) {
      $media = $vendor->get($mediaField)->entity;
      if ($media instanceof MediaInterface) {
        [, $sourceField] = $this->imageMediaSource();
        if ($media->hasField($sourceField) && !$media->get($sourceField)->isEmpty()) {
          return $media->get($sourceField);
        }
      }
    }

    $source = $this->legacySource($vendor, $assetType);
    return $source['items'] ?? NULL;
  }

  /**
   * Resolves the Media-first file entity for URLs and non-render consumers.
   */
  public function fileForAsset(ContentEntityInterface $vendor, string $assetType): ?FileInterface {
    $items = $this->imageItemsForAsset($vendor, $assetType);
    $file = $items?->entity;
    return $file instanceof FileInterface ? $file : NULL;
  }

  /**
   * Returns a validated asset definition.
   *
   * @return array{media: string, legacy: string[], label: string}
   *   Canonical field names and a human-readable asset label.
   */
  private function assetDefinition(string $assetType): array {
    if (!isset(self::ASSET_FIELDS[$assetType])) {
      throw new \InvalidArgumentException(sprintf('Unknown organiser brand asset type "%s".', $assetType));
    }
    return self::ASSET_FIELDS[$assetType];
  }

  /**
   * Finds the preferred direct-file source and detects duplicate-logo drift.
   *
   * @return array{field: string, items: \Drupal\Core\Field\FieldItemListInterface, conflict: bool}|null
   *   The preferred source and conflict state, or NULL when no source exists.
   */
  private function legacySource(ContentEntityInterface $vendor, string $assetType): ?array {
    $definition = $this->assetDefinition($assetType);
    $populated = [];
    foreach ($definition['legacy'] as $fieldName) {
      if ($vendor->hasField($fieldName) && !$vendor->get($fieldName)->isEmpty()) {
        $populated[$fieldName] = $vendor->get($fieldName);
      }
    }
    if ($populated === []) {
      return NULL;
    }

    $firstField = array_key_first($populated);
    $firstItems = $populated[$firstField];
    $fileIds = [];
    foreach ($populated as $items) {
      $fileIds[] = (int) ($items->target_id ?? 0);
    }

    return [
      'field' => $firstField,
      'items' => $firstItems,
      'conflict' => count(array_unique(array_filter($fileIds))) > 1,
    ];
  }

  /**
   * Loads the Image media type and its source field.
   *
   * @return array{\Drupal\media\MediaTypeInterface, string}
   *   The Image media type and its configured source field name.
   */
  private function imageMediaSource(): array {
    $mediaType = $this->entityTypeManager->getStorage('media_type')->load('image');
    if (!$mediaType instanceof MediaTypeInterface) {
      throw new \RuntimeException('The Image media type is not available.');
    }
    $sourceField = (string) ($mediaType->getSource()->getConfiguration()['source_field'] ?? '');
    if ($sourceField === '') {
      throw new \RuntimeException('The Image media source field is not configured.');
    }
    return [$mediaType, $sourceField];
  }

  /**
   * Prevents Media creation with formats rejected by the Image media bundle.
   */
  private function assertAllowedExtension(FileInterface $file, string $sourceField): void {
    $field = $this->entityTypeManager->getStorage('field_config')->load('media.image.' . $sourceField);
    $extensions = $field?->getSetting('file_extensions');
    if (!is_string($extensions) || trim($extensions) === '') {
      return;
    }

    $extension = strtolower((string) pathinfo($file->getFilename(), PATHINFO_EXTENSION));
    $allowed = preg_split('/\s+/', strtolower(trim($extensions))) ?: [];
    if ($extension === '' || !in_array($extension, $allowed, TRUE)) {
      throw new UnsupportedBrandMediaFileException(sprintf(
        'File %d uses .%s, which the Image media type does not allow (%s).',
        (int) $file->id(),
        $extension !== '' ? $extension : 'unknown',
        implode(', ', $allowed),
      ));
    }
  }

}
