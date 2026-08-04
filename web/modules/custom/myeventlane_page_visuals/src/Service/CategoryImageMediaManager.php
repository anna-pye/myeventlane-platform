<?php

declare(strict_types=1);

namespace Drupal\myeventlane_page_visuals\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\media\MediaTypeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Creates platform-owned Image Media for legacy category images.
 */
final class CategoryImageMediaManager {

  /**
   * System ownership keeps platform category assets out of organiser results.
   */
  private const PLATFORM_MEDIA_OWNER_ID = 0;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * Captures a category's legacy direct-file image as reusable Media.
   *
   * Category images are platform assets, so backfilled Media is deliberately
   * system-owned rather than attributed to an organiser. This keeps category
   * artwork out of organiser-owned Media Library results while staff retain
   * their existing cross-owner access.
   *
   * @return array{media: \Drupal\media\MediaInterface, created: bool}
   *   The captured Media item and whether it was newly created.
   */
  public function capture(TermInterface $term): array {
    if ($term->bundle() !== 'categories'
      || !$term->hasField('field_category_image')
      || $term->get('field_category_image')->isEmpty()) {
      throw new \InvalidArgumentException('A legacy category image is required.');
    }

    $image_item = $term->get('field_category_image')->first();
    $image_value = $image_item?->getValue() ?? [];
    $fid = (int) ($image_value['target_id'] ?? 0);
    $file = $fid > 0
      ? $this->entityTypeManager->getStorage('file')->load($fid)
      : NULL;
    if (!$file instanceof FileInterface) {
      throw new \RuntimeException(sprintf('Category image file %d could not be loaded.', $fid));
    }

    $realpath = $this->fileSystem->realpath($file->getFileUri());
    if ($realpath === FALSE || !is_file($realpath)) {
      throw new \RuntimeException(sprintf('Category image file %d is missing from storage.', $fid));
    }

    $media_type = $this->entityTypeManager->getStorage('media_type')->load('image');
    if (!$media_type instanceof MediaTypeInterface) {
      throw new \RuntimeException('The Image media type is not available.');
    }
    $source_field = (string) ($media_type->getSource()->getConfiguration()['source_field'] ?? '');
    if ($source_field === '') {
      throw new \RuntimeException('The Image media source field is not configured.');
    }

    $owner_id = self::PLATFORM_MEDIA_OWNER_ID;
    $media_storage = $this->entityTypeManager->getStorage('media');
    $existing = $media_storage->loadByProperties([
      'bundle' => 'image',
      'uid' => $owner_id,
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

    $alt = trim((string) ($image_value['alt'] ?? ''));
    if ($alt === '') {
      $alt = trim((string) $term->label());
    }
    if ($alt === '') {
      $alt = $file->getFilename();
    }

    $media = $media_storage->create([
      'bundle' => 'image',
      'uid' => $owner_id,
      'name' => sprintf('%s category image', $term->label()),
      $source_field => [
        'target_id' => $fid,
        'alt' => $alt,
        'title' => (string) ($image_value['title'] ?? ''),
      ],
    ]);
    if (!$media instanceof MediaInterface) {
      throw new \RuntimeException('The category Image Media item could not be created.');
    }
    $media->save();

    return ['media' => $media, 'created' => TRUE];
  }

}
