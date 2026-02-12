<?php

declare(strict_types=1);

namespace Drupal\myeventlane_public_trust\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_public_trust\Service\TrustSignalCalculator;
use Drupal\myeventlane_public_trust\Service\TrustSignalFormatter;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the public trust signals page at /trust.
 *
 * Reads from cache only — no live queries during page render.
 * If cache is empty or an error occurs, renders nothing gracefully.
 */
final class PublicTrustController extends ControllerBase {

  public function __construct(
    private readonly TrustSignalCalculator $calculator,
    private readonly TrustSignalFormatter $formatter,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_public_trust.trust_signal_calculator'),
      $container->get('myeventlane_public_trust.trust_signal_formatter'),
      $container->get('logger.channel.myeventlane_public_trust'),
    );
  }

  /**
   * Builds the trust signals page render array.
   *
   * @return array
   *   Render array using the 'public_trust' theme hook, or empty markup
   *   if no signals are available.
   */
  public function page(): array {
    try {
      $metrics = $this->calculator->getCachedMetrics();
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to retrieve trust signal cache: @message', [
        '@message' => $e->getMessage(),
      ]);
      $metrics = NULL;
    }

    if ($metrics === NULL || $metrics === []) {
      return [
        '#markup' => '',
        '#cache' => [
          'max-age' => 86400,
        ],
      ];
    }

    try {
      $signals = $this->formatter->format($metrics);
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to format trust signals: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [
        '#markup' => '',
        '#cache' => [
          'max-age' => 86400,
        ],
      ];
    }

    if (empty($signals)) {
      return [
        '#markup' => '',
        '#cache' => [
          'max-age' => 86400,
        ],
      ];
    }

    return [
      '#theme' => 'public_trust',
      '#signals' => $signals,
      '#cache' => [
        'max-age' => 86400,
        'tags' => ['myeventlane_public_trust'],
      ],
    ];
  }

}
