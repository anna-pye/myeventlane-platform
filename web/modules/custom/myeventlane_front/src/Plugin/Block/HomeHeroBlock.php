<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Block\BlockManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the homepage hero block.
 *
 * @Block(
 *   id = "myeventlane_home_hero",
 *   admin_label = @Translation("Home Hero (MyEventLane)"),
 * )
 */
final class HomeHeroBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly BlockManagerInterface $blockManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   *
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('plugin.manager.block'),
    );
  }

  /**
   *
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

    return [
      '#theme' => 'myeventlane_home_hero',
      '#pills' => $pills,
      '#cache' => [
        'contexts' => ['url.path', 'languages:language_interface', 'user.permissions'],
        'tags' => [],
        'max-age' => 3600,
      ],
    ];
  }

}
