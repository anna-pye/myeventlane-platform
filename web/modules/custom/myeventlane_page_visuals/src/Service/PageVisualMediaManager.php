<?php

declare(strict_types=1);

namespace Drupal\myeventlane_page_visuals\Service;

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
   * Constructs the manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
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
      'myeventlane_page_visuals',
      'myeventlane_page_visual',
      $visual_id
    );
  }

}
