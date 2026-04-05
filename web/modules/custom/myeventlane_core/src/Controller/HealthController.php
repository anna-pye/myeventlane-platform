<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Database\Database;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;

final class HealthController extends ControllerBase {

  public function __construct(
    private readonly CacheBackendInterface $cache,
    private readonly ThemeHandlerInterface $themeHandler,
  ) {}

  public static function create($container): self {
    return new self(
      $container->get('cache.default'),
      $container->get('theme_handler'),
    );
  }

  public function check(): Response {
    try {
      // 1. Database check
      Database::getConnection()->query('SELECT 1')->fetchField();

      // 2. Cache write/read check
      $this->cache->set('mel_health_check', 'ok', time() + 60);
      $cache = $this->cache->get('mel_health_check');

      if (!$cache || $cache->data !== 'ok') {
        return new Response('cache failed', 503);
      }

      // 3. Theme asset check
      $default_theme = \Drupal::config('system.theme')->get('default');
      $theme_path = $this->themeHandler->getTheme($default_theme)?->getPath();

      if (!$theme_path) {
        return new Response('theme missing', 503);
      }

      $asset_path = DRUPAL_ROOT . '/' . $theme_path . '/dist/assets';

      if (!is_dir($asset_path)) {
        return new Response('assets missing', 503);
      }

      $css_files = glob($asset_path . '/main*.css');

      if (count($css_files) !== 1) {
        return new Response('css invalid', 503);
      }

      return new Response('ok', 200);

    } catch (\Throwable $e) {
      \Drupal::logger('myeventlane_core')->error($e->getMessage());
      return new Response('error', 503);
    }
  }
}