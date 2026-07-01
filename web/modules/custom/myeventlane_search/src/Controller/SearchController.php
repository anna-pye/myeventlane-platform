<?php

declare(strict_types=1);

namespace Drupal\myeventlane_search\Controller;

use Drupal\Component\Datetime\Time;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_core\Service\EventCategoryUrlService;
use Drupal\myeventlane_core\Utility\UpcomingEventEntityQueryHelper;
use Drupal\myeventlane_event\Service\PublicEventVisibility;
use Drupal\myeventlane_front\Service\FeaturedEventsRenderBuilder;
use Drupal\node\NodeInterface;
use Drupal\search_api\IndexInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Site-wide search results with grouped output.
 *
 * Events are prioritised: direct title/venue/body matches first, then category
 * matches, then organiser matches. Categories and vendors groups appear when
 * they match even if events were found. Venues and pages appear only when no
 * events match. Past events are never returned.
 */
final class SearchController extends ControllerBase {

  private const LIMIT_PER_GROUP = 5;

  private const MATCH_DIRECT = 0;

  private const MATCH_CATEGORY = 1;

  private const MATCH_ORGANISER = 2;

  /**
   * Fulltext field IDs for main content (Events + Pages).
   */
  private const CONTENT_MAIN_FULLTEXT_FIELDS = [
    'title',
    'field_venue_name',
    'field_venue_address',
    'body',
    'rendered_item',
  ];

