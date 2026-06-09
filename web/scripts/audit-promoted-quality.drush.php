<?php

/**
 * @file
 * Audits promoted event media quality gate (local verification).
 */

declare(strict_types=1);

use Drupal\crop\Entity\Crop;
use Drupal\node\Entity\Node;

$nids = array_map('intval', $argv ?? []);
if ($nids === [] || !isset($nids[0]) || str_contains((string) $nids[0], '.php')) {
  $nids = [1714, 1715, 1716];
}

/** @var \Drupal\myeventlane_event\Service\BoostedEventQualityGate $gate */
$gate = \Drupal::service('myeventlane_event.boosted_quality_gate');
$fs = \Drupal::service('file_system');
$style = \Drupal::entityTypeManager()->getStorage('image_style')->load('mel_event_card_featured');
$focalType = (string) \Drupal::config('focal_point.settings')->get('crop_type');

echo "=== Promoted event quality audit ===\n\n";

foreach ($nids as $nid) {
  $node = Node::load($nid);
  if ($node === NULL) {
    echo "NID {$nid}: NOT FOUND\n\n";
    continue;
  }

  $fid = $node->get('field_event_image')->target_id ?? NULL;
  $file = $node->get('field_event_image')->entity;
  $uri = $file?->getFileUri() ?? '';
  $real = $uri !== '' ? $fs->realpath($uri) : FALSE;
  $disk = ($real !== FALSE && is_readable($real)) ? 'yes' : 'no';
  $styleUrl = '';
  if ($style && $uri !== '' && $disk === 'yes') {
    $styleUrl = $style->buildUrl($uri);
  }

  $focalCrop = $uri !== '' ? Crop::findCrop($uri, $focalType) : NULL;
  $heroCrop = $uri !== '' ? Crop::findCrop($uri, 'event_hero') : NULL;

  echo "NID: {$nid}\n";
  echo "Title: {$node->label()}\n";
  echo "Promoted: " . ((int) ($node->get('field_promoted')->value ?? 0)) . "\n";
  echo "field_event_image target_id: " . ($fid ?? 'NULL') . "\n";
  echo "File URI: " . ($uri !== '' ? $uri : 'NULL') . "\n";
  echo "File on disk: {$disk}\n";
  echo "Image style URL: " . ($styleUrl !== '' ? $styleUrl : 'NULL') . "\n";
  echo "focal_point crop: " . ($focalCrop ? 'yes' : 'no') . "\n";
  echo "event_hero crop: " . ($heroCrop ? 'yes' : 'no') . "\n";
  echo "hasHeroImage(): " . ($gate->hasHeroImage($node) ? 'TRUE' : 'FALSE') . "\n";
  echo "hasFocalPoint(): " . ($gate->hasFocalPoint($node) ? 'TRUE' : 'FALSE') . "\n";
  echo "isMarketplaceReady(): " . ($gate->isMarketplaceReady($node) ? 'TRUE' : 'FALSE') . "\n\n";
}

echo "=== All promoted upcoming ===\n\n";
$promotedNids = \Drupal::entityQuery('node')
  ->accessCheck(FALSE)
  ->condition('type', 'event')
  ->condition('status', 1)
  ->condition('field_promoted', 1)
  ->execute();

foreach ($promotedNids as $nid) {
  $node = Node::load($nid);
  if ($node === NULL) {
    continue;
  }
  echo implode(' | ', [
    (string) $nid,
    $node->label(),
    'ready=' . ($gate->isMarketplaceReady($node) ? 'yes' : 'NO'),
    'img=' . ($gate->hasHeroImage($node) ? 'yes' : 'NO'),
    'focal=' . ($gate->hasFocalPoint($node) ? 'yes' : 'NO'),
  ]) . "\n";
}
