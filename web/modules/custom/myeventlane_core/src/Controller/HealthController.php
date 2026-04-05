<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ThemeExtensionList;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Minimal deploy / load-balancer health checks (DB, cache, theme assets).
 */
final class HealthController extends ControllerBase {

  public function __construct(
    private readonly Connection $connection,
    private readonly CacheBackendInterface $cache,
    private readonly ThemeExtensionList $themeExtensionList,
    private readonly TimeInterface $time,
    private readonly string $appRoot,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('cache.default'),
      $container->get('extension.list.theme'),
      $container->get('datetime.time'),
      (string) $container->getParameter('app.root'),
    );
  }

  /**
   * Returns 200 when database, cache, and default theme built assets are usable.
   */
  public function health(): Response {
    try {
      $this->connection->query('SELECT 1')->fetchField();
    }
    catch (\Throwable $e) {
      $this->getLogger('myeventlane_core')->error('Health check failed (database): @message', [
        '@message' => $e->getMessage(),
      ]);
      return new Response('unhealthy: database', Response::HTTP_SERVICE_UNAVAILABLE);
    }

    $token = Crypt::randomBytesBase64(16);
    $cid = 'myeventlane_core.health_check';
    $expire = $this->time->getRequestTime() + 120;
    try {
      $this->cache->set($cid, $token, $expire);
      $item = $this->cache->get($cid);
      if (!$item || $item->data !== $token) {
        $this->getLogger('myeventlane_core')->error('Health check failed: cache read/write mismatch.');
        return new Response('unhealthy: cache', Response::HTTP_SERVICE_UNAVAILABLE);
      }
    }
    catch (\Throwable $e) {
      $this->getLogger('myeventlane_core')->error('Health check failed (cache): @message', [
        '@message' => $e->getMessage(),
      ]);
      return new Response('unhealthy: cache', Response::HTTP_SERVICE_UNAVAILABLE);
    }

    $default_theme = (string) $this->config('system.theme')->get('default');
    if ($default_theme === '') {
      $this->getLogger('myeventlane_core')->error('Health check failed: no default theme configured.');
      return new Response('unhealthy: theme', Response::HTTP_SERVICE_UNAVAILABLE);
    }

    $relative_theme_path = $this->themeExtensionList->getPath($default_theme);
    $theme_full_path = $relative_theme_path !== ''
      ? $this->appRoot . '/' . $relative_theme_path
      : '';
    if ($theme_full_path === '' || !is_dir($theme_full_path)) {
      $this->getLogger('myeventlane_core')->error('Health check failed: theme path missing for @theme.', [
        '@theme' => $default_theme,
      ]);
      return new Response('unhealthy: theme', Response::HTTP_SERVICE_UNAVAILABLE);
    }

    $assets_dir = $theme_full_path . '/dist/assets';
    if (!is_dir($assets_dir) || !is_readable($assets_dir)) {
      $this->getLogger('myeventlane_core')->error('Health check failed: dist/assets missing for @theme.', [
        '@theme' => $default_theme,
      ]);
      return new Response('unhealthy: theme', Response::HTTP_SERVICE_UNAVAILABLE);
    }

    $css_files = glob($assets_dir . '/main*.css') ?: [];
    if (\count($css_files) !== 1) {
      $this->getLogger('myeventlane_core')->error('Health check failed: expected 1 main*.css in @dir, found @count.', [
        '@dir' => $assets_dir,
        '@count' => (string) \count($css_files),
      ]);
      return new Response('unhealthy: theme', Response::HTTP_SERVICE_UNAVAILABLE);
    }

    return new Response('ok', Response::HTTP_OK, [
      'Content-Type' => 'text/plain; charset=UTF-8',
      'Cache-Control' => 'no-store',
    ]);
  }

}
