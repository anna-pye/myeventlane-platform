<?php

/**
 * @file
 * Finds field.storage.* and field.field.* configs with empty or invalid type.
 *
 * Runs with direct DB access (no Drupal bootstrap) to avoid triggering
 * the PluginNotFoundException that occurs when Drupal loads these configs.
 *
 * Usage (from project root):
 *   ddev exec php web/scripts/find_bad_field_storage.php
 *   ddev exec php web/scripts/find_bad_field_storage.php --fix
 *
 * With --fix: deletes the bad config(s) from the database.
 * Without --fix: only reports which config(s) are bad.
 */

$fix = in_array('--fix', $argv ?? [], true);

// Determine project root and load DB credentials.
$script_dir = dirname(__FILE__);
$project_root = realpath($script_dir . '/../..');
chdir($project_root);

// Load settings to get $databases. Minimal include to avoid side effects.
$settings_ddev = $project_root . '/web/sites/default/settings.ddev.php';
if (!file_exists($settings_ddev)) {
  fwrite(STDERR, "settings.ddev.php not found. Run from DDEV or adapt DB credentials.\n");
  exit(1);
}
$databases = [];
require $settings_ddev;

$db = $databases['default']['default'] ?? null;
if (!$db) {
  fwrite(STDERR, "Could not load database config.\n");
  exit(1);
}

$dsn = sprintf(
  'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
  $db['host'],
  $db['port'] ?? 3306,
  $db['database']
);

try {
  $pdo = new PDO($dsn, $db['username'], $db['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  ]);
} catch (PDOException $e) {
  fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
  exit(1);
}

$bad = [];

// Check field.storage.* (uses 'type' key).
$stmt = $pdo->prepare(
  "SELECT name, data FROM config WHERE collection = '' AND name LIKE 'field.storage.%'"
);
$stmt->execute();
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $data = @unserialize($row['data'], ['allowed_classes' => FALSE]);
  if (!is_array($data)) {
    $bad[] = ['name' => $row['name'], 'reason' => 'unserialize failed', 'type_value' => null];
    continue;
  }
  $type = $data['type'] ?? null;
  if ($type === '' || $type === null || !is_string($type)) {
    $bad[] = [
      'name' => $row['name'],
      'reason' => 'type is empty or invalid',
      'type_value' => $type,
    ];
  }
}

// Check field.field.* (uses 'field_type' key).
$stmt = $pdo->prepare(
  "SELECT name, data FROM config WHERE collection = '' AND name LIKE 'field.field.%'"
);
$stmt->execute();
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $data = @unserialize($row['data'], ['allowed_classes' => FALSE]);
  if (!is_array($data)) {
    $bad[] = ['name' => $row['name'], 'reason' => 'unserialize failed', 'type_value' => null];
    continue;
  }
  $type = $data['field_type'] ?? null;
  if ($type === '' || $type === null || !is_string($type)) {
    $bad[] = [
      'name' => $row['name'],
      'reason' => 'field_type is empty or invalid',
      'type_value' => $type,
    ];
  }
}

if (empty($bad)) {
  echo "No invalid field.storage or field.field configs found.\n";
  exit(0);
}

echo "Found " . count($bad) . " invalid config(s):\n";
foreach ($bad as $b) {
  $type_str = isset($b['type_value']) ? ' (type: ' . var_export($b['type_value'], TRUE) . ')' : '';
  echo "  - {$b['name']}{$type_str}\n";
}

if (!$fix) {
  echo "\nTo remove these from the database, run:\n";
  echo "  ddev exec php web/scripts/find_bad_field_storage.php --fix\n";
  exit(0);
}

// Delete the bad configs.
$del = $pdo->prepare("DELETE FROM config WHERE collection = '' AND name = ?");
foreach ($bad as $b) {
  $del->execute([$b['name']]);
  echo "Deleted: {$b['name']}\n";
}

echo "\nDone. Run 'ddev drush cr' to clear caches.\n";
