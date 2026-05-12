<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Support;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Resolves optional vendor-to-MEL platform support UI.
 */
final class MelSupportResolver implements MelSupportResolverInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RouteProviderInterface $routeProvider,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * {@inheritdoc}
   */
  public function buildCard(NodeInterface $event, string $placement): ?array {
    if (!$this->moduleHandler->moduleExists('myeventlane_donations')) {
      return NULL;
    }

    $config = $this->configFactory->get('myeventlane_donations.settings');
    if (!(bool) ($config->get('enable_platform_donations') ?? TRUE)) {
      return NULL;
    }

    try {
      $this->routeProvider->getRouteByName('myeventlane_donations.platform');
      $url = Url::fromRoute('myeventlane_donations.platform');
      if (!$url->access($this->currentUser)) {
        return NULL;
      }
    }
    catch (RouteNotFoundException $e) {
      $this->logger->error('Event Studio support card cannot resolve platform donation route: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }

    $configured_copy = trim((string) ($config->get('platform_copy') ?? ''));
    $intro = $configured_copy !== ''
      ? $configured_copy
      : (string) $this->t('Help keep MyEventLane affordable for community organisers.');

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'mel-platform-support-card',
          'mel-platform-support-card--' . $this->sanitizePlacement($placement),
        ],
        'role' => 'region',
        'aria-labelledby' => 'mel-platform-support-card-' . $this->sanitizePlacement($placement),
        'data-mel-platform-support-card' => '1',
      ],
      'kicker' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Community contribution'),
        '#attributes' => ['class' => ['mel-platform-support-card__kicker']],
      ],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Help keep community events affordable'),
        '#attributes' => [
          'id' => 'mel-platform-support-card-' . $this->sanitizePlacement($placement),
          'class' => ['mel-platform-support-card__title'],
        ],
      ],
      'intro' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $intro,
        '#attributes' => ['class' => ['mel-platform-support-card__intro']],
      ],
      'assurance' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Optional, transparent, and separate from attendee checkout. Never required to publish.'),
        '#attributes' => ['class' => ['mel-platform-support-card__assurance']],
      ],
      'action' => [
        '#type' => 'link',
        '#title' => $this->t('Contribute to MEL'),
        '#url' => $url,
        '#attributes' => ['class' => ['mel-btn', 'mel-btn--secondary', 'mel-platform-support-card__cta']],
      ],
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => $event->getCacheTags(),
      ],
    ];
  }

  private function sanitizePlacement(string $placement): string {
    $sanitized = strtolower(preg_replace('/[^a-z0-9_-]+/i', '-', $placement) ?? '');
    return trim($sanitized, '-') ?: 'studio';
  }

}
