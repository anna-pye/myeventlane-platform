<?php

declare(strict_types=1);

namespace Drupal\mel_guide\Cache\Context;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextInterface;
use Drupal\mel_guide\Service\MelGuideVisibility;

/**
 * Cache context for MEL Guide mobile vs desktop device gating.
 *
 * Cache context ID: 'mel_guide_device_class'.
 */
final class MelGuideDeviceCacheContext implements CacheContextInterface {

  public function __construct(
    private readonly MelGuideVisibility $visibility,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getLabel(): string {
    return (string) t('MEL Guide device class');
  }

  /**
   * {@inheritdoc}
   */
  public function getContext(): string {
    return $this->visibility->getDeviceClass();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata(): CacheableMetadata {
    return new CacheableMetadata();
  }

}
