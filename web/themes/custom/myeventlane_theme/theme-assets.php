<?php

/**
 * @file
 * Loads Vite manifest and returns hashed asset paths.
 */

/**
 * Returns the built asset path for a Vite output file.
 *
 * @param string $filename
 *   The logical asset filename.
 *
 * @return string
 *   The resolved asset path.
 */
function myeventlane_theme_asset($filename) {
  $theme_path = drupal_get_path('theme', 'myeventlane_theme');
  $manifest = $theme_path . '/dist/assets.json';

  if (!file_exists($manifest)) {
    return $theme_path . '/dist/' . $filename;
  }

  $data = json_decode(file_get_contents($manifest), TRUE);
  return isset($data[$filename]) ? $theme_path . '/dist/' . $data[$filename] : $theme_path . '/dist/' . $filename;
}
