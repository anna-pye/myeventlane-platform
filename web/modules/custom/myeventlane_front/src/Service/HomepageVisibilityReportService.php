<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_core\Service\DiscoverySurfaceAnalyticsService;
use Drupal\myeventlane_domain_events\ProjectionReadModel\EventMetricsReadModel;
use Drupal\myeventlane_event\Service\FeaturedEventReadinessService;
use Drupal\node\NodeInterface;

/**
 * Reports homepage placement surfaces from current merchandising decisions.
 *
 * Read-only — uses HomepageMerchandising resolution, not analytics tracking.
 */
final class HomepageVisibilityReportService {

  use StringTranslationTrait;

  public function __construct(
    private readonly HomepageMerchandising $merchandising,
    private readonly FeaturedEventReadinessService $readinessService,
    TranslationInterface $string_translation,
    private readonly ?EventMetricsReadModel $eventMetricsReadModel = NULL,
    private readonly ?DiscoverySurfaceAnalyticsService $surfaceAnalytics = NULL,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Surfaces where an event currently appears on the homepage.
   *
   * @return list<array{surface_key: string, label: string}>
   */
  public function getSurfacesForEvent(NodeInterface $event): array {
    if ($event->bundle() !== 'event') {
      return [];
    }

    $nid = (int) $event->id();
    $surfaces = [];

    if (in_array($nid, $this->merchandising->getHeroEventIds(), TRUE)) {
      $surfaces[] = $this->surface('hero', (string) $this->t('Hero'));
    }
    if (in_array($nid, $this->merchandising->getFeaturedBlockEventIds(), TRUE)) {
      $surfaces[] = $this->surface('spotlight', (string) $this->t('Community Spotlight'));
    }
    if (in_array($nid, $this->merchandising->getMoreToDiscoverEventIds(), TRUE)) {
      $surfaces[] = $this->surface('more_to_discover', (string) $this->t('Worth checking out'));
    }
    if (in_array($nid, $this->merchandising->getRecommendedEventIds(), TRUE)) {
      $surfaces[] = $this->surface('recommended', (string) $this->t('Recommended'));
    }
    if (in_array($nid, $this->merchandising->getDiscoverEventIds(), TRUE)) {
      $surfaces[] = $this->surface('discover', (string) $this->t('Discover Events'));
    }
    if (in_array($nid, $this->merchandising->getTonightEventIds(), TRUE)) {
      $surfaces[] = $this->surface('tonight', (string) $this->t('Happening Tonight'));
    }
    if (in_array($nid, $this->merchandising->getFreeRsvpEventIds(), TRUE)) {
      $surfaces[] = $this->surface('free', (string) $this->t('Free & RSVP'));
    }
    if (in_array($nid, $this->merchandising->getLatestEventIds(), TRUE)) {
      $surfaces[] = $this->surface('latest', (string) $this->t('Freshly Added'));
    }

    return $surfaces;
  }

  /**
   * Builds a vendor-facing visibility report for boosted events.
   *
   * @param list<NodeInterface> $events
   *
   * @return list<array<string, mixed>>
   */
  public function buildReport(array $events): array {
    $rows = [];
    foreach ($events as $event) {
      if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
        continue;
      }

      $isBoosted = $event->hasField('field_promoted')
        && !(bool) $event->get('field_promoted')->isEmpty()
        && (bool) $event->get('field_promoted')->value;

      $surfaces = $this->getSurfacesForEvent($event);
      $readiness = $this->readinessService->getPresentation($event);
      $engagement = $this->resolveEngagement($event);
      $surface_click_labels = [];
      $homepage_clicks = 0;
      if ($this->surfaceAnalytics !== NULL) {
        $surface_click_labels = array_values(
          $this->surfaceAnalytics->getHomepageClickLabelsBySurface((int) $event->id()),
        );
        $homepage_clicks = $this->surfaceAnalytics->getTotalHomepageClicks((int) $event->id());
      }
      $rows[] = [
        'event_id' => (int) $event->id(),
        'event_title' => $event->label(),
        'boost_active' => $isBoosted,
        'boost_label' => $isBoosted
          ? (string) $this->t('Boost active')
          : (string) $this->t('Not boosted'),
        'surface_labels' => array_map(
          static fn (array $surface): string => (string) ($surface['label'] ?? ''),
          $surfaces,
        ),
        'surface_click_labels' => $surface_click_labels,
        'homepage_clicks' => $homepage_clicks,
        'readiness_ready' => !empty($readiness['ready']),
        'readiness_label' => (string) ($readiness['short_status_label'] ?? $readiness['status_label'] ?? ''),
        'engagement_count' => $engagement['count'],
        'engagement_label' => $engagement['label'],
        'homepage_visible' => $surfaces !== [],
      ];
    }

    return $rows;
  }

