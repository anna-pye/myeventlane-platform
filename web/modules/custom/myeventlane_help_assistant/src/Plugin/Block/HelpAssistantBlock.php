<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_assistant\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Help Assistant block.
 *
 * @Block(
 *   id = "myeventlane_help_assistant_block",
 *   admin_label = @Translation("MyEventLane Help Assistant"),
 *   category = @Translation("MyEventLane")
 * )
 */
final class HelpAssistantBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ConfigFactoryInterface $configFactory,
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
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $config = $this->configFactory->get('myeventlane_help_assistant.settings');
    if (!(bool) $config->get('enabled')) {
      return [];
    }

    return [
      '#theme' => 'myeventlane_help_assistant_block',
      '#endpoint' => '/help/assistant',
      '#attached' => [
        'library' => [
          'myeventlane_help_assistant/assistant',
        ],
        'drupalSettings' => [
          'myeventlaneHelpAssistant' => [
            'endpoint' => '/help/assistant',
          ],
        ],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

}
