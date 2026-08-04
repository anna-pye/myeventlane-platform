<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\media\MediaTypeInterface;
use Drupal\node\NodeInterface;

/**
 * Creates or reuses organiser-owned Image Media for legacy event covers.
 */
final class EventCoverMediaManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Captures the event's raw cover file in the organiser's Media Library.
   *
   * The legacy field_event_image value remains unchanged and continues to be
   * the public rendering source. Reuse is deliberately scoped by organiser and
   * file so a shared legacy file never grants cross-organiser Media access.
   *
   * @return array{media: \Drupal\media\MediaInterface, created: bool}
   *   The captured Media item and whether it was newly created.
   */
  public function capture(NodeInterface $event): array {
    if ($event->bundle() !== 'event'
      || !$event->hasField('field_event_image')
      || $event->get('field_event_image')->isEmpty()) {
      throw new \InvalidArgumentException('An event cover image is required.');
    }

    $cover_item = $event->get('field_event_image')->first();
    $cover_value = $cover_item?->getValue() ?? [];
    $fid = (int) ($cover_value['target_id'] ?? 0);
    $file = $fid > 0
      ? $this->entityTypeManager->getStorage('file')->load($fid)
      : NULL;
    if (!$file instanceof FileInterface) {
      throw new \RuntimeException(sprintf('Event cover file %d could not be loaded.', $fid));
    }

    $media_type = $this->entityTypeManager->getStorage('media_type')->load('image');
    if (!$media_type instanceof MediaTypeInterface) {
      throw new \RuntimeException('The Image media type is not available.');
    }
    $source_field = (string) ($media_type->getSource()->getConfiguration()['source_field'] ?? '');
    if ($source_field === '') {
      throw new \RuntimeException('The Image media source field is not configured.');
    }

    $owner_id = max(0, (int) $event->getOwnerId());
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

    $alt = trim((string) ($cover_value['alt'] ?? ''));
    if ($alt === '') {
      $alt = trim((string) $event->label());
    }
    if ($alt === '') {
      $alt = $file->getFilename();
    }

    $media = $media_storage->create([
      'bundle' => 'image',
      'uid' => $owner_id,
      'name' => $file->getFilename(),
      $source_field => [
        'target_id' => $fid,
        'alt' => $alt,
        'title' => (string) ($cover_value['title'] ?? ''),
      ],
    ]);
    if (!$media instanceof MediaInterface) {
      throw new \RuntimeException('The Image media item could not be created.');
    }
    $media->save();

    return ['media' => $media, 'created' => TRUE];
  }

  /**
   * Returns the source file ID for an Image Media item.
   */
  public function sourceFileId(MediaInterface $media): int {
    $source_field = (string) ($media->getSource()->getConfiguration()['source_field'] ?? '');
    if ($source_field === '' || !$media->hasField($source_field) || $media->get($source_field)->isEmpty()) {
      return 0;
    }
    return (int) ($media->get($source_field)->target_id ?? 0);
  }

}
