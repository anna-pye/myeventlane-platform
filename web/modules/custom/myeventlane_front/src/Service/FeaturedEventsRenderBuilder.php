<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\views\ViewExecutableFactory;

/**
 * Builds the canonical homepage featured events render array.
 */
final class FeaturedEventsRenderBuilder {

  private const VIEW_ID = 'front_featured_events';
  private const DISPLAY_ID = 'block_featured';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ViewExecutableFactory $viewExecutableFactory,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Builds the existing featured events View display.
   *
   * @return array
   *   A render array for front_featured_events:block_featured, or an empty
   *   cacheable render array if the View cannot be built.
   */
  public function build(): array {
    try {
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
      if (!$view->access(self::DISPLAY_ID)) {
        return $this->emptyBuild();
      }

      return $view->buildRenderable(self::DISPLAY_ID);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_front')->error('Could not build homepage featured events: @message', [
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
