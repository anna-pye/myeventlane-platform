<?php

/**
 * @file
 * DANGEROUS — deletes ALL event nodes in the active database.
 *
 * Safe environment: local DDEV only, disposable database.
 * Destructive: YES — irreversible entity deletion.
 * Required confirmation: export a DB backup first; type the event count aloud
 * before running: ddev drush php:script scripts/dangerous/delete-events.php
 *
 * Do NOT run on staging or production.
 */

// Load all event node IDs without access checking.
$ids = \Drupal::entityQuery('node')
  ->condition('type', 'event')
  ->accessCheck(FALSE)
  ->execute();

if ($ids) {
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  $nodes = $storage->loadMultiple($ids);
  $storage->delete($nodes);
}

print "Deleted " . count($ids) . " event nodes.\n";
