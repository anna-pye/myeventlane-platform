<?php

declare(strict_types=1);

namespace Drupal\myeventlane_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\myeventlane_core\Service\FrontCategoryStatsService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the MEL event categories strip block for homepage.
 *
 * @Block(
 *   id = "myeventlane_event_categories_strip",
 *   admin_label = @Translation("MyEventLane: Event Categories Strip"),
 *   category = @Translation("MyEventLane")
 * )
 */
final class EventCategoriesStripBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly FrontCategoryStatsService $frontCategoryStats,
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
      $container->get('myeventlane_core.front_category_stats')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $data = $this->frontCategoryStats->buildFrontStats();

    $build = [
      '#theme' => 'myeventlane_category_pills',
      '#categories' => $data['items'] ?? [],
    ];

    $cacheable = new CacheableMetadata();
    $cacheable->setCacheMaxAge((int) ($data['cache']['max_age'] ?? 3600));
    $cacheable->setCacheTags((array) ($data['cache']['tags'] ?? []));
    $cacheable->applyTo($build);

    return $build;
  }

}
