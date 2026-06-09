<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\views\ViewExecutableFactory;

/**
 * Builds homepage featured events render arrays.
 */
final class FeaturedEventsRenderBuilder {

  private const VIEW_ID = 'front_featured_events';
  private const DISPLAY_ID = 'block_featured';
  private const HERO_DISPLAY_ID = 'block_hero';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ViewExecutableFactory $viewExecutableFactory,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Whether the spotlight featured events display has at least one row.
   */
  public function hasResults(): bool {
    return $this->displayHasResults(self::DISPLAY_ID);
  }

  /**
   * Whether the hero rotator display has at least one row.
   */
  public function hasHeroResults(): bool {
    return $this->displayHasResults(self::HERO_DISPLAY_ID);
  }

  /**
   * Builds the homepage featured discovery rail (teaser_featured spotlight cards).
   */
  public function build(): array {
    return $this->buildDisplay(self::DISPLAY_ID);
  }

  /**
   * Builds the homepage hero featured rotator (editorial_magazine carousel).
   */
  public function buildHeroRotator(): array {
    return $this->buildDisplay(self::HERO_DISPLAY_ID);
  }

  /**
   * Whether a View display returns at least one row (after access checks).
   */
  private function displayHasResults(string $displayId): bool {
    try {
      $storage = $this->entityTypeManager
        ->getStorage('view')
        ->load(self::VIEW_ID);

      if ($storage === NULL) {
        return FALSE;
      }

      $view = $this->viewExecutableFactory->get($storage);
      if (!$view->access($displayId)) {
        return FALSE;
      }

      $view->setDisplay($displayId);
      $view->execute();

      return $view->result !== [];
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * Builds a featured events View display render array.
   */
  private function buildDisplay(string $displayId): array {
    try {
      if (!$this->displayHasResults($displayId)) {
        return $this->emptyBuild();
      }

      $storage = $this->entityTypeManager
        ->getStorage('view')
        ->load(self::VIEW_ID);

      if ($storage === NULL) {
        $this->loggerFactory->get('myeventlane_front')->error('Homepage featured View "@view" was not found.', [
          '@view' => self::VIEW_ID,
        ]);

        return $this->emptyBuild();
      }

      $view = $this->viewExecutableFactory->get($storage);
      if (!$view->access($displayId)) {
        return $this->emptyBuild();
      }

      return $view->buildRenderable($displayId);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_front')->error('Could not build homepage featured events (@display): @message', [
        '@display' => $displayId,
        '@message' => $e->getMessage(),
      ]);

      return $this->emptyBuild();
    }
  }

  /**
   * Returns cacheable empty output for failure or denied access.
   */
  private function emptyBuild(): array {
    return [
      '#markup' => '',
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => ['config:views.view.' . self::VIEW_ID],
      ],
    ];
  }

}
