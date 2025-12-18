<?php

namespace Drupal\myeventlane_theme\Service;

use Drupal\Core\Extension\ExtensionList;

/**
 * Resolves Vite-built asset paths for the theme.
 */
class AssetResolver {

  /**
   * The theme extension list service.
   *
   * @var \Drupal\Core\Extension\ExtensionList
   */
  protected ExtensionList $themeList;

  /**
   * Constructs an AssetResolver.
   *
   * @param \Drupal\Core\Extension\ExtensionList $theme_list
   *   The theme extension list service.
   */
  public function __construct(ExtensionList $theme_list) {
    $this->themeList = $theme_list;
  }

  /**
   * Returns the full path to Vite manifest.json.
   *
   * @return string|null
   *   The manifest path, or NULL if it is missing.
   */
  public function getManifestPath(): ?string {
    $path = $this->themeList->getPath('myeventlane_theme');
    $manifest = DRUPAL_ROOT . '/' . $path . '/dist/.vite/manifest.json';

    return file_exists($manifest) ? $manifest : NULL;
  }

}
