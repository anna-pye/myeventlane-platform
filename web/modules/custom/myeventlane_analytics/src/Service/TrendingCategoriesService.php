<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Schema;
use Drupal\Core\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Computes trending categories and category-scoped trending events.
 *
 * Locked scoring:
 * - Per event: (tickets_sold * 3) + (rsvps * 1)
 * - Category score: SUM(event_score) across events tagged with the category.
 * - Category going: SUM(tickets_sold + rsvps) across events tagged with category.
 *
 * Hard rules:
 * - Published events only (inherited from PopularEventsService).
 * - Boost excluded (inherited from PopularEventsService).
 * - No N+1 DB loops for counts; use set-based queries.
 * - Past events are not hidden; categories/events may be deprioritised at sort.
 */
final class TrendingCategoriesService {

  private const DEFAULT_DAYS = 7;
  private const DEFAULT_CATEGORY_LIMIT = 8;
  private const DEFAULT_EVENT_SCAN_LIMIT = 60;
  private const DEFAULT_EVENTS_PER_CATEGORY = 12;

  /**
   * Canonical Event -> Category field (confirmed in install config).
   *
   * @see web/modules/custom/myeventlane_schema/config/install/field.field.node.event.field_category.yml
   */
  private const EVENT_CATEGORY_FIELD = 'field_category';

  /**
   * Canonical categories vocabulary (confirmed in install config).
   *
   * @see web/modules/custom/myeventlane_schema/config/install/taxonomy.vocabulary.categories.yml
   */
  private const CATEGORY_VOCAB = 'categories';

  private Connection $database;
  private Schema $schema;
  private TimeInterface $time;
  private EntityTypeManagerInterface $entityTypeManager;
  private PopularEventsService $popular;

  /**
   * @var \Psr\Log\LoggerInterface
   */
  private $logger;

  public function __construct(
    Connection $database,
    TimeInterface $time,
    EntityTypeManagerInterface $entity_type_manager,
    PopularEventsService $popular,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->database = $database;
    $this->schema = $database->schema();
    $this->time = $time;
    $this->entityTypeManager = $entity_type_manager;
    $this->popular = $popular;
    $this->logger = $logger_factory->get('myeventlane_analytics');
  }

  /**
   * Returns trending categories for the lookback window.
   *
   * Uses a bounded pool of "popular events" to keep work predictable while
   * still reflecting real engagement. Category scores are sums of per-event
   * scores across all contributing events in the pool.
   *
   * @return array<int, array{
   *   tid:int,
   *   label:string,
   *   score:int,
   *   tickets_sold:int,
   *   rsvps:int,
   *   going:int,
   *   event_ids:int[],
   *   has_upcoming:bool
   * }>
   *   Ranked categories with transparency fields.
   */
  public function getTrendingCategories(
    int $days = self::DEFAULT_DAYS,
    int $limit = self::DEFAULT_CATEGORY_LIMIT,
    int $eventScanLimit = self::DEFAULT_EVENT_SCAN_LIMIT,
  ): array {
    $days = max(1, $days);
    $limit = max(1, $limit);
    $eventScanLimit = max($limit, $eventScanLimit);

    $popular_rows = $this->popular->getPopularEventIds($days, $eventScanLimit);
    if (empty($popular_rows)) {
      return [];
    }

    // Index popularity rows by nid for constant-time lookups during aggregation.
    $by_nid = [];
    foreach ($popular_rows as $row) {
      $nid = (int) ($row['nid'] ?? 0);
      if ($nid > 0) {
        $by_nid[$nid] = $row;
      }
    }
    if (empty($by_nid)) {
      return [];
    }

    $nids = array_keys($by_nid);

    $event_to_tids = $this->loadCategoryTidsForEvents($nids);
    if (empty($event_to_tids)) {
      return [];
    }

    // Aggregate by tid.
    $agg = [];
    foreach ($event_to_tids as $nid => $tids) {
      if (!isset($by_nid[$nid])) {
        continue;
      }
      $row = $by_nid[$nid];

      $score = (int) ($row['score'] ?? 0);
      $tickets_sold = (int) ($row['tickets_sold'] ?? 0);
      $rsvps = (int) ($row['rsvps'] ?? 0);
      $going = (int) ($row['going'] ?? ($tickets_sold + $rsvps));
      $is_past = (bool) ($row['is_past'] ?? FALSE);

      foreach ($tids as $tid) {
        if (!isset($agg[$tid])) {
          $agg[$tid] = [
            'tid' => (int) $tid,
            'score' => 0,
            'tickets_sold' => 0,
            'rsvps' => 0,
            'going' => 0,
            'event_ids' => [],
            'has_upcoming' => FALSE,
          ];
        }

        $agg[$tid]['score'] += $score;
        $agg[$tid]['tickets_sold'] += $tickets_sold;
        $agg[$tid]['rsvps'] += $rsvps;
        $agg[$tid]['going'] += $going;

        if (!$is_past) {
          $agg[$tid]['has_upcoming'] = TRUE;
        }

        // Collect contributing event IDs (capped, best-effort).
        if (count($agg[$tid]['event_ids']) < self::DEFAULT_EVENTS_PER_CATEGORY) {
          $agg[$tid]['event_ids'][] = (int) $nid;
        }
      }
    }

    if (empty($agg)) {
      return [];
    }

    // Attach labels for the categories vocabulary.
    $labels = $this->loadCategoryLabels(array_keys($agg));
    foreach ($agg as $tid => $row) {
      $agg[$tid]['label'] = (string) ($labels[(int) $tid] ?? '');
    }

    $items = array_values($agg);

    // Sort:
    // 1) Categories with upcoming events first (optional bias, never exclude).
    // 2) Score DESC
    // 3) Going DESC
    // 4) TID DESC (stable deterministic)
    usort($items, static function (array $a, array $b): int {
      if ((bool) $a['has_upcoming'] !== (bool) $b['has_upcoming']) {
        return $a['has_upcoming'] ? -1 : 1;
      }
      if ((int) $a['score'] !== (int) $b['score']) {
        return ((int) $b['score']) <=> ((int) $a['score']);
      }
      if ((int) $a['going'] !== (int) $b['going']) {
        return ((int) $b['going']) <=> ((int) $a['going']);
      }
      return ((int) $b['tid']) <=> ((int) $a['tid']);
    });

    return array_slice($items, 0, $limit);
  }

