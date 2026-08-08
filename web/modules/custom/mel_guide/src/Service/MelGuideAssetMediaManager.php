<?php

declare(strict_types=1);

namespace Drupal\mel_guide\Service;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\media\MediaTypeInterface;

/**
 * Captures MEL Guide character files as platform-owned Image Media.
 */
final class MelGuideAssetMediaManager {

  /**
   * Environment-local Media selection, deliberately outside config import.
   */
  public const STATE_KEY = 'mel_guide.asset_media_uuids';

  /**
   * System ownership keeps platform artwork out of organiser Media results.
   */
  private const PLATFORM_MEDIA_OWNER_ID = 0;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * Creates or reuses platform-owned Image Media for a character asset.
   *
   * @return array{media: \Drupal\media\MediaInterface, created: bool}
   *   The captured Media item and whether it was newly created.
   *
   * @throws \InvalidArgumentException
   *   When the source file is unavailable or unsupported by Image Media.
   * @throws \RuntimeException
   *   When Image Media is unavailable or cannot be saved.
   */
  public function capture(int $fid, string $alt): array {
    $file = $this->entityTypeManager->getStorage('file')->load($fid);
    if (!$file instanceof FileInterface) {
      throw new \InvalidArgumentException(sprintf('MEL Guide file %d could not be loaded.', $fid));
    }

    $realpath = $this->fileSystem->realpath($file->getFileUri());
    if ($realpath === FALSE || !is_file($realpath)) {
      throw new \InvalidArgumentException(sprintf('MEL Guide file %d is missing from storage.', $fid));
    }

    $media_type = $this->entityTypeManager->getStorage('media_type')->load('image');
    if (!$media_type instanceof MediaTypeInterface) {
      throw new \RuntimeException('The Image media type is not available.');
    }
    $source_field = (string) ($media_type->getSource()->getConfiguration()['source_field'] ?? '');
    if ($source_field === '') {
      throw new \RuntimeException('The Image media source field is not configured.');
    }

    $media_storage = $this->entityTypeManager->getStorage('media');
    $existing = $media_storage->loadByProperties([
      'bundle' => 'image',
      'uid' => self::PLATFORM_MEDIA_OWNER_ID,
      $source_field . '.target_id' => $fid,
    ]);
    $media = $existing ? reset($existing) : NULL;
    if ($media instanceof MediaInterface) {
      return ['media' => $media, 'created' => FALSE];
    }

    if ($file->isTemporary()) {
      $file->setPermanent();
      $file->save();
    }

    $alt = trim($alt);
    if ($alt === '') {
      $alt = $file->getFilename();
    }
    $media = $media_storage->create([
      'bundle' => 'image',
      'uid' => self::PLATFORM_MEDIA_OWNER_ID,
      'status' => 1,
      'name' => sprintf('MEL Guide: %s', $alt),
      $source_field => [
        'target_id' => $fid,
        'alt' => $alt,
      ],
    ]);
    if (!$media instanceof MediaInterface) {
      throw new \RuntimeException('The MEL Guide Image Media item could not be created.');
    }

    $violations = $media->validate();
    if ($violations->count() > 0) {
      throw new \InvalidArgumentException(sprintf('MEL Guide file %d is not supported by Image Media: %s', $fid, (string) $violations));
    }

    try {
      $media->save();
    }
    catch (\Throwable $exception) {
      throw new \RuntimeException(sprintf('The MEL Guide Image Media item could not be saved: %s', $exception->getMessage()), 0, $exception);
    }
    return ['media' => $media, 'created' => TRUE];
  }

  /**
   * Resolves a Media UUID to its source file ID.
   */
  public function getFileId(?string $media_uuid): int {
    if ($media_uuid === NULL || $media_uuid === '') {
      return 0;
    }
    $media = $this->entityRepository->loadEntityByUuid('media', $media_uuid);
    if (!$media instanceof MediaInterface || $media->bundle() !== 'image') {
      return 0;
    }
    $source = $media->getSource();
    $source_field = (string) ($source->getConfiguration()['source_field'] ?? '');
    if ($source_field === '' || !$media->hasField($source_field) || $media->get($source_field)->isEmpty()) {
      return 0;
    }
    $file = $media->get($source_field)->entity;
    if (!$file instanceof FileInterface) {
      return 0;
    }
    $realpath = $this->fileSystem->realpath($file->getFileUri());
    return $realpath !== FALSE && is_file($realpath) ? (int) $file->id() : 0;
  }

}
