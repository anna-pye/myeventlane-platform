<?php

declare(strict_types=1);

namespace Drupal\myeventlane_page_visuals\EventSubscriber;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Invalidates page visual cache when config changes.
 */
final class PageVisualConfigSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ConfigEvents::SAVE => ['onConfigSave', 100],
      ConfigEvents::DELETE => ['onConfigDelete', 100],
    ];
  }

  /**
   * Invalidates cache when a page visual config is saved.
   */
  public function onConfigSave(ConfigCrudEvent $event): void {
    $config = $event->getConfig();
    if (str_starts_with($config->getName(), 'myeventlane_page_visuals.page_visual.')) {
      Cache::invalidateTags(['config:myeventlane_page_visual_list']);
    }
  }

  /**
   * Invalidates cache when a page visual config is deleted.
   */
  public function onConfigDelete(ConfigCrudEvent $event): void {
    $config = $event->getConfig();
    if (str_starts_with($config->getName(), 'myeventlane_page_visuals.page_visual.')) {
      Cache::invalidateTags(['config:myeventlane_page_visual_list']);
    }
  }

}
