<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\myeventlane_analytics\Service\PopularEventsService;
use Drupal\myeventlane_event\Service\BoostedEventQualityGate;
use Drupal\myeventlane_event\Service\PublicEventVisibility;
use Drupal\myeventlane_event_state\Service\EventStateResolver;
use Drupal\views\ViewExecutableFactory;

/**
 * Resolves homepage hero, spotlight, and cross-rail deduplication sets.
 *
 * Hero = top promoted upcoming event (same sort as front_featured_events).
 * Spotlight = all other promoted upcoming events.
 * Lower rails exclude higher-priority sections (cascade).
 */
final class HomepageMerchandising {

  /**
   * View displays that receive homepage exclusion alters.
   *
   * @var array<string, list<string>>
   */
  private const MERCHANDISED_DISPLAYS = [
    'front_featured_events' => [
      'block_hero',
      'block_featured',
    ],
    'upcoming_events' => [
      'homepage_tonight',
      'homepage_hidden_gems',
      'homepage_latest',
    ],
    'mel_home_events' => [
      'embed_discover',
      'under_20',
    ],
    'front_recommended_events' => [
      'block_1',
    ],
  ];

  /**
   * @var list<int>|null
   */
  private ?array $heroEventIds = NULL;

  /**
   * @var list<int>|null
   */
  private ?array $spotlightEventIds = NULL;

  /**
   * @var list<int>|null
   */
  private ?array $discoverEventIds = NULL;

  /**
   * @var list<int>|null
   */
  private ?array $tonightEventIds = NULL;

  /**
   * @var list<int>|null
   */
  private ?array $freeRsvpEventIds = NULL;

  /**
   * @var list<int>|null
   */
  private ?array $latestEventIds = NULL;

  /**
   * @var list<int>|null
   */
  private ?array $featuredBlockEventIds = NULL;

  /**
   * @var list<int>|null
   */
  private ?array $communitySpotlightEventIds = NULL;

  /**
   * @var list<int>|null
   */
  private ?array $moreToDiscoverEventIds = NULL;

  /**
   * @var list<int>|null
   */
  private ?array $hiddenGemEventIds = NULL;

  /**
   * @var list<int>|null
   */
  private ?array $recommendedEventIds = NULL;

  /**
   * @var list<int>|null
   */
  private ?array $communityFavouritesEventIds = NULL;

