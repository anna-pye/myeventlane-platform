<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

/**
 * Allowlist and resolution for public discovery click attribution sources.
 */
final class DiscoveryAttributionSources {

  public const SOURCE_HOMEPAGE_FEATURED = 'homepage_featured';
  public const SOURCE_HOMEPAGE_TONIGHT = 'homepage_tonight';
  public const SOURCE_HOMEPAGE_FREE = 'homepage_free';
  public const SOURCE_HOMEPAGE_RECOMMENDED = 'homepage_recommended';
  public const SOURCE_HOMEPAGE_UPCOMING = 'homepage_upcoming';
  public const SOURCE_SEARCH = 'search';
  public const SOURCE_CATEGORY = 'category';
  public const SOURCE_RELATED_EVENTS = 'related_events';
  public const SOURCE_VENDOR_PROFILE = 'vendor_profile';

  public const SOURCE_HOMEPAGE_DISCOVER = 'homepage_discover';
  public const SOURCE_HOMEPAGE_HIDDEN_GEMS = 'homepage_hidden_gems';
  public const SOURCE_BROWSE_HIDDEN_GEMS = 'browse_hidden_gems';

  /**
   * @var list<string>
   */
  private const ALLOWED = [
    self::SOURCE_HOMEPAGE_FEATURED,
    self::SOURCE_HOMEPAGE_DISCOVER,
    self::SOURCE_HOMEPAGE_TONIGHT,
    self::SOURCE_HOMEPAGE_FREE,
    self::SOURCE_HOMEPAGE_RECOMMENDED,
    self::SOURCE_HOMEPAGE_UPCOMING,
    self::SOURCE_HOMEPAGE_HIDDEN_GEMS,
    self::SOURCE_BROWSE_HIDDEN_GEMS,
    self::SOURCE_SEARCH,
    self::SOURCE_CATEGORY,
    self::SOURCE_RELATED_EVENTS,
    self::SOURCE_VENDOR_PROFILE,
  ];

  /**
   * @var array<string, string>
   */
  private const VIEW_DISPLAY_MAP = [
    'front_featured_events:block_featured' => self::SOURCE_HOMEPAGE_FEATURED,
    'front_featured_events:block_hero' => self::SOURCE_HOMEPAGE_FEATURED,
    'mel_home_events:embed_discover' => self::SOURCE_HOMEPAGE_DISCOVER,
    'upcoming_events:homepage_tonight' => self::SOURCE_HOMEPAGE_TONIGHT,
    'mel_home_events:under_20' => self::SOURCE_HOMEPAGE_FREE,
    'front_recommended_events:block_1' => self::SOURCE_HOMEPAGE_RECOMMENDED,
    'upcoming_events:homepage_latest' => self::SOURCE_HOMEPAGE_UPCOMING,
    'upcoming_events:homepage_hidden_gems' => self::SOURCE_HOMEPAGE_HIDDEN_GEMS,
    'upcoming_events:page_hidden_gems' => self::SOURCE_BROWSE_HIDDEN_GEMS,
    'upcoming_events:page_category' => self::SOURCE_CATEGORY,
  ];

  /**
   * @var array<string, string>
   */
  private const ROUTE_MAP = [
    'mel_search.view' => self::SOURCE_SEARCH,
    'entity.myeventlane_vendor.canonical' => self::SOURCE_VENDOR_PROFILE,
  ];

  /**
   * Whether a source value is allowed for storage.
   */
  public function isAllowed(string $source): bool {
    return in_array($source, self::ALLOWED, TRUE);
  }

  /**
   * Resolves attribution source for a Views display, if mapped.
   */
  public function forViewDisplay(string $view_id, string $display_id): ?string {
    if ($view_id === '' || $display_id === '') {
      return NULL;
    }

    return self::VIEW_DISPLAY_MAP[$view_id . ':' . $display_id] ?? NULL;
  }

  /**
   * Resolves attribution source for a route, if mapped.
   */
  public function forRoute(?string $route_name): ?string {
    if ($route_name === NULL || $route_name === '') {
      return NULL;
    }

    return self::ROUTE_MAP[$route_name] ?? NULL;
  }

}
