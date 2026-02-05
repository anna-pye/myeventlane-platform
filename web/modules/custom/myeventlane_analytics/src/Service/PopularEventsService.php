<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Schema;
use Drupal\Core\Datetime\TimeInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Computes "Popular this week" events using real engagement.
 *
 * Deterministic scoring (locked by spec):
 *   score = (tickets_sold * 3) + (rsvps * 1)
 *
 * Data sources (locked by decisions):
 * - Tickets sold (paid): Commerce orders/order items, SUM(quantity), last N days.
 * - RSVPs: rsvp_submission storage (entity tables), last N days.
 *
 * Hard rules:
 * - Exclude Boost purchases/spend: order_item.type <> 'boost'
 * - Only published events (node_field_data.status = 1)
 * - No N+1: single query per source, merged in PHP.
 * - Do not hide past events; optionally deprioritise past events at sort time.
 */
final class PopularEventsService {

  public const GEO_NONE = 'none';

  private const DEFAULT_LIMIT = 8;
  private const DEFAULT_DAYS = 7;

  /**
   * @var \Drupal\Core\Database\Connection
   */
  private Connection $database;

  /**
   * @var \Drupal\Core\Datetime\TimeInterface
   */
  private TimeInterface $time;

  /**
   * @var \Drupal\Core\Database\Schema
   */
  private Schema $schema;

  /**
   * @var \Psr\Log\LoggerInterface
   */
  private $logger;

  public function __construct(
    Connection $database,
    TimeInterface $time,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->database = $database;
    $this->time = $time;
    $this->schema = $database->schema();
    $this->logger = $logger_factory->get('myeventlane_analytics');
  }

  /**
   * Returns ranked popular events with transparency fields.
   *
   * @return array<int, array{nid:int, score:int, tickets_sold:int, rsvps:int, going:int, is_past:bool}>
   *   Each row includes both counts and the final score.
   */
  public function getPopularEventIds(int $days = self::DEFAULT_DAYS, int $limit = self::DEFAULT_LIMIT): array {
    $limit = max(1, $limit);
    $rows = $this->getPopularEventRows($days);
    return array_slice($rows, 0, $limit);
  }

  /**
   * Returns ALL ranked popular events for the lookback window.
   *
   * This is intentionally uncapped so other features (e.g. category trending)
   * can filter/aggregate without "top N overall" bias.
   *
   * @return array<int, array{nid:int, score:int, tickets_sold:int, rsvps:int, going:int, is_past:bool}>
   *   Ranked rows for all events with engagement in the window.
   */
  public function getPopularEventRows(int $days = self::DEFAULT_DAYS): array {
    $days = max(1, $days);

    $since = $this->time->getRequestTime() - (86400 * $days);

    // Source A: tickets sold (paid) from Commerce. SUM(quantity) over last N days.
    $tickets = $this->getTicketsSoldByEvent($since);

    // Source B: RSVPs (canonical) from rsvp_submission storage over last N days.
    $rsvps = $this->getRsvpsByEvent($since);

    // Merge counts for any event present in either list.
    $nids = array_unique(array_merge(array_keys($tickets), array_keys($rsvps)));
    if (empty($nids)) {
      return [];
    }

    // Fetch event start times once (for optional deprioritisation of past events).
    $starts = $this->getEventStartTimestamps($nids);

    $rows = [];
    foreach ($nids as $nid) {
      $tickets_sold = (int) ($tickets[$nid] ?? 0);
      $rsvp_count = (int) ($rsvps[$nid] ?? 0);

      // Spec: UI may show only "X going" where X = tickets_sold + rsvps.
      $going = $tickets_sold + $rsvp_count;

      // Spec: Deterministic score formula.
      $score = ($tickets_sold * 3) + ($rsvp_count * 1);

      // Past handling: do not hide; only used for sort tie-breaking.
      $start_ts = (int) ($starts[$nid] ?? 0);
      $is_past = ($start_ts > 0) ? ($start_ts < $this->time->getRequestTime()) : FALSE;

      $rows[] = [
        'nid' => (int) $nid,
        'score' => (int) $score,
        'tickets_sold' => (int) $tickets_sold,
        'rsvps' => (int) $rsvp_count,
        'going' => (int) $going,
        'is_past' => (bool) $is_past,
      ];
    }

    // Sort:
    // 1) Upcoming first (optional past deprioritisation, but never hidden).
    // 2) Score DESC
    // 3) Going DESC (useful for tie breaks)
    // 4) NID DESC (stable deterministic)
    usort($rows, static function (array $a, array $b): int {
      if ($a['is_past'] !== $b['is_past']) {
        return $a['is_past'] ? 1 : -1;
      }
      if ($a['score'] !== $b['score']) {
        return $b['score'] <=> $a['score'];
      }
      if ($a['going'] !== $b['going']) {
        return $b['going'] <=> $a['going'];
      }
      return $b['nid'] <=> $a['nid'];
    });

    return $rows;
  }

