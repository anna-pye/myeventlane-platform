<?php

/**
 * Run: cd web && ../vendor/bin/drush php:script ../scripts/mel-twig-hash-map.php
 */
$t = \Drupal::service('twig');
$target = '037882a2c32da1eff33dcdc0de709d77';

$bundles = [
  ['myeventlane_vendor_theme', \Drupal::service('extension.list.theme')->getPath('myeventlane_vendor_theme') . '/templates'],
  ['myeventlane_theme', \Drupal::service('extension.list.theme')->getPath('myeventlane_theme') . '/templates'],
  ['myeventlane_boost', \Drupal::service('extension.list.module')->getPath('myeventlane_boost') . '/templates'],
];

$checked = 0;
foreach ($bundles as [$theme, $dir]) {
  if (!is_dir($dir)) {
    echo "SKIP missing dir: $dir\n";
    continue;
  }
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
  foreach ($it as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'twig') {
      continue;
    }
    $checked++;
    $rel = str_replace($dir . DIRECTORY_SEPARATOR, '', $file->getPathname());
    $rel = str_replace('\\', '/', $rel);
    $logical = '@' . $theme . '/' . $rel;
    try {
      $cls = $t->getTemplateClass($logical);
      $h = substr($cls, strlen('__TwigTemplate_'));
      if ($h === $target) {
        echo "MATCH: $logical\n";
        echo "FILE: " . $file->getPathname() . "\n";
      }
    }
    catch (\Throwable $e) {
      // skip
    }
  }
}

$names = [
  '@myeventlane_vendor_theme/layout/console-page.html.twig',
  '@myeventlane_vendor_theme/templates/layout/console-page.html.twig',
];
foreach ($names as $logical) {
  try {
    $cls = $t->getTemplateClass($logical);
    $h = substr($cls, strlen('__TwigTemplate_'));
    echo "TRY: $h $logical\n";
  }
  catch (\Throwable $e) {
    echo "ERR $logical: " . $e->getMessage() . "\n";
  }
}

echo "Scanned $checked twig files.\n";