  /**
   * Returns trending events for a given category term.
   *
   * @return array<int, array{nid:int, score:int, tickets_sold:int, rsvps:int, going:int, is_past:bool}>
   *   Ranked event rows for the category (only events with engagement in window).
   */
  public function getTrendingEventsForCategory(int $tid, int $days = self::DEFAULT_DAYS, int $limit = self::DEFAULT_CATEGORY_LIMIT): array {
    $tid = max(1, $tid);
    $days = max(1, $days);
    $limit = max(1, $limit);

    // Get the full popularity ranking for the window, then filter by category
    // membership while preserving the popularity ordering.
    $popular_rows = $this->popular->getPopularEventRows($days);
    if (empty($popular_rows)) {
      return [];
    }

    $nids_in_category = $this->loadEventIdsForCategoryTid($tid);
    if (empty($nids_in_category)) {
      return [];
    }

    $allowed = array_fill_keys($nids_in_category, TRUE);
    $out = [];
    foreach ($popular_rows as $row) {
      $nid = (int) ($row['nid'] ?? 0);
      if ($nid > 0 && isset($allowed[$nid])) {
        $out[] = $row;
        if (count($out) >= $limit) {
          break;
        }
      }
    }

    return $out;
  }

  /**
   * Loads category tids for a list of event node IDs (single query).
   *
   * @param int[] $nids
   *
   * @return array<int, int[]>
   *   [nid => [tid, ...]]
   */
  private function loadCategoryTidsForEvents(array $nids): array {
    $nids = array_values(array_unique(array_map('intval', $nids)));
    if (empty($nids)) {
      return [];
    }

    $table = 'node__' . self::EVENT_CATEGORY_FIELD;
    $target_id = self::EVENT_CATEGORY_FIELD . '_target_id';

    if (!$this->schema->tableExists($table) || !$this->schema->fieldExists($table, $target_id)) {
      $this->logger->warning('TrendingCategoriesService: missing category field table/column (@table.@col).', [
        '@table' => $table,
        '@col' => $target_id,
      ]);
      return [];
    }

    $query = $this->database->select($table, 'c');
    $query->addField('c', 'entity_id', 'nid');
    $query->addField('c', $target_id, 'tid');
    $query->condition('c.entity_id', $nids, 'IN');

    $rows = $query->execute()->fetchAll();
    if (!$rows) {
      return [];
    }

    $out = [];
    foreach ($rows as $row) {
      $nid = (int) ($row->nid ?? 0);
      $tid = (int) ($row->tid ?? 0);
      if ($nid > 0 && $tid > 0) {
        $out[$nid] ??= [];
        $out[$nid][] = $tid;
      }
    }

    // Deduplicate per event.
    foreach ($out as $nid => $tids) {
      $out[$nid] = array_values(array_unique(array_map('intval', $tids)));
    }

    return $out;
  }

  /**
   * Loads all event node IDs tagged with a category term ID (single query).
   *
   * This does not filter published status; published-only is guaranteed by
   * intersecting with PopularEventsService rows.
   *
   * @return int[]
   *   Event node IDs.
   */
  private function loadEventIdsForCategoryTid(int $tid): array {
    $table = 'node__' . self::EVENT_CATEGORY_FIELD;
    $target_id = self::EVENT_CATEGORY_FIELD . '_target_id';

    if (!$this->schema->tableExists($table) || !$this->schema->fieldExists($table, $target_id)) {
      $this->logger->warning('TrendingCategoriesService: missing category field table/column (@table.@col).', [
        '@table' => $table,
        '@col' => $target_id,
      ]);
      return [];
    }

    $query = $this->database->select($table, 'c');
    $query->addField('c', 'entity_id', 'nid');
    $query->condition('c.' . $target_id, $tid);

    $rows = $query->execute()->fetchAll();
    if (!$rows) {
      return [];
    }

    $nids = [];
    foreach ($rows as $row) {
      $nid = (int) ($row->nid ?? 0);
      if ($nid > 0) {
        $nids[] = $nid;
      }
    }

    return array_values(array_unique($nids));
  }

  /**
   * Loads term labels for the categories vocabulary in one operation.
   *
   * @param int[] $tids
   *
   * @return array<int, string>
   *   [tid => label]
   */
  private function loadCategoryLabels(array $tids): array {
    $tids = array_values(array_unique(array_map('intval', $tids)));
    if (empty($tids)) {
      return [];
    }

    /** @var \Drupal\taxonomy\TermStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $storage->loadMultiple($tids);

    $out = [];
    foreach ($terms as $term) {
      if ($term instanceof TermInterface && $term->bundle() === self::CATEGORY_VOCAB) {
        $out[(int) $term->id()] = $term->label();
      }
    }

    return $out;
  }

}