  /**
   * Tickets sold: SUM(quantity) per target event over last N seconds.
   *
   * Excludes Boost order items by type, and excludes carts.
   *
   * @return array<int, int>
   *   [nid => tickets_sold]
   */
  private function getTicketsSoldByEvent(int $since_ts): array {
    // Drupal Commerce schemas vary by install: some sites have *_field_data
    // tables, others use the base table only. Support both without guessing.
    $order_item_table = NULL;
    if ($this->schema->tableExists('commerce_order_item_field_data')) {
      $order_item_table = 'commerce_order_item_field_data';
    }
    elseif ($this->schema->tableExists('commerce_order_item')) {
      $order_item_table = 'commerce_order_item';
    }

    $order_table = NULL;
    if ($this->schema->tableExists('commerce_order_field_data')) {
      $order_table = 'commerce_order_field_data';
    }
    elseif ($this->schema->tableExists('commerce_order')) {
      $order_table = 'commerce_order';
    }

    $required_tables = [
      $order_item_table,
      $order_table,
      'commerce_order_item__field_target_event',
      'node_field_data',
    ];

    foreach ($required_tables as $table) {
      if (!is_string($table) || $table === '' || !$this->schema->tableExists($table)) {
        $this->logger->warning('PopularEventsService: missing Commerce table @t; tickets source disabled.', [
          '@t' => is_string($table) ? $table : '(unknown)',
        ]);
        return [];
      }
    }

    // We intentionally filter by order_item.created to match "engagement" timing.
    // You can change this to order.completed time later if you prefer that definition.
    $query = $this->database->select($order_item_table, 'oi');
    $query->addExpression('SUM(COALESCE(oi.quantity, 0))', 'tickets_sold');

    $query->innerJoin($order_table, 'o', 'o.order_id = oi.order_id');
    $query->innerJoin('commerce_order_item__field_target_event', 'te', 'te.entity_id = oi.order_item_id');

    // Join only published events.
    $query->innerJoin('node_field_data', 'n', 'n.nid = te.field_target_event_target_id AND n.type = :event_type AND n.status = 1', [
      ':event_type' => 'event',
    ]);

    // Exclude Boost order items (platform-only).
    $query->condition('oi.type', 'boost', '<>');

    // Exclude carts/drafts: cart flag is the safest no-guess constraint.
    if ($this->schema->fieldExists($order_table, 'cart')) {
      $query->condition('o.cart', 0);
    }

    // Optional: exclude cancelled orders if that column exists.
    // We avoid guessing your state machine; this is conservative.
    if ($this->schema->fieldExists($order_table, 'state')) {
      $query->condition('o.state', 'canceled', '<>');
    }

    // Time window (last N days).
    if ($this->schema->fieldExists($order_item_table, 'created')) {
      $query->condition('oi.created', $since_ts, '>=');
    }

    $query->addField('te', 'field_target_event_target_id', 'nid');
    $query->groupBy('te.field_target_event_target_id');

    $result = $query->execute()->fetchAllAssoc('nid');

    $out = [];
    foreach ($result as $nid => $row) {
      $out[(int) $nid] = (int) round((float) ($row->tickets_sold ?? 0));
    }
    return $out;
  }

