<?php

declare(strict_types=1);

namespace Drupal\mel_guide\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\mel_guide\Service\MelGuideContext;
use Drupal\mel_guide\Service\MelGuideVisibility;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the MEL Guide discovery companion block.
 *
 * @Block(
 *   id = "mel_guide_block",
 *   admin_label = @Translation("MEL Guide"),
 *   category = @Translation("MyEventLane")
 * )
 */
final class MelGuideBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly MelGuideVisibility $visibility,
    private readonly MelGuideContext $guideContext,
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
      $container->get('mel_guide.visibility'),
      $container->get('mel_guide.context'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return Cache::mergeContexts(parent::getCacheContexts(), [
      'user.roles',
      'route',
      'mel_guide_device_class',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return Cache::mergeTags(parent::getCacheTags(), [
      'config:mel_guide.settings',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    if (!$this->visibility->shouldAttach()) {
      return [
        '#cache' => [
          'contexts' => $this->getCacheContexts(),
          'tags' => $this->getCacheTags(),
        ],
      ];
    }

    $variables = $this->guideContext->buildVariables();
    if ($variables === NULL) {
      return [
        '#cache' => [
          'contexts' => $this->getCacheContexts(),
          'tags' => $this->getCacheTags(),
        ],
      ];
    }

    return [
      '#theme' => 'mel_guide',
      '#state' => $variables['state'],
      '#message' => $variables['message'],
      '#image_url' => $variables['image_url'],
      '#image_alt' => $variables['image_alt'],
      '#position' => $variables['position'],
      '#attached' => [
        'library' => ['mel_guide/guide'],
        'drupalSettings' => [
          'melGuide' => [
            'appearanceDelay' => $variables['appearance_delay'],
            'maxMessagesPerSession' => $variables['max_messages_per_session'],
            'hideDaysAfterDismiss' => $variables['hide_days_after_dismiss'],
            'debugForceDisplay' => $variables['debug_force_display'],
            'state' => $variables['state'],
          ],
        ],
      ],
      '#cache' => [
        'contexts' => $this->getCacheContexts(),
        'tags' => $this->getCacheTags(),
      ],
    ];
  }

}
