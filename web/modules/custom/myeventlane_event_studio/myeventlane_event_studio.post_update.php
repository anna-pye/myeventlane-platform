<?php

declare(strict_types=1);

use Drupal\Core\Image\ImageFactory;
use Drupal\crop\Entity\Crop;
use Drupal\crop\CropInterface;
use Drupal\file\FileInterface;
use Drupal\focal_point\FocalPointManagerInterface;

/**
 * Backfill event_hero crops for legacy focal-only hero images.
 *
 * One-time post-update for hero 16:9 unification. Creates an event_hero crop
 * centred on the existing focal_point for hero files that lack event_hero.
 */
function myeventlane_event_studio_post_update_backfill_event_hero_from_focal(array &$sandbox): string {
  $entity_type_manager = \Drupal::entityTypeManager();
  $image_factory = \Drupal::service('image.factory');
  assert($image_factory instanceof ImageFactory);
  $focal_point_manager = \Drupal::service('focal_point.manager');
  assert($focal_point_manager instanceof FocalPointManagerInterface);
  $logger = \Drupal::logger('myeventlane_event_studio');
  $focal_crop_type = (string) \Drupal::config('focal_point.settings')->get('crop_type');
  if ($focal_crop_type === '') {
    $focal_crop_type = 'focal_point';
  }

  if (!isset($sandbox['total'])) {
    $file_ids = [];
    $node_storage = $entity_type_manager->getStorage('node');
    $nids = $entity_type_manager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->exists('field_event_image')
      ->execute();

    foreach ($node_storage->loadMultiple($nids) as $node) {
      $file = $node->get('field_event_image')->entity;
      if ($file instanceof FileInterface && $file->id() !== NULL) {
        $file_ids[(int) $file->id()] = (int) $file->id();
      }
    }

    $sandbox['file_ids'] = array_values($file_ids);
    $sandbox['total'] = count($sandbox['file_ids']);
    $sandbox['current'] = 0;
    $sandbox['created'] = 0;
    $sandbox['skipped'] = 0;
    $sandbox['errors'] = 0;
  }

  $crop_storage = $entity_type_manager->getStorage('crop');
  $batch_size = 10;
  $slice = array_slice($sandbox['file_ids'], $sandbox['current'], $batch_size);
  $file_storage = $entity_type_manager->getStorage('file');

  foreach ($slice as $fid) {
    $sandbox['current']++;
    $file = $file_storage->load($fid);
    if (!$file instanceof FileInterface) {
      $sandbox['skipped']++;
      continue;
    }

    $uri = $file->getFileUri();
    if ($uri === '') {
      $sandbox['skipped']++;
      continue;
    }

    if (Crop::findCrop($uri, 'event_hero') instanceof CropInterface) {
      $sandbox['skipped']++;
      continue;
    }

    $image = $image_factory->get($uri);
    if (!$image->isValid()) {
      $logger->warning('Hero 16:9 backfill: skipping unreadable file @fid (@uri).', [
        '@fid' => (string) $fid,
        '@uri' => $uri,
      ]);
      $sandbox['errors']++;
      continue;
    }

    $img_w = $image->getWidth();
    $img_h = $image->getHeight();
    $center = _myeventlane_event_studio_hero_backfill_focal_center(
      $file,
      $uri,
      $focal_crop_type,
      $focal_point_manager,
      $image_factory,
      $img_w,
      $img_h,
    );

    if ($center === NULL) {
      $sandbox['skipped']++;
      continue;
    }

    $dimensions = _myeventlane_event_studio_hero_backfill_16x9_dimensions(
      $center['x'],
      $center['y'],
      $img_w,
      $img_h,
    );
    if ($dimensions === NULL) {
      $logger->warning('Hero 16:9 backfill: could not fit 16:9 crop for file @fid (@uri).', [
        '@fid' => (string) $fid,
        '@uri' => $uri,
      ]);
      $sandbox['errors']++;
      continue;
    }

    /** @var \Drupal\crop\CropInterface $crop */
    $crop = $crop_storage->create([
      'type' => 'event_hero',
      'entity_id' => $file->id(),
      'entity_type' => 'file',
      'uri' => $uri,
    ]);
    $crop->setPosition($center['x'], $center['y']);
    $crop->setSize($dimensions['width'], $dimensions['height']);
    $crop->save();

    $sandbox['created']++;
    $logger->notice('Hero 16:9 backfill: created event_hero crop for file @fid (centre @x,@y; @w×@h).', [
      '@fid' => (string) $fid,
      '@x' => (string) $center['x'],
      '@y' => (string) $center['y'],
      '@w' => (string) $dimensions['width'],
      '@h' => (string) $dimensions['height'],
    ]);
  }

  if ($sandbox['current'] >= $sandbox['total']) {
    $summary = (string) t('Hero 16:9 backfill complete: @created created, @skipped skipped, @errors errors (@total hero files scanned).', [
      '@created' => $sandbox['created'],
      '@skipped' => $sandbox['skipped'],
      '@errors' => $sandbox['errors'],
      '@total' => $sandbox['total'],
    ]);
    $logger->notice('Hero 16:9 backfill finished: @summary', ['@summary' => $summary]);
    $sandbox['#finished'] = 1;
    return $summary;
  }

  $sandbox['#finished'] = $sandbox['total'] > 0
    ? $sandbox['current'] / $sandbox['total']
    : 1;
  return (string) t('Hero 16:9 backfill: processed @current of @total hero files (@created created so far).', [
    '@current' => $sandbox['current'],
    '@total' => $sandbox['total'],
    '@created' => $sandbox['created'],
  ]);
}

