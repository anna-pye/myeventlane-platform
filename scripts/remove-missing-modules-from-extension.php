<?php

/**
 * @file
 * One-off script: remove myeventlane_account and myeventlane_page_visuals from
 * core.extension in the database so the site can bootstrap (module files are
 * missing). Run with: ddev exec php /var/www/html/scripts/remove-missing-modules-from-extension.php
 *
 * Requires: run inside DDEV so settings.ddev.php is loaded and $databases exists.
 */

$repo_root = dirname(__DIR__);
$app_root = $repo_root . '/web';
$site_path = 'sites/default';

if (!file_exists($app_root . '/' . $site_path . '/settings.php')) {
  fwrite(STDERR, "Error: settings.php not found. Run from repo root inside DDEV.\n");
  exit(1);
}

// Load settings to get $databases (and settings.ddev.php when in DDEV).
$app_root_real = $app_root;
require $app_root . '/' . $site_path . '/settings.php';

if (empty($databases['default']['default'])) {
  fwrite(STDERR, "Error: No database config. Run inside DDEV: ddev exec php /var/www/html/scripts/remove-missing-modules-from-extension.php\n");
  exit(1);
}

$db = $databases['default']['default'];
$prefix = $db['prefix'] ?? '';
$config_table = (is_array($prefix) ? ($prefix['default'] ?? '') : $prefix) . 'config';

$dsn = sprintf(
  'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
  $db['host'] ?? 'db',
  $db['port'] ?? '3306',
  $db['database']
);
$pdo = new PDO($dsn, $db['username'], $db['password'], [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$stmt = $pdo->prepare("SELECT data FROM `$config_table` WHERE collection = ? AND name = ?");
// Drupal uses empty string for default collection.
$stmt->execute(['', 'core.extension']);
$raw = $stmt->fetchColumn();
if ($raw === false) {
  fwrite(STDERR, "Error: core.extension config not found.\n");
  exit(1);
}

// Drupal stores config as PHP serialized.
$data = @unserialize($raw);
if (!is_array($data) || !isset($data['module'])) {
  fwrite(STDERR, "Error: core.extension data invalid.\n");
  exit(1);
}

$to_remove = ['myeventlane_account', 'myeventlane_page_visuals'];
$removed = [];
foreach ($to_remove as $module) {
  if (isset($data['module'][$module])) {
    unset($data['module'][$module]);
    $removed[] = $module;
  }
}

if (empty($removed)) {
  echo "No missing modules found in core.extension (already removed?).\n";
  exit(0);
}

$serialized = serialize($data);
$stmt = $pdo->prepare("UPDATE `$config_table` SET data = ? WHERE collection = ? AND name = ?");
$stmt->execute([$serialized, '', 'core.extension']);

echo "Removed from core.extension: " . implode(', ', $removed) . "\n";
echo "Clear cache: ddev drush cr\n";
