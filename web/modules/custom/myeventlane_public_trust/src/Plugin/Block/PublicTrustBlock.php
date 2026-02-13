<?php

declare(strict_types=1);

namespace Drupal\myeventlane_public_trust\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\myeventlane_public_trust\Service\TrustSignalCalculator;
use Drupal\myeventlane_public_trust\Service\TrustSignalFormatter;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the public trust signals block.
 *
 * Renders aggregate trust statements for anonymous and authenticated users.
 * Hidden on admin routes. Reads from cache only — never triggers live queries.
 *
 * @Block(
 *   id = "myeventlane_public_trust_block",
 *   admin_label = @Translation("Public trust signals"),
 *   category = @Translation("MyEventLane")
 * )
 */
final class PublicTrustBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly TrustSignalCalculator $calculator,
    private readonly TrustSignalFormatter $formatter,
    private readonly AdminContext $adminContext,
    private readonly RouteMatchInterface $routeMatch,
    private readonly LoggerInterface $logger,
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
      $container->get('myeventlane_public_trust.trust_signal_calculator'),
      $container->get('myeventlane_public_trust.trust_signal_formatter'),
      $container->get('router.admin_context'),
      $container->get('current_route_match'),
      $container->get('logger.channel.myeventlane_public_trust'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    // Do not render on admin routes.
    $route = $this->routeMatch->getRouteObject();
    if ($route !== NULL && $this->adminContext->isAdminRoute($route)) {
      return [];
    }

    try {
      $metrics = $this->calculator->getCachedMetrics();
    }
    catch (\Throwable $e) {
      $this->logger->error('Block: failed to retrieve trust signals: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }

    if ($metrics === NULL || $metrics === []) {
      return [];
    }

    try {
      $signals = $this->formatter->format($metrics);
    }
    catch (\Throwable $e) {
      $this->logger->error('Block: failed to format trust signals: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }

    if (empty($signals)) {
      return [];
    }

    return [
      '#theme' => 'public_trust',
      '#signals' => $signals,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return 86400;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return Cache::mergeTags(parent::getCacheTags(), ['myeventlane_public_trust']);
  }

}
