<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\media\MediaInterface;
use Drupal\media\MediaTypeInterface;
use Drupal\myeventlane_venue\Entity\Venue;

/**
 * Saves an explicitly approved website image as organiser-owned Media.
 */
final class VenueWebsiteImageImporter {

  public function __construct(
    private readonly SafeRemoteContentFetcher $contentFetcher,
    private readonly FileRepositoryInterface $fileRepository,
    private readonly FileSystemInterface $fileSystem,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Imports an approved metadata image and attaches it to the venue.
   */
  public function import(Venue $venue, string $source_url, string $image_url): MediaInterface {
    $image = $this->contentFetcher->fetchImage($image_url);
    $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($image['body']);
    $extensions = [
      'image/jpeg' => 'jpg',
      'image/png' => 'png',
      'image/webp' => 'webp',
    ];
    if (!is_string($mime) || !isset($extensions[$mime]) || $mime !== $image['content_type']) {
      throw new \RuntimeException('The approved image is not a supported JPEG, PNG or WebP file.');
    }

    $dimensions = @getimagesizefromstring($image['body']);
    $width = (int) ($dimensions[0] ?? 0);
    $height = (int) ($dimensions[1] ?? 0);
    if ($width < 400 || $height < 200 || ($width * $height) > 24000000) {
      throw new \RuntimeException('The approved image dimensions are not suitable for a venue listing.');
    }

    $directory = 'public://venues/imported/' . date('Y-m', $this->time->getRequestTime());
    if (!$this->fileSystem->prepareDirectory(
      $directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    )) {
      throw new \RuntimeException('The venue image directory could not be prepared.');
    }

    $filename = sprintf(
      'venue-%d-%d.%s',
      (int) $venue->id(),
      $this->time->getRequestTime(),
      $extensions[$mime],
    );
    $file = $this->fileRepository->writeData(
      $image['body'],
      $directory . '/' . $filename,
      FileExists::Rename,
    );
    if (!$file instanceof FileInterface) {
      throw new \RuntimeException('The venue image file could not be saved.');
    }
    $file->setPermanent();
    $file->save();

    $media = NULL;
    try {
      $media_type = $this->entityTypeManager->getStorage('media_type')->load('image');
      if (!$media_type instanceof MediaTypeInterface) {
        throw new \RuntimeException('The Image media type is not available.');
      }
      $source_field = (string) ($media_type->getSource()->getConfiguration()['source_field'] ?? '');
      if ($source_field === '') {
        throw new \RuntimeException('The Image media source field is not configured.');
      }

      $media = $this->entityTypeManager->getStorage('media')->create([
        'bundle' => 'image',
        'uid' => max(0, (int) $venue->getOwnerId()),
        'name' => sprintf('%s website image', trim((string) $venue->label())),
        $source_field => [
          'target_id' => (int) $file->id(),
          'alt' => trim((string) $venue->label()),
        ],
      ]);
      if (!$media instanceof MediaInterface) {
        throw new \RuntimeException('The venue image Media item could not be created.');
      }
      $media->save();

      $accepted = $this->acceptedMetadata($venue);
      $accepted['image'] = [
        'source' => $image['url'],
        'media_id' => (int) $media->id(),
        'accepted' => $this->time->getRequestTime(),
      ];
      $venue->set('image_media', ['target_id' => (int) $media->id()]);
      $venue->set('website_metadata_source_url', $source_url);
      $venue->set('website_metadata_image_source_url', $image['url']);
      $venue->set('website_metadata_checked', $this->time->getRequestTime());
      $venue->set('website_metadata_accepted_fields', json_encode($accepted, JSON_THROW_ON_ERROR));
      $venue->save();
    }
    catch (\Throwable $error) {
      if ($media instanceof MediaInterface && !$media->isNew()) {
        $media->delete();
      }
      if (!$file->isNew()) {
        $file->delete();
      }
      throw $error;
    }

    return $media;
  }

  /**
   * Returns existing accepted website metadata for the venue.
   *
   * @return array<string, mixed>
   *   Existing accepted metadata keyed by field name.
   */
  private function acceptedMetadata(Venue $venue): array {
    if (!$venue->hasField('website_metadata_accepted_fields')) {
      return [];
    }
    $accepted = json_decode((string) $venue->get('website_metadata_accepted_fields')->value, TRUE);
    return is_array($accepted) ? $accepted : [];
  }

}
