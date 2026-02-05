<?php

declare(strict_types=1);

namespace Drupal\myeventlane_page_visuals\Service;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\media\MediaInterface;
use Drupal\myeventlane_page_visuals\Entity\PageVisualInterface;

/**
 * Resolves Page Visual for the current route.
 *
 * Returns a DTO-style array with image_url, alt, enabled. Media entities
 * are loaded by UUID in the service; Twig receives only URLs and strings.
 */
final class PageVisualResolver {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity repository (for loadEntityByUuid).
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  private EntityRepositoryInterface $entityRepository;

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  private FileUrlGeneratorInterface $fileUrlGenerator;

  /**
   * Constructs the resolver.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    EntityRepositoryInterface $entityRepository,
    FileUrlGeneratorInterface $fileUrlGenerator,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->entityRepository = $entityRepository;
    $this->fileUrlGenerator = $fileUrlGenerator;
  }

  /**
   * Resolves the Page Visual for the current route.
   *
   * Resolution order:
   * 1. Enabled visual matching current route_name
   * 2. Section fallback (optional, not implemented).
   * 3. Enabled global default visual (route_name = 'default')
   * 4. null if none found.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route match.
   *
   * @return array|null
   *   DTO: enabled, image_url, alt, image_url_mobile, hide_on_mobile, _cache.
   */
  public function resolveForRoute(RouteMatchInterface $routeMatch): ?array {
    $route_name = $routeMatch->getRouteName();
    if ($route_name === NULL || $route_name === '') {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('myeventlane_page_visual');
    $visuals = $storage->loadMultiple();

    // 1. Exact route match.
    foreach ($visuals as $visual) {
      if (!$visual instanceof PageVisualInterface || !$visual->isEnabled()) {
        continue;
      }
      if ($visual->getRouteName() === $route_name) {
        return $this->buildResult($visual);
      }
    }

    // 2. Section fallback (optional, not implemented).
    // 3. Global default (route_name = 'default').
    foreach ($visuals as $visual) {
      if (!$visual instanceof PageVisualInterface || !$visual->isEnabled()) {
        continue;
      }
      if ($visual->getRouteName() === 'default') {
        return $this->buildResult($visual);
      }
    }

    return NULL;
  }

  /**
   * Builds the result array from a Page Visual entity.
   *
   * Loads desktop and mobile Media entities by UUID. Fails closed: if desktop
   * Media is configured but cannot be loaded, image_url is null (no broken
   * images). Mobile is optional; if configured but unloadable, image_url_mobile
   * is null.
   *
   * @param \Drupal\myeventlane_page_visuals\Entity\PageVisualInterface $visual
   *   The page visual entity.
   *
   * @return array{enabled: bool, image_url: string|null, alt: string, image_url_mobile: string|null, hide_on_mobile: bool, _cache: array}
   *   DTO array with _cache for preprocess to merge.
   */
  private function buildResult(PageVisualInterface $visual): array {
    $media_uuid_desktop = $visual->getMediaUuidDesktop();
    $media_uuid_mobile = $visual->getMediaUuidMobile();
    $image_url = NULL;
    $image_url_mobile = NULL;
    $cache_tags = [
      'config:myeventlane_page_visual_list',
      'config:myeventlane_page_visual.' . $visual->id(),
    ];

    if ($media_uuid_desktop !== NULL && $media_uuid_desktop !== '') {
      $media = $this->entityRepository->loadEntityByUuid('media', $media_uuid_desktop);
      if ($media instanceof MediaInterface) {
        $image_url = $this->getImageUrlFromMedia($media);
        $cache_tags = array_merge($cache_tags, $media->getCacheTags());
      }
    }

    if ($media_uuid_mobile !== NULL && $media_uuid_mobile !== '') {
      $media_mobile = $this->entityRepository->loadEntityByUuid('media', $media_uuid_mobile);
      if ($media_mobile instanceof MediaInterface) {
        $image_url_mobile = $this->getImageUrlFromMedia($media_mobile);
        $cache_tags = array_merge($cache_tags, $media_mobile->getCacheTags());
      }
    }

    return [
      'enabled' => $visual->isEnabled(),
      'image_url' => $image_url,
      'alt' => $visual->getAltText(),
      'image_url_mobile' => $image_url_mobile,
      'hide_on_mobile' => $visual->isHideOnMobile(),
      '_cache' => [
        'contexts' => ['route'],
        'tags' => $cache_tags,
      ],
    ];
  }

  /**
   * Gets the image URL from a Media entity.
   *
   * Uses the field_media_image field (standard image media type).
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   *
   * @return string|null
   *   Absolute image URL, or null if no image found.
   */
  private function getImageUrlFromMedia(MediaInterface $media): ?string {
    if (!$media->hasField('field_media_image') || $media->get('field_media_image')->isEmpty()) {
      return NULL;
    }

    $item = $media->get('field_media_image')->first();
    if ($item === NULL) {
      return NULL;
    }

    $file = $item->entity;
    if ($file === NULL) {
      return NULL;
    }

    return $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
  }

  /**
   * Gets cacheable metadata when no visual is found.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route match.
   *
   * @return \Drupal\Core\Cache\CacheableMetadata
   *   Cacheable metadata.
   */
  public function getCacheableMetadata(RouteMatchInterface $routeMatch): CacheableMetadata {
    $metadata = new CacheableMetadata();
    $metadata->addCacheContexts(['route']);
    $metadata->addCacheTags(['config:myeventlane_page_visual_list']);
    return $metadata;
  }

}