  /**
   * Fulltext field IDs for venue-only query (Venues group).
   */
  private const CONTENT_VENUE_FULLTEXT_FIELDS = [
    'field_venue_name',
    'field_venue_address',
  ];

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly Time $time,
    private readonly PublicEventVisibility $publicEventVisibility,
    private readonly EventCategoryUrlService $categoryUrlService,
    private readonly FeaturedEventsRenderBuilder $featuredEventsRenderBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('datetime.time'),
      $container->get('myeventlane_event.public_visibility'),
      $container->get('myeventlane_core.event_category_url'),
      $container->get('myeventlane_front.featured_events_render_builder'),
    );
  }

  /**
   * Builds the /search results page.
   */
  public function build(Request $request): array {
    $q = trim((string) $request->query->get('q', ''));
    $groups = [
      'events' => ['title' => $this->t('Events'), 'items' => []],
      'vendors' => ['title' => $this->t('Organisers'), 'items' => []],
      'venues' => ['title' => $this->t('Venues'), 'items' => []],
      'pages' => ['title' => $this->t('Pages / Blog'), 'items' => []],
      'categories' => ['title' => $this->t('Categories'), 'items' => []],
    ];

    if ($q === '') {
      return [
        '#theme' => 'myeventlane_search_results',
        '#query' => '',
        '#groups' => $groups,
        '#empty' => TRUE,
        '#fallback' => [],
      ];
    }

    $contentIndex = $this->getIndex('mel_content');
    $vendorsIndex = $this->getIndex('mel_vendors');
    $categoriesIndex = $this->getIndex('mel_categories');
    $fallback = [];

    if ($contentIndex) {
      [$events, $pages] = $this->runContentQuery($contentIndex, $q);
      $events = $this->mergeRelatedEventMatches(
        $events,
        $categoriesIndex,
        $vendorsIndex,
        $q,
      );
      $groups['events']['items'] = $this->renderEventItems($events);
      if (count($events) >= 1) {
        $groups['pages']['items'] = [];
        $groups['venues']['items'] = [];
      }
      else {
        $groups['pages']['items'] = $pages;
        $groups['venues']['items'] = $this->buildVenueItems($contentIndex, $q);
      }
    }

    if ($vendorsIndex) {
      $groups['vendors']['items'] = $this->runVendorsQuery($vendorsIndex, $q);
    }

    if ($categoriesIndex) {
      $groups['categories']['items'] = $this->runCategoriesQuery($categoriesIndex, $q);
    }

    $total_items = 0;
    foreach ($groups as $group) {
      $total_items += count($group['items']);
    }
    $featured_events_fallback = [];
    $hidden_gems_fallback = [];
    if ($total_items === 0) {
      $fallback = $this->buildEmptyStateFallback();
      $featured_events_fallback = $this->buildFeaturedEventsFallback();
      $hidden_gems_fallback = $this->buildHiddenGemsFallback();
    }

    $build = [
      '#theme' => 'myeventlane_search_results',
      '#query' => $q,
      '#groups' => $groups,
      '#empty' => FALSE,
      '#fallback' => $fallback,
    ];
    if ($featured_events_fallback !== []) {
      // Render child — do not nest View builds inside #fallback theme variables.
      $build['featured_events_fallback'] = $featured_events_fallback;
    }
    if ($hidden_gems_fallback !== []) {
      $build['hidden_gems_fallback'] = $hidden_gems_fallback;
    }

    return $build;
  }

  /**
   * Loads a Search API index by ID.
   */
  private function getIndex(string $id): ?IndexInterface {
    $storage = $this->entityTypeManager()->getStorage('search_api_index');
    $index = $storage->load($id);
    return $index instanceof IndexInterface ? $index : NULL;
  }

  /**
   * Content query on title, venue, body.
   *
   * @return array
   *   [0] = events (list of item arrays), [1] = pages (list of item arrays).
   */
  private function runContentQuery(IndexInterface $index, string $keys): array {
    $now = (int) $this->time->getRequestTime();
    $query = $index->query();
    $query->addTag('myeventlane_event_discovery');
    $query->setFulltextFields(self::CONTENT_MAIN_FULLTEXT_FIELDS);
    $query->keys($keys);
    $or = $query->createConditionGroup('OR');
    $or->addCondition('type', 'event', '<>');
    $or->addCondition('field_event_start', $now, '>=');
    $query->addConditionGroup($or);
    $query->range(0, self::LIMIT_PER_GROUP * 3);
    $rs = $query->execute();
    $events = [];
    $pages = [];
    foreach ($rs->getResultItems() as $item) {
      $obj = $item->getOriginalObject();
      if (!$obj) {
        continue;
      }
      $entity = $obj->getValue();
      if (!$entity instanceof NodeInterface) {
        continue;
      }
      $bundle = $entity->bundle();
      $link = $entity->toLink($entity->getTitle() ?? '', 'canonical');
      $row = ['title' => $entity->getTitle(), 'url' => $link->getUrl()->toString()];
      if ($bundle === 'event') {
        if (!$this->publicEventVisibility->isPubliclyListable($entity)) {
          continue;
        }
        $row['nid'] = (int) $entity->id();
        $row['match_tier'] = self::MATCH_DIRECT;
        $events[] = $row;
      }
      elseif (in_array($bundle, ['article', 'page'], TRUE)) {
        $pages[] = $row;
      }
    }
    return [
      array_slice($events, 0, self::LIMIT_PER_GROUP),
      array_slice($pages, 0, self::LIMIT_PER_GROUP),
    ];
  }

  /**
   * Adds category- and organiser-matched events after direct Search API matches.
   *
   * @param list<array<string, mixed>> $directEvents
   *
   * @return list<array<string, mixed>>
   */
  private function mergeRelatedEventMatches(
    array $directEvents,
    ?IndexInterface $categoriesIndex,
    ?IndexInterface $vendorsIndex,
    string $keys,
  ): array {
    $merged = [];
    foreach ($directEvents as $event) {
      $nid = (int) ($event['nid'] ?? 0);
      if ($nid <= 0) {
        continue;
      }
      $merged[$nid] = $event + ['match_tier' => self::MATCH_DIRECT];
    }

    $exclude = array_keys($merged);
    $remaining = self::LIMIT_PER_GROUP - count($merged);

    if ($remaining > 0 && $categoriesIndex instanceof IndexInterface) {
      $category_tids = $this->loadMatchingCategoryTids($categoriesIndex, $keys);
      $category_events = $this->loadEventsByCategoryTids($category_tids, $exclude, $remaining);
      foreach ($category_events as $event) {
        $nid = (int) ($event['nid'] ?? 0);
        if ($nid <= 0 || isset($merged[$nid])) {
          continue;
        }
        $merged[$nid] = $event + ['match_tier' => self::MATCH_CATEGORY];
        $exclude[] = $nid;
        $remaining--;
        if ($remaining <= 0) {
          break;
        }
      }
    }

    if ($remaining > 0 && $vendorsIndex instanceof IndexInterface) {
      $store_ids = $this->loadMatchingStoreIds($vendorsIndex, $keys);
      $organiser_events = $this->loadEventsByStoreIds($store_ids, $exclude, $remaining);
      foreach ($organiser_events as $event) {
        $nid = (int) ($event['nid'] ?? 0);
        if ($nid <= 0 || isset($merged[$nid])) {
          continue;
        }
        $merged[$nid] = $event + ['match_tier' => self::MATCH_ORGANISER];
        if (--$remaining <= 0) {
          break;
        }
      }
    }

    $events = array_values($merged);
    usort($events, static function (array $a, array $b): int {
      $tier_cmp = ((int) ($a['match_tier'] ?? 99)) <=> ((int) ($b['match_tier'] ?? 99));
      return $tier_cmp !== 0 ? $tier_cmp : ((int) ($a['nid'] ?? 0)) <=> ((int) ($b['nid'] ?? 0));
    });

    return array_slice($events, 0, self::LIMIT_PER_GROUP);
  }

  /**
   * @param list<array<string, mixed>> $events
   *
   * @return list<array<string, mixed>>
   */
  private function renderEventItems(array $events): array {
    if ($events === []) {
      return [];
    }

    $nids = array_values(array_filter(array_map(
      static fn (array $event): int => (int) ($event['nid'] ?? 0),
      $events,
    )));
    $nodes = $nids ? $this->entityTypeManager()->getStorage('node')->loadMultiple($nids) : [];
    $view_builder = $this->entityTypeManager()->getViewBuilder('node');

    foreach ($events as &$item) {
      $nid = (int) ($item['nid'] ?? 0);
      if ($nid && isset($nodes[$nid])) {
        $item['rendered'] = $view_builder->view($nodes[$nid], 'compact_commerce');
      }
      else {
        $item['rendered'] = ['#markup' => ''];
      }
    }
    unset($item);

    return $events;
  }

  /**
   * @return list<int>
   */
  private function loadMatchingCategoryTids(IndexInterface $index, string $keys): array {
    $query = $index->query();
    $query->keys($keys);
    $query->range(0, self::LIMIT_PER_GROUP);
    $rs = $query->execute();

    $tids = [];
    foreach ($rs->getResultItems() as $item) {
      $obj = $item->getOriginalObject();
      if (!$obj) {
        continue;
      }
      $term = $obj->getValue();
      if ($term instanceof TermInterface) {
        $tids[] = (int) $term->id();
      }
    }

    return array_values(array_unique(array_filter($tids)));
  }

  /**
   * @param list<int> $tids
   * @param list<int> $excludeNids
   *
   * @return list<array{nid: int, title: string, url: string}>
   */
  private function loadEventsByCategoryTids(array $tids, array $excludeNids, int $limit): array {
    if ($tids === [] || $limit <= 0) {
      return [];
    }

    $now = (int) $this->time->getRequestTime();
    $query = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'event')
      ->condition('status', 1)
      ->condition('field_category', $tids, 'IN');
    UpcomingEventEntityQueryHelper::addStartOrEndInFutureOrOngoing($query, $now);
    if ($excludeNids !== []) {
      $query->condition('nid', $excludeNids, 'NOT IN');
    }
    $query->sort('field_event_start', 'ASC');
    $query->range(0, $limit);
    $nids = array_map('intval', $query->execute());
    if ($nids === []) {
      return [];
    }

    return $this->buildEventRowsFromNids($nids);
  }

  /**
   * @return list<int>
   */
  private function loadMatchingStoreIds(IndexInterface $index, string $keys): array {
    $query = $index->query();
    $query->keys($keys);
    $query->range(0, self::LIMIT_PER_GROUP);
    $rs = $query->execute();

    $store_ids = [];
    foreach ($rs->getResultItems() as $item) {
      $obj = $item->getOriginalObject();
      if (!$obj) {
        continue;
      }
      $entity = $obj->getValue();
      if ($entity instanceof StoreInterface) {
        $store_ids[] = (int) $entity->id();
      }
    }

    return array_values(array_unique(array_filter($store_ids)));
  }

  /**
   * @param list<int> $storeIds
   * @param list<int> $excludeNids
   *
   * @return list<array{nid: int, title: string, url: string}>
   */
  private function loadEventsByStoreIds(array $storeIds, array $excludeNids, int $limit): array {
    if ($storeIds === [] || $limit <= 0) {
      return [];
    }

    $now = (int) $this->time->getRequestTime();
    $query = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'event')
      ->condition('status', 1)
      ->condition('field_event_store', $storeIds, 'IN');
    UpcomingEventEntityQueryHelper::addStartOrEndInFutureOrOngoing($query, $now);
    if ($excludeNids !== []) {
      $query->condition('nid', $excludeNids, 'NOT IN');
    }
    $query->sort('field_event_start', 'ASC');
    $query->range(0, $limit);
    $nids = array_map('intval', $query->execute());
    if ($nids === []) {
      return [];
    }

    return $this->buildEventRowsFromNids($nids);
  }

  /**
   * @param list<int> $nids
   *
   * @return list<array{nid: int, title: string, url: string}>
   */
  private function buildEventRowsFromNids(array $nids): array {
    $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple($nids);
    $rows = [];
    foreach ($nids as $nid) {
      $node = $nodes[$nid] ?? NULL;
      if (!$node instanceof NodeInterface || !$this->publicEventVisibility->isPubliclyListable($node)) {
        continue;
      }
      $rows[] = [
        'nid' => (int) $node->id(),
        'title' => $node->getTitle(),
        'url' => $node->toUrl('canonical')->toString(),
      ];
    }

    return $rows;
  }

  /**
   * Suggested categories and featured events when search returns zero results.
   *
   * @return array<string, mixed>
   */
  private function buildEmptyStateFallback(): array {
    $categories = [];
    $terms = $this->entityTypeManager()->getStorage('taxonomy_term')->loadByProperties([
      'vid' => 'categories',
    ]);
    foreach ($terms as $term) {
      if (!$term instanceof TermInterface) {
        continue;
      }
      $categories[] = [
        'title' => $term->getName(),
        'url' => $this->categoryUrlService->getCategoryUrlString($term),
      ];
      if (count($categories) >= 8) {
        break;
      }
    }

    return [
      'categories' => $categories,
    ];
  }

  /**
   * Featured events View for zero-result search fallback.
   *
   * @return array<string, mixed>
   */
  private function buildFeaturedEventsFallback(): array {
    return $this->featuredEventsRenderBuilder->build();
  }

  /**
   * Hidden Gems View for zero-result search fallback (after featured, before categories).
   *
   * @return array<string, mixed>
   */
  private function buildHiddenGemsFallback(): array {
    return $this->featuredEventsRenderBuilder->buildHiddenGemsDiscoveryFallback();
  }

  /**
   * Build venue group items (event + venue name/address).
   */
  private function buildVenueItems(IndexInterface $index, string $keys): array {
    $now = (int) $this->time->getRequestTime();
    $query = $index->query();
    $query->addTag('myeventlane_event_discovery');
    $query->setFulltextFields(self::CONTENT_VENUE_FULLTEXT_FIELDS);
    $query->keys($keys);
    $query->addCondition('type', 'event');
    $query->addCondition('field_event_start', $now, '>=');
    $query->range(0, self::LIMIT_PER_GROUP);
    $rs = $query->execute();
    $items = [];
    foreach ($rs->getResultItems() as $item) {
      $obj = $item->getOriginalObject();
      if (!$obj) {
        continue;
      }
      $node = $obj->getValue();
      if (!$node instanceof NodeInterface) {
        continue;
      }
      if (!$this->publicEventVisibility->isPubliclyListable($node)) {
        continue;
      }
      $venueName = $node->get('field_venue_name')->value ?? '';
      $venueAddr = '';
      if ($node->hasField('field_venue_address') && !$node->get('field_venue_address')->isEmpty()) {
        $a = $node->get('field_venue_address')->first();
        if ($a && method_exists($a, 'get')) {
          $parts = [];
          foreach (['address_line1', 'locality', 'administrative_area'] as $k) {
            $v = $a->get($k);
            if ($v !== NULL && $v->getValue() !== NULL && (string) $v->getValue() !== '') {
              $parts[] = (string) $v->getValue();
            }
          }
          $venueAddr = implode(', ', $parts);
        }
      }
      $sub = trim($venueName . ($venueName && $venueAddr ? ' — ' : '') . $venueAddr) ?: $node->getTitle();
      $title = $node->getTitle() . ($sub ? ' (' . $sub . ')' : '');
      $items[] = [
        'title' => $title,
        'url' => $node->toUrl('canonical')->toString(),
      ];
    }
    return $items;
  }

  /**
   * Runs the vendors index query and returns item arrays (title, url).
   *
   * @return list<array{title: string, url: string}>
   */
  private function runVendorsQuery(IndexInterface $index, string $keys): array {
    $query = $index->query();
    $query->keys($keys);
    $query->range(0, self::LIMIT_PER_GROUP);
    $rs = $query->execute();
    $items = [];
    foreach ($rs->getResultItems() as $item) {
      $obj = $item->getOriginalObject();
      if (!$obj) {
        continue;
      }
      $entity = $obj->getValue();
      if ($entity instanceof StoreInterface) {
        $items[] = [
          'title' => $entity->getName(),
          'url' => $entity->toUrl('canonical')->toString(),
        ];
      }
    }
    return $items;
  }

  /**
   * Runs the categories index query and returns item arrays (title, url).
   *
   * @return list<array{title: string, url: string}>
   */
  private function runCategoriesQuery(IndexInterface $index, string $keys): array {
    $query = $index->query();
    $query->keys($keys);
    $query->range(0, self::LIMIT_PER_GROUP);
    $rs = $query->execute();
    $items = [];
    foreach ($rs->getResultItems() as $item) {
      $obj = $item->getOriginalObject();
      if (!$obj) {
        continue;
      }
      $term = $obj->getValue();
      if ($term instanceof TermInterface) {
        $items[] = [
          'title' => $term->getName(),
          'url' => $this->categoryUrlService->getCategoryUrlString($term),
        ];
      }
    }
    return $items;
  }

}
