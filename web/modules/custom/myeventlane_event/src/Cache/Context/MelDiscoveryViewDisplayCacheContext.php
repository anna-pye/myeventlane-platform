<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Cache\Context;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Cache context for the View display currently rendering discovery cards.
 *
 * Cache context ID: 'mel_discovery_view_display'.
 */
final class MelDiscoveryViewDisplayCacheContext implements CacheContextInterface {

  public function __construct(
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getLabel(): string {
    return (string) t('Discovery view display');
  }

  /**
   * {@inheritdoc}
   */
  public function getContext(): string {
    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      return 'none';
    }
    $view_id = $request->attributes->get('mel_active_view_id', '');
    $display_id = $request->attributes->get('mel_active_view_display', '');
    if (!is_string($view_id)) {
      $view_id = '';
    }
    if (!is_string($display_id)) {
      $display_id = '';
    }
    if ($view_id === '' && $display_id === '') {
      return 'none';
    }
    return $view_id . ':' . $display_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata(): CacheableMetadata {
    return new CacheableMetadata();
  }

}
