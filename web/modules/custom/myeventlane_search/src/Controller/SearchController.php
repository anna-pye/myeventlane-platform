<?php

declare(strict_types=1);

namespace Drupal\myeventlane_search\Controller;

use Drupal\Component\Datetime\Time;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Drupal\search_api\IndexInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Site-wide search results with grouped output.
 *
 * Events are exclusive when any match: only Events group is shown. Non-event
 * groups (Vendors, Venues, Pages, Categories) are shown only when Events
 * has zero results. Past events are never returned.
 */
final class SearchController extends ControllerBase {

  private const LIMIT_PER_GROUP = 5;

  /**
   * Fulltext field IDs for main content (Events + Pages).
   *
   * Order reflects relevance priority: title, venue, body, rendered_item.
   * rendered_item includes category term labels (via entity view, e.g. teaser).
   * The database backend does not support per-field weighting; order is for
   * clarity. Includes venue fields so venue searches (e.g. "the chippo")
   * return events.
   */
  private const CONTENT_MAIN_FULLTEXT_FIELDS = [
    'title',
    'field_venue_name',
    'field_venue_address',
    'body',
    'rendered_item',
  ];

  /**
   * Constructs the controller.
   *
   * @param \Drupal\Component\Datetime\Time $time
   *   The time service for "upcoming" filters.
   */
  public function __construct(
    private readonly Time $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('datetime.time'));
  }

  /**
   * Fulltext field IDs for venue-only query (Venues group).
   *
   * Same IDs as in mel_content; only these are searched for the Venues group.
   */
  private const CONTENT_VENUE_FULLTEXT_FIELDS = [
    'field_venue_name',
    'field_venue_address',
  ];

  /**
   * Builds the /search results page.
   */
  public function build(Request $request): array {
    $q = trim((string) $request->query->get('q', ''));
    $groups = [
      'events' => ['title' => $this->t('Events'), 'items' => []],
      'vendors' => ['title' => $this->t('Vendors'), 'items' => []],
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
      ];
    }

    $contentIndex = $this->getIndex('mel_content');
    $vendorsIndex = $this->getIndex('mel_vendors');
    $categoriesIndex = $this->getIndex('mel_categories');

    if ($contentIndex) {
      [$events, $pages] = $this->runContentQuery($contentIndex, $q);
      $groups['events']['items'] = $events;
      if (count($events) >= 1) {
        // Enrich event items with rendered teaser (matches /events cards).
        $nids = array_filter(array_map('intval', array_column($events, 'nid')));
        $nodes = $nids ? $this->entityTypeManager()->getStorage('node')->loadMultiple($nids) : [];
        $view_builder = $this->entityTypeManager()->getViewBuilder('node');
        foreach ($groups['events']['items'] as &$item) {
          $nid = (int) ($item['nid'] ?? 0);
          if ($nid && isset($nodes[$nid])) {
            $item['rendered'] = $view_builder->view($nodes[$nid], 'teaser');
          }
          else {
            $item['rendered'] = ['#markup' => ''];
          }
        }
        unset($item);
        // Only show Events; suppress non-event groups.
        $groups['pages']['items'] = [];
        $groups['venues']['items'] = [];
      }
      else {
        $groups['pages']['items'] = $pages;
        $groups['venues']['items'] = $this->buildVenueItems($contentIndex, $q);
      }
    }

    if ($vendorsIndex && count($groups['events']['items']) < 1) {
      $groups['vendors']['items'] = $this->runVendorsQuery($vendorsIndex, $q);
    }

    if ($categoriesIndex && count($groups['events']['items']) < 1) {
      $groups['categories']['items'] = $this->runCategoriesQuery($categoriesIndex, $q);
    }

    return [
      '#theme' => 'myeventlane_search_results',
      '#query' => $q,
      '#groups' => $groups,
      '#empty' => FALSE,
    ];
  }

  /**
   * Loads a Search API index by ID.
   *
   * @param string $id
   *   The index ID.
   *
   * @return \Drupal\search_api\IndexInterface|null
   *   The index or NULL if not found.
   */
  private function getIndex(string $id): ?IndexInterface {
    $storage = $this->entityTypeManager()->getStorage('search_api_index');
    $index = $storage->load($id);
    return $index instanceof IndexInterface ? $index : NULL;
  }

  /**
   * Content query on title, venue, body.
   *
   * Finds events and pages; venue searches (e.g. "the chippo") return events.
   * Only upcoming events: field_event_start >= now. Pages and articles are
   * included via (type != 'event') in the OR.
   *
   * @return array
   *   [0] = events (list of item arrays), [1] = pages (list of item arrays).
   */
  private function runContentQuery(IndexInterface $index, string $keys): array {
    $now = (int) $this->time->getRequestTime();
    $query = $index->query();
    $query->setFulltextFields(self::CONTENT_MAIN_FULLTEXT_FIELDS);
    $query->keys($keys);
    // Include: non-events (page, article) OR events with start >= now.
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
        $row['nid'] = (int) $entity->id();
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
   * Build venue group items (event + venue name/address).
   *
   * Only upcoming events: field_event_start >= now.
   */
  private function buildVenueItems(IndexInterface $index, string $keys): array {
    $now = (int) $this->time->getRequestTime();
    $query = $index->query();
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
   * @return array
   *   List of associative arrays with 'title' and 'url' keys.
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
   * @return array
   *   List of associative arrays with 'title' and 'url' keys.
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
          'url' => $term->toUrl('canonical')->toString(),
        ];
      }
    }
    return $items;
  }

}
