<?php

declare(strict_types=1);

namespace Drupal\myeventlane_page_visuals\Service;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\file\FileInterface;
use Drupal\image\ImageStyleInterface;
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
   * Desktop discovery hero derivative (16:9, max 1600×900).
   */
  private const STYLE_HERO_DESKTOP = 'mel_event_hero_featured';

  /**
   * Mobile discovery hero derivative (16:9, 800×450).
   */
  private const STYLE_HERO_MOBILE = 'mel_event_card_featured';

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
    $route_candidates = $this->getRouteMatchCandidates($route_name);

    // 1. Exact route match (including configured aliases).
    foreach ($visuals as $visual) {
      if (!$visual instanceof PageVisualInterface || !$visual->isEnabled()) {
        continue;
      }
      if (in_array($visual->getRouteName(), $route_candidates, TRUE)) {
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
   * Expands a runtime route to equivalent Page Visual route_name values.
   *
   * @return string[]
   *   Route names to match against Page Visual config entities.
   */
  private function getRouteMatchCandidates(string $route_name): array {
    $aliases = [
      'view.frontpage.page_1' => ['system.front_page'],
      'system.front_page' => ['view.frontpage.page_1'],
      'myeventlane_vendor.organisers' => ['myeventlane_vendor.public_list'],
      'myeventlane_vendor.public_list' => ['myeventlane_vendor.organisers'],
    ];

    return array_values(array_unique(array_merge([$route_name], $aliases[$route_name] ?? [])));
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
    $desktop_media = NULL;

    if ($media_uuid_desktop !== NULL && $media_uuid_desktop !== '') {
      $media = $this->entityRepository->loadEntityByUuid('media', $media_uuid_desktop);
      if ($media instanceof MediaInterface) {
        $desktop_media = $media;
        $styled = $this->getStyledImageUrlFromMedia($media, self::STYLE_HERO_DESKTOP);
        if ($styled !== NULL) {
          $image_url = $styled['url'];
          $cache_tags = array_merge($cache_tags, $styled['cache_tags']);
        }
      }
    }

    if ($media_uuid_mobile !== NULL && $media_uuid_mobile !== '') {
      $media_mobile = $this->entityRepository->loadEntityByUuid('media', $media_uuid_mobile);
      if ($media_mobile instanceof MediaInterface) {
        $styled = $this->getStyledImageUrlFromMedia($media_mobile, self::STYLE_HERO_MOBILE);
        if ($styled !== NULL) {
          $image_url_mobile = $styled['url'];
          $cache_tags = array_merge($cache_tags, $styled['cache_tags']);
        }
      }
    }
    elseif ($desktop_media instanceof MediaInterface) {
      // Same artwork: smaller derivative for mobile <picture>.
      $styled = $this->getStyledImageUrlFromMedia($desktop_media, self::STYLE_HERO_MOBILE);
      if ($styled !== NULL) {
        $image_url_mobile = $styled['url'];
        $cache_tags = array_merge($cache_tags, $styled['cache_tags']);
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
   * Builds a styled image URL from a Media entity.
   *
   * Uses existing MEL image styles (no raw file URLs). Falls back to the
   * original file URL only when the requested style is missing.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   * @param string $style_id
   *   Image style machine name.
   *
   * @return array{url: string, cache_tags: string[]}|null
   *   Styled URL and cache tags, or null if no image file.
   */
  private function getStyledImageUrlFromMedia(MediaInterface $media, string $style_id): ?array {
    $file = $this->getMediaImageFile($media);
    if (!$file instanceof FileInterface) {
      return NULL;
    }

    $uri = $file->getFileUri();
    $cache_tags = array_merge($media->getCacheTags(), $file->getCacheTags());

    $style = $this->entityTypeManager->getStorage('image_style')->load($style_id);
    if (!$style instanceof ImageStyleInterface) {
      return [
        'url' => $this->fileUrlGenerator->generateAbsoluteString($uri),
        'cache_tags' => $cache_tags,
      ];
    }

    return [
      'url' => $style->buildUrl($uri),
      'cache_tags' => array_merge($cache_tags, $style->getCacheTags()),
    ];
  }

  /**
   * Loads the image file from standard image media.
   */
  private function getMediaImageFile(MediaInterface $media): ?FileInterface {
    if (!$media->hasField('field_media_image') || $media->get('field_media_image')->isEmpty()) {
      return NULL;
    }

    $item = $media->get('field_media_image')->first();
    if ($item === NULL) {
      return NULL;
    }

    $file = $item->entity;
    return $file instanceof FileInterface ? $file : NULL;
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
