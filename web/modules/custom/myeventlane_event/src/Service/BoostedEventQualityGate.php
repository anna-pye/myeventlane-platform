<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\crop\Entity\Crop;
use Drupal\crop\CropInterface;
use Drupal\file\FileInterface;
use Drupal\focal_point\FocalPointManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Validates boosted event media quality for homepage merchandising surfaces.
 *
 * Reuses field_event_image + focal_point crop architecture (no new media types).
 */
final class BoostedEventQualityGate {

  private const EVENT_HERO_CROP_TYPE = 'event_hero';

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FocalPointManagerInterface $focalPointManager,
  ) {}

  /**
   * Whether the event has a readable hero image on disk.
   */
  public function hasHeroImage(NodeInterface $event): bool {
    return $this->resolveHeroFile($event) instanceof FileInterface;
  }

  /**
   * Whether the hero file has focal point data (focal_point or event_hero crop).
   */
  public function hasFocalPoint(NodeInterface $event): bool {
    $file = $this->resolveHeroFile($event);
    if (!$file instanceof FileInterface) {
      return FALSE;
    }

    $uri = $file->getFileUri();
    if ($uri === '') {
      return FALSE;
    }

    $focalCropType = $this->getFocalPointCropType();
    if ($focalCropType !== '' && Crop::findCrop($uri, $focalCropType) instanceof CropInterface) {
      return TRUE;
    }

    return Crop::findCrop($uri, self::EVENT_HERO_CROP_TYPE) instanceof CropInterface;
  }

  /**
   * Whether the event is eligible for hero, spotlight, and featured merchandising.
   */
  public function isMarketplaceReady(NodeInterface $event): bool {
    if ($event->bundle() !== 'event') {
      return FALSE;
    }

    return $this->hasHeroImage($event) && $this->hasFocalPoint($event);
  }

  private function resolveHeroFile(NodeInterface $event): ?FileInterface {
    if (!$event->hasField('field_event_image') || $event->get('field_event_image')->isEmpty()) {
      return NULL;
    }

    $file = $event->get('field_event_image')->entity;
    if (!$file instanceof FileInterface) {
      return NULL;
    }

    return $this->isHeroFileRenderable($file) ? $file : NULL;
  }

  private function isHeroFileRenderable(FileInterface $file): bool {
    $uri = $file->getFileUri();
    if ($uri === '') {
      return FALSE;
    }

    $real = $this->fileSystem->realpath($uri);
    if ($real === FALSE || !is_readable($real)) {
      return FALSE;
    }

    $mime = $file->getMimeType();
    if ($mime !== '' && !str_starts_with($mime, 'image/')) {
      return FALSE;
    }

    return TRUE;
  }

  private function getFocalPointCropType(): string {
    return (string) $this->configFactory->get('focal_point.settings')->get('crop_type');
  }

}