  /**
   * RSVPs: count rsvp_submission records per event over last N seconds.
   *
   * Important: we do NOT guess the event reference field name.
   * We introspect rsvp_submission__* field tables and select the first column
   * that looks like a node entity reference target_id.
   *
   * @return array<int, int>
   *   [nid => rsvps]
   */
  private function getRsvpsByEvent(int $since_ts): array {
    // Core entity tables we expect for content entities.
    if (!$this->schema->tableExists('rsvp_submission_field_data')) {
      $this->logger->warning('PopularEventsService: rsvp_submission_field_data missing; RSVP source disabled.');
      return [];
    }

    // Find all field tables for this entity.
    // Example typical: rsvp_submission__field_event with column field_event_target_id.
    $field_tables = $this->schema->findTables('rsvp_submission__%');
    if (empty($field_tables)) {
      $this->logger->warning('PopularEventsService: no rsvp_submission__* field tables found; RSVP source disabled.');
      return [];
    }

    // Identify an event reference field table + its *_target_id column.
    $candidate = $this->detectRsvpEventReference($field_tables);
    if ($candidate === NULL) {
      $this->logger->warning('PopularEventsService: could not detect RSVP event reference field; RSVP source disabled.');
      return [];
    }

    $table = $candidate['table'];
    $target_id_col = $candidate['target_id_col'];

    // Ensure node_field_data exists for published check.
    if (!$this->schema->tableExists('node_field_data')) {
      $this->logger->warning('PopularEventsService: node_field_data missing; RSVP source disabled.');
      return [];
    }

    $query = $this->database->select('rsvp_submission_field_data', 'rsfd');
    $query->innerJoin($table, 'rse', 'rse.entity_id = rsfd.id');
    $query->innerJoin('node_field_data', 'n', "n.nid = rse.$target_id_col AND n.type = :event_type AND n.status = 1", [
      ':event_type' => 'event',
    ]);

    // Time window.
    if ($this->schema->fieldExists('rsvp_submission_field_data', 'created')) {
      $query->condition('rsfd.created', $since_ts, '>=');
    }

    // Status filter: we only apply if the column exists (no guessing).
    // You can tighten this later if you confirm the exact status model.
    if ($this->schema->fieldExists('rsvp_submission_field_data', 'status')) {
      $query->condition('rsfd.status', 'confirmed');
    }

    $query->addExpression('COUNT(rsfd.id)', 'rsvps');
    $query->addField('rse', $target_id_col, 'nid');
    $query->groupBy("rse.$target_id_col");

    $result = $query->execute()->fetchAllAssoc('nid');

    $out = [];
    foreach ($result as $nid => $row) {
      $out[(int) $nid] = (int) ($row->rsvps ?? 0);
    }
    return $out;
  }

  /**
   * Detects the RSVP event entity-reference field table + target id column.
   *
   * @param array<string, string> $field_tables
   *   Tables returned by Schema::findTables(), keyed by full table name.
   *
   * @return array{table:string, target_id_col:string}|null
   *   Selected table and target id column.
   */
  private function detectRsvpEventReference(array $field_tables): ?array {
    foreach (array_keys($field_tables) as $table) {
      // Must be a real table.
      if (!$this->schema->tableExists($table)) {
        continue;
      }
      $fields = $this->schema->fieldNames($table);
      if (empty($fields)) {
        continue;
      }

      // Pick the first column that looks like a node entity reference:
      // *_target_id is the canonical pattern for entity reference fields.
      foreach ($fields as $field_name) {
        if (!is_string($field_name)) {
          continue;
        }
        if (!str_ends_with($field_name, '_target_id')) {
          continue;
        }

        // Heuristic: ignore user references etc by preferring "event" in table name.
        // If no "event" table exists, we'll still accept the first target_id column.
        if (str_contains($table, 'event')) {
          return ['table' => $table, 'target_id_col' => $field_name];
        }
      }
    }

    // Second pass: accept any target_id column if we didn't find an "event" one.
    foreach (array_keys($field_tables) as $table) {
      if (!$this->schema->tableExists($table)) {
        continue;
      }
      $fields = $this->schema->fieldNames($table);
      foreach ($fields as $field_name) {
        if (is_string($field_name) && str_ends_with($field_name, '_target_id')) {
          return ['table' => $table, 'target_id_col' => $field_name];
        }
      }
    }

    return NULL;
  }

  /**
   * Fetches start timestamps for events (single query).
   *
   * @param int[] $nids
   *
   * @return array<int, int>
   *   [nid => start_ts]
   */
  private function getEventStartTimestamps(array $nids): array {
    $nids = array_values(array_unique(array_map('intval', $nids)));
    if (empty($nids)) {
      return [];
    }

    // Event start field table (typical). If it doesn't exist, return empty.
    if (!$this->schema->tableExists('node__field_event_start')) {
      return [];
    }
    if (!$this->schema->fieldExists('node__field_event_start', 'field_event_start_value')) {
      return [];
    }

    $query = $this->database->select('node__field_event_start', 's');
    $query->addField('s', 'entity_id', 'nid');
    $query->addField('s', 'field_event_start_value', 'start_value');
    $query->condition('s.entity_id', $nids, 'IN');

    $rows = $query->execute()->fetchAll();

    $out = [];
    foreach ($rows as $row) {
      $nid = (int) ($row->nid ?? 0);
      $value = (string) ($row->start_value ?? '');
      if ($nid > 0 && $value !== '') {
        // field_event_start_value is stored as DATETIME string in Drupal.
        $ts = strtotime($value);
        if ($ts !== FALSE) {
          $out[$nid] = (int) $ts;
        }
      }
    }
    return $out;
  }

}