  /**
   * Counts boosted events appearing on each homepage surface.
   *
   * @param list<NodeInterface> $events
   *
   * @return list<array{label: string, count: int}>
   */
  public function buildSurfaceSummary(array $events): array {
    $counts = [];
    foreach ($events as $event) {
      if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
        continue;
      }
      foreach ($this->getSurfacesForEvent($event) as $surface) {
        $key = (string) ($surface['surface_key'] ?? '');
        if ($key === '') {
          continue;
        }
        $counts[$key] = ($counts[$key] ?? 0) + 1;
      }
    }

    if ($counts === []) {
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

    $labels = [
      'hero' => (string) $this->t('Hero'),
      'spotlight' => (string) $this->t('Community Spotlight'),
      'more_to_discover' => (string) $this->t('Worth checking out'),
      'recommended' => (string) $this->t('Recommended'),
      'discover' => (string) $this->t('Discover Events'),
      'tonight' => (string) $this->t('Happening Tonight'),
      'free' => (string) $this->t('Free & RSVP'),
      'latest' => (string) $this->t('Freshly Added'),
    ];

    $summary = [];
    foreach ($order as $key) {
      if (empty($counts[$key])) {
        continue;
      }
      $summary[] = [
        'label' => $labels[$key] ?? $key,
        'count' => (int) $counts[$key],
      ];
    }

    return $summary;
  }

  /**
   * Readiness summary for boosted events.
   *
   * @param list<NodeInterface> $events
   *
   * @return array{ready_count: int, total: int, label: string, ready: bool}
   */
  public function buildReadinessSummary(array $events): array {
    $total = 0;
    $ready = 0;
    foreach ($events as $event) {
      if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
        continue;
      }
      $total++;
      if ($this->readinessService->isReady($event)) {
        $ready++;
      }
    }

    return [
      'ready_count' => $ready,
      'total' => $total,
      'ready' => $total > 0 && $ready === $total,
      'label' => $total === 0
        ? ''
        : ($ready === $total
          ? (string) $this->t('Yes')
          : (string) $this->t('@ready of @total ready', [
            '@ready' => $ready,
            '@total' => $total,
          ])),
    ];
  }

  /**
   * @return list<array{label: string, clicks: int}>
   */
  public function buildSurfaceClickSummary(array $events): array {
    if ($this->surfaceAnalytics === NULL) {
      return [];
    }

    $event_ids = [];
    foreach ($events as $event) {
      if ($event instanceof NodeInterface && $event->bundle() === 'event') {
        $event_ids[] = (int) $event->id();
      }
    }

    return $this->surfaceAnalytics->getAggregateHomepageSurfacePerformance($event_ids);
  }

  /**
   * @return array{count: int|null, label: string|null}
   */
  private function resolveEngagement(NodeInterface $event): array {
    if ($this->eventMetricsReadModel === NULL) {
      return [
        'count' => NULL,
        'label' => NULL,
      ];
    }

    $metrics = $this->eventMetricsReadModel->getEventMetrics((int) $event->id());
    $going = (int) ($metrics['tickets_sold'] ?? 0) + (int) ($metrics['rsvp_count'] ?? 0);
    if ($going <= 0) {
      return [
        'count' => 0,
        'label' => NULL,
      ];
    }

    return [
      'count' => $going,
      'label' => (string) $this->formatPlural($going, '1 going', '@count going'),
    ];
  }

  /**
   * @return array{surface_key: string, label: string}
   */
  private function surface(string $key, string $label): array {
    return [
      'surface_key' => $key,
      'label' => $label,
    ];
  }

}
