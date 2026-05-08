<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface\Block;

use Drupal\Core\Block\BlockPluginInterface;
use Drupal\myeventlane_core\MelSurfaceId;
use Drupal\myeventlane_surface\SurfaceAccessHelper;
use Drupal\myeventlane_surface\SurfaceResolver;

/**
 * Centralised visibility governance for blocks that should not leak across surfaces.
 */
final class SurfaceBlockGovernor {

  /**
   * Plugins frequently placed in global regions that distract on auth/checkout.
   *
   * @var list<string>
   */
  private const AUTH_CHECKOUT_DENY_PLUGINS = [
    'commerce_cart',
    'views_block:featured_events-block_spotlight',
    'views_block:mel_home_events-discover',
    'myeventlane_home_hero',
  ];

  public function __construct(
    private readonly SurfaceResolver $resolver,
    private readonly SurfaceAccessHelper $accessHelper,
  ) {}

  /**
   * @param array<string, mixed> $build
   */
  public function alter(array &$build, BlockPluginInterface $block): void {
    $surface = $this->resolver->resolve();
    $checkout_sensitive = $this->accessHelper->isCheckoutSensitiveRequest();

    if ($surface !== MelSurfaceId::Auth && !$checkout_sensitive) {
      return;
    }

    $plugin_id = $block->getPluginId();
    if (!$this->shouldDenyPlugin($plugin_id)) {
      return;
    }

    $build['#access'] = FALSE;
    $build['#cache']['contexts'][] = 'route';
  }

  private function shouldDenyPlugin(string $plugin_id): bool {
    foreach (self::AUTH_CHECKOUT_DENY_PLUGINS as $candidate) {
      if ($plugin_id === $candidate) {
        return TRUE;
      }
    }

    // Catch dynamic Views block plugin IDs without enumerating every display.
    if (str_starts_with($plugin_id, 'views_block:mel_home_events_discover')) {
      return TRUE;
    }

    return FALSE;
  }

}
