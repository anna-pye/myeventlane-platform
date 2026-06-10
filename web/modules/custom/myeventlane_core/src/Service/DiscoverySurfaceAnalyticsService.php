<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Maps discovery attribution sources to homepage surfaces for click reporting.
 *
 * Uses stored event_click rows only — no impressions or synthetic metrics.
 */
final class DiscoverySurfaceAnalyticsService {

  use StringTranslationTrait;

  /**
   * @var array<string, string>
   */
  private const SOURCE_SURFACE_MAP = [
    DiscoveryAttributionSources::SOURCE_HOMEPAGE_FEATURED => 'spotlight',
    DiscoveryAttributionSources::SOURCE_HOMEPAGE_DISCOVER => 'discover',
    DiscoveryAttributionSources::SOURCE_HOMEPAGE_TONIGHT => 'tonight',
    DiscoveryAttributionSources::SOURCE_HOMEPAGE_FREE => 'free',
    DiscoveryAttributionSources::SOURCE_HOMEPAGE_RECOMMENDED => 'recommended',
    DiscoveryAttributionSources::SOURCE_HOMEPAGE_UPCOMING => 'latest',
  ];

  /**
   * @var array<string, string>
   */
  private const SURFACE_LABELS = [
    'hero' => 'Hero',
    'spotlight' => 'Community Spotlight',
    'more_to_discover' => 'Worth checking out',
    'recommended' => 'Recommended',
    'discover' => 'Discover Events',
    'tonight' => 'Happening Tonight',
    'free' => 'Free & RSVP',
    'latest' => 'Freshly Added',
  ];

  public function __construct(
    private readonly AnalyticsService $analytics,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Homepage-surface click counts for one event (from stored attribution).
   *
   * @return array<string, int>
   *   Map of surface key => click count.
   */
  public function getHomepageClickCountsBySurface(int $event_id): array {
    if ($event_id <= 0) {
      return [];
    }

    $by_source = $this->analytics->countEventClicksGroupedBySource('node', $event_id);
    if ($by_source === []) {
      return [];
    }

    $by_surface = [];
    foreach ($by_source as $source => $count) {
      $surface = self::SOURCE_SURFACE_MAP[$source] ?? NULL;
      if ($surface === NULL) {
        continue;
      }
      $by_surface[$surface] = ($by_surface[$surface] ?? 0) + $count;
    }

    return $by_surface;
  }

  /**
   * Total homepage-attributed clicks for one event.
   */
  public function getTotalHomepageClicks(int $event_id): int {
    return array_sum($this->getHomepageClickCountsBySurface($event_id));
  }

  /**
   * Builds organiser-facing click labels keyed by homepage surface.
   *
   * @return array<string, string>
   *   Map of surface key => label including click count.
   */
  public function getHomepageClickLabelsBySurface(int $event_id): array {
    $labels = [];
    foreach ($this->getHomepageClickCountsBySurface($event_id) as $surface => $count) {
      if ($count <= 0) {
        continue;
      }
      $surface_label = $this->surfaceLabel($surface);
      $labels[$surface] = (string) $this->formatPlural(
        $count,
        '@surface — 1 click',
        '@surface — @count clicks',
        [
          '@surface' => $surface_label,
          '@count' => $count,
        ],
      );
    }

    return $labels;
  }

  /**
   * Aggregate homepage click totals grouped by surface across many events.
   *
   * @param list<int> $event_ids
   *
   * @return list<array{label: string, clicks: int}>
   */
  public function getAggregateHomepageSurfacePerformance(array $event_ids): array {
    $totals = [];
    foreach ($event_ids as $event_id) {
      $event_id = (int) $event_id;
      if ($event_id <= 0) {
        continue;
      }
      foreach ($this->getHomepageClickCountsBySurface($event_id) as $surface => $count) {
        $totals[$surface] = ($totals[$surface] ?? 0) + $count;
      }
    }

    if ($totals === []) {
      return [];
    }

    $order = [
      'hero',
      'spotlight',
      'more_to_discover',
      'recommended',
      'discover',
      'tonight',
      'free',
      'latest',
    ];

    $rows = [];
    foreach ($order as $surface) {
      if (empty($totals[$surface])) {
        continue;
      }
      $rows[] = [
        'label' => $this->surfaceLabel($surface),
        'clicks' => (int) $totals[$surface],
      ];
    }

    return $rows;
  }

  private function surfaceLabel(string $surface): string {
    $key = self::SURFACE_LABELS[$surface] ?? $surface;
    return (string) $this->t($key);
  }

}