/**
 * Resolves focal centre in absolute pixels for a hero file.
 *
 * @return array{x: int, y: int}|null
 *   Centre coordinates, or NULL when no focal_point data exists.
 */
function _myeventlane_event_studio_hero_backfill_focal_center(
  FileInterface $file,
  string $uri,
  string $focal_crop_type,
  FocalPointManagerInterface $focal_point_manager,
  ImageFactory $image_factory,
  int $img_w,
  int $img_h,
): ?array {
  $focal_crop = Crop::findCrop($uri, $focal_crop_type);
  if ($focal_crop instanceof CropInterface && !$focal_crop->get('x')->isEmpty() && !$focal_crop->get('y')->isEmpty()) {
    return [
      'x' => (int) $focal_crop->get('x')->value,
      'y' => (int) $focal_crop->get('y')->value,
    ];
  }

  $node_storage = \Drupal::entityTypeManager()->getStorage('node');
  $nids = $node_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'event')
    ->condition('field_event_image.target_id', $file->id())
    ->range(0, 1)
    ->execute();

  if ($nids === []) {
    return NULL;
  }

  $node = $node_storage->load(reset($nids));
  if ($node === NULL || $node->get('field_event_image')->isEmpty()) {
    return NULL;
  }

  $item = $node->get('field_event_image')->first()?->getValue() ?? [];
  $focal_value = trim((string) ($item['focal_point'] ?? ''));
  if ($focal_value === '' || !$focal_point_manager->validateFocalPoint($focal_value)) {
    return NULL;
  }

  [$rel_x, $rel_y] = array_map('intval', explode(',', $focal_value));
  $absolute = $focal_point_manager->relativeToAbsolute($rel_x, $rel_y, $img_w, $img_h);
  return [
    'x' => (int) $absolute['x'],
    'y' => (int) $absolute['y'],
  ];
}

/**
 * Computes the largest 16:9 crop rectangle centred on a point within an image.
 *
 * @return array{width: int, height: int}|null
 */
function _myeventlane_event_studio_hero_backfill_16x9_dimensions(
  int $center_x,
  int $center_y,
  int $img_w,
  int $img_h,
): ?array {
  if ($img_w < 1 || $img_h < 1) {
    return NULL;
  }

  $center_x = max(0, min($center_x, $img_w));
  $center_y = max(0, min($center_y, $img_h));

  $max_width = (int) floor(min(
    $center_x * 2,
    ($img_w - $center_x) * 2,
    (int) floor($center_y * 2 * 16 / 9),
    (int) floor(($img_h - $center_y) * 2 * 16 / 9),
    $img_w,
  ));

  if ($max_width < 16) {
    return NULL;
  }

  $height = (int) round($max_width * 9 / 16);
  if ($height < 9) {
    return NULL;
  }

  // Reconcile rounding so height stays within image bounds.
  while ($height > 0 && ($center_y - (int) floor($height / 2) < 0 || $center_y + (int) ceil($height / 2) > $img_h)) {
    $height--;
  }
  $width = (int) round($height * 16 / 9);
  while ($width > 0 && ($center_x - (int) floor($width / 2) < 0 || $center_x + (int) ceil($width / 2) > $img_w)) {
    $width--;
    $height = (int) round($width * 9 / 16);
  }

  if ($width < 16 || $height < 9) {
    return NULL;
  }

  return [
    'width' => $width,
    'height' => $height,
  ];
}