  /**
   * @var list<int>|null
   */
  private ?array $ineligiblePromotedEventIds = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ViewExecutableFactory $viewExecutableFactory,
    private readonly TimeInterface $time,
    private readonly PathMatcherInterface $pathMatcher,
    private readonly PublicEventVisibility $publicVisibility,
    private readonly BoostedEventQualityGate $qualityGate,
    private readonly PopularEventsService $popularEvents,
  ) {}

  /**
   * Whether merchandising alters apply to this View display on this request.
   */
  public function applies(string $viewId, string $displayId): bool {
    if (!$this->pathMatcher->isFrontPage()) {
      return FALSE;
    }

    return in_array($displayId, self::MERCHANDISED_DISPLAYS[$viewId] ?? [], TRUE);
  }

  /**
   * NIDs to exclude from a homepage View display query.
   *
   * @return list<int>
   */
  public function getExclusionNids(string $viewId, string $displayId): array {
    if (!$this->applies($viewId, $displayId)) {
      return [];
    }

    $hero = $this->getHeroEventIds();
    $spotlight = $this->getSpotlightEventIds();

    return match ($viewId . ':' . $displayId) {
      'front_featured_events:block_featured' => $hero,
      'mel_home_events:embed_discover' => array_values(array_unique([...$hero, ...$spotlight])),
      'upcoming_events:homepage_tonight' => array_values(array_unique([
        ...$hero,
        ...$spotlight,
        ...$this->getDiscoverEventIds(),
      ])),
      'upcoming_events:homepage_hidden_gems' => array_values(array_unique([
        ...$hero,
        ...$spotlight,
        ...$this->getDiscoverEventIds(),
        ...$this->getTonightEventIds(),
      ])),
      'mel_home_events:under_20' => array_values(array_unique([
        ...$hero,
        ...$spotlight,
        ...$this->getDiscoverEventIds(),
        ...$this->getTonightEventIds(),
        ...$this->getHiddenGemEventIds(),
        ...$this->getCommunityFavouritesEventIds(),
      ])),
      'upcoming_events:homepage_latest' => array_values(array_unique([
        ...$hero,
        ...$spotlight,
        ...$this->getDiscoverEventIds(),
        ...$this->getTonightEventIds(),
        ...$this->getHiddenGemEventIds(),
        ...$this->getCommunityFavouritesEventIds(),
        ...$this->getFreeRsvpEventIds(),
      ])),
      'front_recommended_events:block_1' => array_values(array_unique([
        ...$hero,
        ...$spotlight,
        ...$this->getDiscoverEventIds(),
        ...$this->getTonightEventIds(),
        ...$this->getHiddenGemEventIds(),
        ...$this->getCommunityFavouritesEventIds(),
        ...$this->getFreeRsvpEventIds(),
        ...$this->getLatestEventIds(),
      ])),
      default => [],
    };
  }

  /**
   * Promoted upcoming events that fail marketplace media quality gate.
   *
   * @return list<int>
   */
  public function getIneligiblePromotedNids(): array {
    if ($this->ineligiblePromotedEventIds !== NULL) {
      return $this->ineligiblePromotedEventIds;
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $allPromoted = $this->loadAllPromotedUpcomingEventIds();
    if ($allPromoted === []) {
      $this->ineligiblePromotedEventIds = [];
      return $this->ineligiblePromotedEventIds;
    }

    $nodes = $storage->loadMultiple($allPromoted);
    $ineligible = [];
    foreach ($allPromoted as $nid) {
      $node = $nodes[$nid] ?? NULL;
      if ($node === NULL || !$this->qualityGate->isMarketplaceReady($node)) {
        $ineligible[] = $nid;
      }
    }

    $this->ineligiblePromotedEventIds = $ineligible;
    return $this->ineligiblePromotedEventIds;
  }

  /**
   * Whether quality-gate filtering applies to this View display.
   */
  public function appliesQualityGate(string $viewId, string $displayId): bool {
    return $viewId === 'front_featured_events'
      && in_array($displayId, ['block_hero', 'block_featured'], TRUE);
  }

  /**
   * Hero event NIDs (single lead promoted event).
   *
   * @return list<int>
   */
  public function getHeroEventIds(): array {
    if ($this->heroEventIds !== NULL) {
      return $this->heroEventIds;
    }

    $promoted = $this->loadMarketplaceReadyPromotedUpcomingEventIds();
    $this->heroEventIds = $promoted !== [] ? [(int) reset($promoted)] : [];
    return $this->heroEventIds;
  }

  /**
   * Spotlight event NIDs (all boosted except hero).
   *
   * @return list<int>
   */
  public function getSpotlightEventIds(): array {
    if ($this->spotlightEventIds !== NULL) {
      return $this->spotlightEventIds;
    }

    $promoted = $this->loadMarketplaceReadyPromotedUpcomingEventIds();
    $hero = $this->getHeroEventIds();
    if ($hero !== []) {
      $promoted = array_values(array_diff($promoted, $hero));
    }
    $this->spotlightEventIds = $promoted;
    return $this->spotlightEventIds;
  }

  /**
   * @return list<int>
   */
  public function getDiscoverEventIds(): array {
    if ($this->discoverEventIds !== NULL) {
      return $this->discoverEventIds;
    }

    $this->discoverEventIds = $this->executeViewResultNids('mel_home_events', 'embed_discover');
    return $this->discoverEventIds;
  }

  /**
   * @return list<int>
   */
  public function getTonightEventIds(): array {
    if ($this->tonightEventIds !== NULL) {
      return $this->tonightEventIds;
    }

    $this->tonightEventIds = $this->executeViewResultNids('upcoming_events', 'homepage_tonight');
    return $this->tonightEventIds;
  }

  /**
   * Hidden Gems editorial rail (upcoming_events:homepage_hidden_gems).
   *
   * @return list<int>
   */
  public function getHiddenGemEventIds(): array {
    if ($this->hiddenGemEventIds !== NULL) {
      return $this->hiddenGemEventIds;
    }

    $this->hiddenGemEventIds = $this->executeViewResultNids('upcoming_events', 'homepage_hidden_gems');
    return $this->hiddenGemEventIds;
  }

  /**
   * @return list<int>
   */
  public function getFreeRsvpEventIds(): array {
    if ($this->freeRsvpEventIds !== NULL) {
      return $this->freeRsvpEventIds;
    }

    $this->freeRsvpEventIds = $this->executeViewResultNids('mel_home_events', 'under_20');
    return $this->freeRsvpEventIds;
  }

  /**
   * @return list<int>
   */
  public function getLatestEventIds(): array {
    if ($this->latestEventIds !== NULL) {
      return $this->latestEventIds;
    }

    $this->latestEventIds = $this->executeViewResultNids('upcoming_events', 'homepage_latest');
    return $this->latestEventIds;
  }

  /**
   * Featured block roster (front_featured_events:block_featured).
   *
   * Matches Community Spotlight + More to Discover source ordering.
   *
   * @return list<int>
   */
  public function getFeaturedBlockEventIds(): array {
    if ($this->featuredBlockEventIds !== NULL) {
      return $this->featuredBlockEventIds;
    }

    $fromView = $this->executeViewResultNids('front_featured_events', 'block_featured');
    $this->featuredBlockEventIds = $fromView !== [] ? $fromView : $this->getSpotlightEventIds();
    return $this->featuredBlockEventIds;
  }

  /**
   * Community Spotlight editorial events (block_featured rows 0–2).
   *
   * @return list<int>
   */
  public function getCommunitySpotlightEventIds(): array {
    if ($this->communitySpotlightEventIds !== NULL) {
      return $this->communitySpotlightEventIds;
    }

    $featured = $this->getFeaturedBlockEventIds();
    $this->communitySpotlightEventIds = array_values(array_slice($featured, 0, 3));
    return $this->communitySpotlightEventIds;
  }

  /**
   * Overflow carousel events (block_featured rows from index 3).
   *
   * @return list<int>
   */
  public function getMoreToDiscoverEventIds(): array {
    if ($this->moreToDiscoverEventIds !== NULL) {
      return $this->moreToDiscoverEventIds;
    }

    $featured = $this->getFeaturedBlockEventIds();
    $this->moreToDiscoverEventIds = count($featured) >= 3
      ? array_values(array_slice($featured, 3))
      : [];
    return $this->moreToDiscoverEventIds;
  }

  /**
   * NIDs to exclude from Community Favourites (higher-priority homepage rails).
   *
   * CF sits after Hidden Gems and before Free & RSVP on the homepage.
   *
   * @return list<int>
   */
  public function getCommunityFavouritesExclusionNids(): array {
    if (!$this->pathMatcher->isFrontPage()) {
      return [];
    }

    return array_values(array_unique([
      ...$this->getHeroEventIds(),
      ...$this->getSpotlightEventIds(),
      ...$this->getDiscoverEventIds(),
      ...$this->getTonightEventIds(),
      ...$this->getHiddenGemEventIds(),
    ]));
  }

  /**
   * Resolved Community Favourites candidate pool for downstream dedup cascade.
   *
   * Uses the popularity engine pool after higher-priority homepage exclusions.
   * Over-fetches so diversity filtering in the block cannot cause duplicate cards
   * on lower homepage rails.
   *
   * @return list<int>
   */
  public function getCommunityFavouritesEventIds(): array {
    if ($this->communityFavouritesEventIds !== NULL) {
      return $this->communityFavouritesEventIds;
    }

    if (!$this->pathMatcher->isFrontPage()) {
      $this->communityFavouritesEventIds = [];
      return $this->communityFavouritesEventIds;
    }

    $rows = $this->popularEvents->getPopularEventIds(7, 24);
    if ($rows === []) {
      $this->communityFavouritesEventIds = [];
      return $this->communityFavouritesEventIds;
    }

    $exclude = array_flip($this->getCommunityFavouritesExclusionNids());
    $nids = [];
    foreach ($rows as $row) {
      $nid = (int) ($row['nid'] ?? 0);
      if ($nid > 0 && !isset($exclude[$nid])) {
        $nids[] = $nid;
      }
    }

    $this->communityFavouritesEventIds = $nids;
    return $this->communityFavouritesEventIds;
  }

  /**
   * Recommended events rail (front_recommended_events:block_1).
   *
   * @return list<int>
   */
  public function getRecommendedEventIds(): array {
    if ($this->recommendedEventIds !== NULL) {
      return $this->recommendedEventIds;
    }

    $this->recommendedEventIds = $this->executeViewResultNids('front_recommended_events', 'block_1');
    return $this->recommendedEventIds;
  }

  /**
   * Loads promoted upcoming event IDs, marketplace-ready only, in sort order.
   *
   * @return list<int>
   */
  private function loadMarketplaceReadyPromotedUpcomingEventIds(): array {
    $allPromoted = $this->loadAllPromotedUpcomingEventIds();
    if ($allPromoted === []) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $nodes = $storage->loadMultiple($allPromoted);

    return array_values(array_filter(
      $allPromoted,
      fn (int $nid): bool => isset($nodes[$nid]) && $this->qualityGate->isMarketplaceReady($nodes[$nid]),
    ));
  }

  /**
   * Loads promoted upcoming event IDs using front_featured_events sort order.
   *
   * @return list<int>
   */
  private function loadAllPromotedUpcomingEventIds(): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $now = gmdate('Y-m-d\TH:i:s', $this->time->getRequestTime());

    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'event')
      ->condition('status', 1)
      ->condition('field_promoted', 1)
      ->condition('field_event_start', $now, '>=')
      ->sort('field_event_start', 'ASC');

    $excludedStates = [
      EventStateResolver::STATE_DRAFT,
      EventStateResolver::STATE_CANCELLED,
      EventStateResolver::STATE_ARCHIVED,
    ];
    $or = $query->orConditionGroup();
    $or->condition('field_event_state', $excludedStates, 'NOT IN');
    $or->notExists('field_event_state');
    $query->condition($or);

    $patterns = $this->publicVisibility->getInternalTitleSqlLikePatterns();
    foreach ($patterns as $pattern) {
      $query->condition('title', $pattern, 'NOT LIKE');
    }

    $nids = array_map('intval', $query->execute());
    if ($nids === []) {
      return [];
    }

    $nodes = $storage->loadMultiple($nids);
    usort($nids, static function (int $a, int $b) use ($nodes): int {
      $startA = $nodes[$a]->get('field_event_start')->value ?? '';
      $startB = $nodes[$b]->get('field_event_start')->value ?? '';
      return strcmp((string) $startA, (string) $startB);
    });

    return array_values($nids);
  }

  /**
   * Executes a View display and returns rendered row NIDs (respects pager).
   *
   * @return list<int>
   */
  private function executeViewResultNids(string $viewId, string $displayId): array {
    $viewStorage = $this->entityTypeManager->getStorage('view');
    $viewEntity = $viewStorage->load($viewId);
    if ($viewEntity === NULL) {
      return [];
    }

    try {
      $view = $this->viewExecutableFactory->get($viewEntity);
      if (!$view->access($displayId)) {
        return [];
      }
      $view->setDisplay($displayId);
      $view->execute();

      $nids = [];
      foreach ($view->result as $row) {
        if (isset($row->nid)) {
          $nids[] = (int) $row->nid;
        }
      }
      return $nids;
    }
    catch (\Throwable) {
      return [];
    }
  }

}
