<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_assistant\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\myeventlane_help_assistant\Service\HelpContextResolver;
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
    private readonly HelpContextResolver $helpContextResolver,
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
      $container->get('myeventlane_help_assistant.context_resolver'),
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

    $starters = _myeventlane_help_assistant_starter_list($config);
    $page_context = $this->helpContextResolver->exportClientEchoPayload(
      $this->helpContextResolver->resolve(NULL),
    );

    return [
      '#theme' => 'myeventlane_help_assistant_block',
      '#endpoint' => '/help/assistant',
      '#starter_questions' => $starters,
      '#support_url' => _myeventlane_help_assistant_support_ticket_path(),
      '#attached' => [
        'drupalSettings' => [
          'myeventlaneHelpAssistant' => [
            'endpoint' => '/help/assistant',
            'starters' => $starters,
            'pageContext' => $page_context,
          ],
        ],
      ],
      '#cache' => [
        'max-age' => 0,
        'tags' => ['config:myeventlane_help_assistant.settings'],
        'contexts' => ['user.permissions', 'route'],
      ],
    ];
  }

}
