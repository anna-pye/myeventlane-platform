<?php

declare(strict_types=1);

namespace Drupal\myeventlane_page_visuals\Service;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Psr\Log\LoggerInterface;

/**
 * Creates image media and registers file usage for page visual uploads.
 */
final class PageVisualMediaManager {

  /**
   * Upload directory for page visual hero images.
   */
  public const UPLOAD_DIRECTORY = 'public://page-visuals';

  /**
   * File usage module name.
   */
  private const FILE_USAGE_MODULE = 'myeventlane_page_visuals';

  /**
   * File usage entity type.
   */
  private const FILE_USAGE_TYPE = 'myeventlane_page_visual';

  /**
   * Constructs the manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly FileSystemInterface $fileSystem,
    private readonly FileUsageInterface $fileUsage,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Ensures the upload directory exists and is writable.
   */
  public function ensureUploadDirectoryReady(): bool {
    $directory = self::UPLOAD_DIRECTORY;
    if ($this->fileSystem->prepareDirectory(
      $directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
    )) {
      return TRUE;
    }

    $this->logger->error('Page visual upload directory is missing or not writable: @dir', [
      '@dir' => self::UPLOAD_DIRECTORY,
    ]);
    return FALSE;
  }

  /**
   * Creates or reuses image media for an uploaded file.
   *
   * @return string|null
   *   Media UUID, or NULL on failure.
   */
  public function createOrGetMediaFromFile(int $fid, string $alt_text): ?string {
    $file = $this->entityTypeManager->getStorage('file')->load($fid);
    if (!$file instanceof FileInterface) {
      $this->logger->error('Page visual save failed: uploaded file @fid was not found.', [
        '@fid' => (string) $fid,
      ]);
      return NULL;
    }

    if (!$this->fileSystem->realpath($file->getFileUri())) {
      $this->logger->error('Page visual save failed: physical file missing for @uri (fid @fid).', [
        '@uri' => $file->getFileUri(),
        '@fid' => (string) $fid,
      ]);
      return NULL;
    }

    $file->setPermanent();
    $file->save();

    $media_storage = $this->entityTypeManager->getStorage('media');
    $existing = $media_storage->loadByProperties([
      'bundle' => 'image',
      'field_media_image.target_id' => $fid,
    ]);
    $media = $existing ? reset($existing) : NULL;
    if ($media instanceof MediaInterface) {
      return $media->uuid();
    }

    try {
      $media = Media::create([
        'bundle' => 'image',
        'uid' => (int) $this->currentUser->id(),
        'name' => $file->getFilename(),
        'field_media_image' => [
          'target_id' => $fid,
          'alt' => $alt_text,
        ],
      ]);
      $media->save();
    }
    catch (EntityStorageException $e) {
      $this->logger->error('Page visual save failed while creating media for fid @fid: @message', [
        '@fid' => (string) $fid,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }

    return $media->uuid();
  }

  /**
   * Gets the file ID referenced by a media UUID.
   */
  public function getFileIdFromMediaUuid(?string $media_uuid): int {
    if ($media_uuid === NULL || $media_uuid === '') {
      return 0;
    }

    $media = $this->entityRepository->loadEntityByUuid('media', $media_uuid);
    if (!$media instanceof MediaInterface || !$media->hasField('field_media_image') || $media->get('field_media_image')->isEmpty()) {
      return 0;
    }

    $file = $media->get('field_media_image')->entity;
    return $file instanceof FileInterface ? (int) $file->id() : 0;
  }

  /**
   * Syncs file usage after a page visual save.
   *
   * Removes stale references when images are replaced or cleared, and only
   * registers usage for newly referenced files so counts do not inflate.
   */
  public function syncFileUsageForVisual(
    string $visual_id,
    ?string $old_desktop_uuid,
    ?string $old_mobile_uuid,
    int $new_desktop_fid,
    int $new_mobile_fid,
  ): void {
    if ($visual_id === '') {
      return;
    }

    $old_fids = $this->collectFileIdsInUse(
      $this->getFileIdFromMediaUuid($old_desktop_uuid),
      $this->getFileIdFromMediaUuid($old_mobile_uuid),
    );
    $new_fids = $this->collectFileIdsInUse($new_desktop_fid, $new_mobile_fid);

    foreach (array_diff($old_fids, $new_fids) as $fid) {
      $this->removeFileUsage($fid, $visual_id);
    }
    foreach (array_diff($new_fids, $old_fids) as $fid) {
      $this->registerFileUsage($fid, $visual_id);
    }
  }

  /**
   * Removes all file usage registered for a page visual.
   */
  public function releaseFileUsageForVisual(
    string $visual_id,
    ?string $desktop_uuid,
    ?string $mobile_uuid,
  ): void {
    if ($visual_id === '') {
      return;
    }

    foreach ($this->collectFileIdsInUse(
      $this->getFileIdFromMediaUuid($desktop_uuid),
      $this->getFileIdFromMediaUuid($mobile_uuid),
    ) as $fid) {
      $this->removeFileUsage($fid, $visual_id);
    }
  }

  /**
   * Registers file usage so uploaded files are not garbage-collected.
   */
  public function registerFileUsage(int $fid, string $visual_id): void {
    if ($fid <= 0 || $visual_id === '') {
      return;
    }

    $file = $this->entityTypeManager->getStorage('file')->load($fid);
    if (!$file instanceof FileInterface) {
      return;
    }

    $this->fileUsage->add(
      $file,
      self::FILE_USAGE_MODULE,
      self::FILE_USAGE_TYPE,
      $visual_id
    );
  }

  /**
   * Removes file usage for a page visual reference.
   */
  public function removeFileUsage(int $fid, string $visual_id): void {
    if ($fid <= 0 || $visual_id === '') {
      return;
    }

    $file = $this->entityTypeManager->getStorage('file')->load($fid);
    if (!$file instanceof FileInterface) {
      return;
    }

    $this->fileUsage->delete(
      $file,
      self::FILE_USAGE_MODULE,
      self::FILE_USAGE_TYPE,
      $visual_id
    );
  }

  /**
   * Builds unique file IDs in use for desktop/mobile slots.
   *
   * @return list<int>
   */
  private function collectFileIdsInUse(int $desktop_fid, int $mobile_fid): array {
    $fids = [];
    if ($desktop_fid > 0) {
      $fids[$desktop_fid] = $desktop_fid;
    }
    if ($mobile_fid > 0) {
      $fids[$mobile_fid] = $mobile_fid;
    }
    return array_values($fids);
  }

}
