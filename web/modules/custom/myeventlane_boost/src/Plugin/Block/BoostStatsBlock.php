<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\myeventlane_boost\BoostManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a block showing boost statistics.
 *
 * @Block(
 *   id = "myeventlane_boost_stats_block",
 *   admin_label = @Translation("Boost: Stats"),
 *   category = @Translation("MyEventLane")
 * )
 */
final class BoostStatsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a BoostStatsBlock.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\myeventlane_boost\BoostManager $boostManager
   *   The boost manager service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly BoostManager $boostManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('myeventlane_boost.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    // Get active boosted events (all stores, no access check for stats).
    $activeIds = $this->boostManager->getActiveBoostedEventIdsForStore(NULL, [
      'access_check' => FALSE,
    ]);
    $active = count($activeIds);

    // Get expiring boosts (within 48 hours) using canonical API.
    $expiringIds = $this->boostManager->getExpiringBoostedEventIdsForStore(NULL, 48 * 3600, [
      'access_check' => FALSE,
    ]);
    $expiringCount = count($expiringIds);

    return [
      '#theme' => 'item_list',
      '#items' => [
        $this->t('Active boosts: @n', ['@n' => $active]),
        $this->t('Expiring ≤ 48h: @n', ['@n' => $expiringCount]),
      ],
      '#cache' => [
        'max_age' => 300,
        'contexts' => ['user.permissions'],
        'tags' => ['node_list:event', 'myeventlane_boost:stats'],
      ],
    ];
  }

}
