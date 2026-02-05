<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\myeventlane_page_visuals\Service\PageVisualResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the homepage hero block.
 *
 * Uses Page Visuals Manager for hero image when configured for system.front_page.
 *
 * @Block(
 *   id = "myeventlane_home_hero",
 *   admin_label = @Translation("Home Hero (MyEventLane)"),
 * )
 */
final class HomeHeroBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the block.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly BlockManagerInterface $blockManager,
    private readonly PageVisualResolver $pageVisualResolver,
    private readonly RouteMatchInterface $routeMatch,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('plugin.manager.block'),
      $container->get('myeventlane.page_visual_resolver'),
      $container->get('current_route_match'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $pills = [];
    try {
      $instance = $this->blockManager->createInstance('views_block:front_category_pills-pill', []);
      $pills = $instance->build();
      $pills['#cache']['contexts'][] = 'url.path';
    }
    catch (\Throwable $e) {
      $pills = [];
    }

    $pageVisual = $this->pageVisualResolver->resolveForRoute($this->routeMatch);
    $hero_image_url = NULL;
    $hero_image_url_mobile = NULL;
    $hero_hide_on_mobile = FALSE;
    $hero_alt = '';
    $cache_tags = ['config:myeventlane_page_visual_list'];
    $cache_contexts = ['url.path', 'languages:language_interface', 'user.permissions', 'route'];

    if ($pageVisual !== NULL) {
      $hero_image_url = $pageVisual['image_url'] ?? NULL;
      $hero_image_url_mobile = $pageVisual['image_url_mobile'] ?? NULL;
      $hero_hide_on_mobile = $pageVisual['hide_on_mobile'] ?? FALSE;
      $hero_alt = $pageVisual['alt'] ?? '';
      $cache = $pageVisual['_cache'] ?? [];
      $cache_tags = array_merge($cache_tags, $cache['tags'] ?? []);
      $cache_contexts = array_merge($cache_contexts, $cache['contexts'] ?? []);
    }

    $build = [
      '#theme' => 'myeventlane_home_hero',
      '#pills' => $pills,
      '#hero_image_url' => $hero_image_url,
      '#hero_image_url_mobile' => $hero_image_url_mobile,
      '#hero_hide_on_mobile' => $hero_hide_on_mobile,
      '#hero_alt' => $hero_alt,
      '#cache' => [
        'contexts' => array_unique($cache_contexts),
        'tags' => array_unique($cache_tags),
        'max-age' => 3600,
      ],
    ];

    return $build;
  }

}
