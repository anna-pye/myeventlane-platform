<?php

/**
 * @file
 * Fix missing image derivative notices by clearing content references to a
 * missing source file. Run from project root: ddev drush scr scripts/fix-missing-event-image.php
 *
 * Target file: public://events/event_11_-_Ticketed.png (or .avif if in logs).
 * Fix: Clear field_event_image on affected node(s) so Drupal stops generating
 * derivatives for the missing file. Does not suppress logs or recreate files.
 */

use Drupal\file\Entity\File;

// Try both URIs: DB may have .png while logs show .avif derivative path.
$uris = [
  'public://events/event_11_-_Ticketed.png.avif',
  'public://events/event_11_-_Ticketed.png',
];

$fileStorage = \Drupal::entityTypeManager()->getStorage('file');
$nodeStorage = \Drupal::entityTypeManager()->getStorage('node');
$fileUsage = \Drupal::service('file.usage');
$connection = \Drupal::database();

// 1) Collect all fids that match any target URI (multiple file_managed rows can share same URI).
$fids = [];
foreach ($uris as $uri) {
  $files = $fileStorage->loadByProperties(['uri' => $uri]);
  foreach ($files as $f) {
    $fids[(int) $f->id()] = TRUE;
  }
  $rows = $connection->query(
    "SELECT fid FROM {file_managed} WHERE uri = :uri",
    [':uri' => $uri]
  )->fetchAll();
  foreach ($rows as $row) {
    $fids[(int) $row->fid] = TRUE;
  }
}
$fids = array_keys($fids);
$all_affected = [];

if (empty($fids)) {
  // No file_managed row for these URIs. Find nodes with orphaned image refs.
  $table = 'node__field_event_image';
  if (!$connection->schema()->tableExists($table)) {
    echo "No file_managed row for URI and no table {$table}. Nothing to fix.\n";
  }
  else {
    $orphaned = $connection->query(
      "SELECT n.entity_id, n.field_event_image_target_id FROM {" . $connection->escapeTable($table) . "} n
       LEFT JOIN {file_managed} f ON n.field_event_image_target_id = f.fid
       WHERE f.fid IS NULL AND n.bundle = 'event'"
    )->fetchAll();
    $affected = [];
    foreach ($orphaned as $row) {
      $node = $nodeStorage->load($row->entity_id);
      if ($node && $node->hasField('field_event_image') && !$node->get('field_event_image')->isEmpty()) {
        $node->set('field_event_image', []);
        $node->save();
        $affected[] = $row->entity_id;
      }
    }
    if (!empty($affected)) {
      echo "Cleared field_event_image on node(s): " . implode(', ', array_unique($affected)) . " (orphaned file refs; no file_managed row for URI).\n";
      $all_affected = array_merge($all_affected, $affected);
    }
    else {
      echo "No file_managed row for URI and no orphaned event image refs. Nothing to fix (already fixed or no bad refs).\n";
    }
  }
}
else {
  // 2) For each fid: get usage, clear node field, delete file entity.
foreach ($fids as $fid) {
  $file = $fileStorage->load($fid);
  $usage = [];
  if ($file instanceof File) {
    $usage = $fileUsage->listUsage($file);
  }
  else {
    $table = 'node__field_event_image';
    if ($connection->schema()->tableExists($table)) {
      $refs = $connection->query(
        "SELECT entity_id FROM {" . $connection->escapeTable($table) . "} WHERE field_event_image_target_id = :fid",
        [':fid' => $fid]
      )->fetchCol();
      foreach (array_unique($refs) as $nid) {
        $usage['file']['node'][$nid] = 1;
      }
    }
  }

  $affected = [];
  if (!empty($usage['file']['node'])) {
    foreach (array_keys($usage['file']['node']) as $nid) {
      $node = $nodeStorage->load($nid);
      if (!$node || !$node->hasField('field_event_image')) {
        continue;
      }
      $items = $node->get('field_event_image');
      $clear = FALSE;
      foreach ($items as $delta => $item) {
        if ((int) $item->target_id === $fid) {
          $clear = TRUE;
          break;
        }
      }
      if ($clear) {
        $node->set('field_event_image', []);
        $node->save();
        $affected[] = $nid;
        $all_affected[] = $nid;
      }
    }
  }

  if ($file instanceof File) {
    $file->delete();
  }
  if (!empty($affected)) {
    echo "Cleared field_event_image on node(s): " . implode(', ', $affected) . " and removed file entity (fid={$fid}).\n";
  }
  }
}

if (!empty($all_affected)) {
  echo "Affected node(s): " . implode(', ', array_unique($all_affected)) . ".\n";
}
echo "Done. Run: ddev drush ws --count=20  (and flush image style cache if needed).\n";
// Do not use exit() — Drush treats explicit exit as "command terminated abnormally".
