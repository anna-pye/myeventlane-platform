<?php

declare(strict_types=1);

namespace Drupal\myeventlane_search\Controller;

use Drupal\Component\Datetime\Time;
use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Drupal\search_api\IndexInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lightweight JSON autocomplete for search: events, venues, categories.
 *
 * Same indexes as /search. Events: upcoming only, titles. Venues: unique
 * names from events. Categories: taxonomy terms. No pages, no vendors.
 */
final class SearchAutocompleteController extends ControllerBase {

  /**
   * Fulltext fields for event title search.
   */
  private const EVENT_FULLTEXT_FIELDS = ['title'];

  /**
   * Fulltext fields for venue search (matches SearchController venue query).
   */
  private const VENUE_FULLTEXT_FIELDS = [
    'field_venue_name',
    'field_venue_address',
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
   * Returns JSON suggestions: events, venues, categories.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request; uses query param 'q'.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   [{ "type": "event"|"venue"|"category", "label": "...", "value": "..." }]
   */
  public function autocomplete(Request $request): JsonResponse {
    $q = trim((string) $request->query->get('q', ''));
    if ($q === '') {
      return new JsonResponse([]);
    }

    $out = [];
    $contentIndex = $this->getIndex('mel_content');
    $categoriesIndex = $this->getIndex('mel_categories');
    $now = (int) $this->time->getRequestTime();

    if ($contentIndex) {
      $out = array_merge(
        $out,
        $this->fetchEvents($contentIndex, $q, $now),
        $this->fetchVenues($contentIndex, $q, $now),
      );
    }

    if ($categoriesIndex) {
      $out = array_merge($out, $this->fetchCategories($categoriesIndex, $q));
    }

    return new JsonResponse($out);
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
   * Upcoming events, titles only, max 5.
   *
   * @return array<int, array{type: string, label: string, value: string}>
   *   List of suggestion items with type, label, value.
   */
  private function fetchEvents(IndexInterface $index, string $keys, int $now): array {
    $query = $index->query();
    $query->setFulltextFields(self::EVENT_FULLTEXT_FIELDS);
    $query->keys($keys);
    $query->addCondition('type', 'event');
    $query->addCondition('field_event_start', $now, '>=');
    $query->range(0, 5);
    $rs = $query->execute();

    $items = [];
    foreach ($rs->getResultItems() as $item) {
      $obj = $item->getOriginalObject();
      if (!$obj) {
        continue;
      }
      $entity = $obj->getValue();
      if (!$entity instanceof NodeInterface) {
        continue;
      }
      $title = $entity->getTitle();
      if ($title !== NULL && $title !== '') {
        $items[] = [
          'type' => 'event',
          'label' => $title,
          'value' => $title,
        ];
      }
    }
    return $items;
  }

  /**
   * Unique venue names from upcoming events, max 5.
   *
   * @return array<int, array{type: string, label: string, value: string}>
   *   List of suggestion items with type, label, value.
   */
  private function fetchVenues(IndexInterface $index, string $keys, int $now): array {
    $query = $index->query();
    $query->setFulltextFields(self::VENUE_FULLTEXT_FIELDS);
    $query->keys($keys);
    $query->addCondition('type', 'event');
    $query->addCondition('field_event_start', $now, '>=');
    $query->range(0, 20);
    $rs = $query->execute();

    $seen = [];
    $items = [];
    foreach ($rs->getResultItems() as $item) {
      if (count($items) >= 5) {
        break;
      }
      $obj = $item->getOriginalObject();
      if (!$obj) {
        continue;
      }
      $node = $obj->getValue();
      if (!$node instanceof NodeInterface) {
        continue;
      }
      $name = $node->get('field_venue_name')->value ?? '';
      $name = trim((string) $name);
      if ($name === '' || isset($seen[$name])) {
        continue;
      }
      $seen[$name] = TRUE;
      $items[] = [
        'type' => 'venue',
        'label' => $name,
        'value' => $name,
      ];
    }
    return $items;
  }

  /**
   * Categories (taxonomy terms), max 5.
   *
   * @return array<int, array{type: string, label: string, value: string}>
   *   List of suggestion items with type, label, value.
   */
  private function fetchCategories(IndexInterface $index, string $keys): array {
    $query = $index->query();
    $query->keys($keys);
    $query->range(0, 5);
    $rs = $query->execute();

    $items = [];
    foreach ($rs->getResultItems() as $item) {
      $obj = $item->getOriginalObject();
      if (!$obj) {
        continue;
      }
      $term = $obj->getValue();
      if (!$term instanceof TermInterface) {
        continue;
      }
      $name = $term->getName();
      if ($name !== NULL && $name !== '') {
        $items[] = [
          'type' => 'category',
          'label' => $name,
          'value' => $name,
        ];
      }
    }
    return $items;
  }

}
