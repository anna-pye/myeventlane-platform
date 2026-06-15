<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Schema;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\myeventlane_event\Service\PublicEventVisibility;
use Drupal\node\NodeInterface;

/**
 * Computes "Popular this week" events using real engagement.
 *
 * Deterministic scoring (locked by spec):
 *   score = (tickets_sold * 3) + (rsvps * 1)
 *
 * Data sources (locked by decisions):
 * - Tickets sold (paid): Commerce orders/order items, SUM(quantity), last N days.
 * - RSVPs: rsvp_submission base table (confirmed), last N days.
 *
 * Hard rules:
 * - Exclude Boost purchases/spend: order_item.type <> 'boost'
 * - Only published events (node_field_data.status = 1)
 * - Only publicly listable upcoming events (PublicEventVisibility)
 * - No N+1: single query per source, merged in PHP.
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
   * @var \Drupal\Component\Datetime\TimeInterface
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

  /**
   * @var \Drupal\myeventlane_analytics\Service\OrderItemClassifier
   */
  private OrderItemClassifier $orderItemClassifier;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * @var \Drupal\myeventlane_event\Service\PublicEventVisibility
   */
  private PublicEventVisibility $publicEventVisibility;

  public function __construct(
    Connection $database,
    TimeInterface $time,
    LoggerChannelFactoryInterface $logger_factory,
    OrderItemClassifier $orderItemClassifier,
    EntityTypeManagerInterface $entity_type_manager,
    PublicEventVisibility $public_event_visibility,
  ) {
    $this->database = $database;
    $this->time = $time;
    $this->schema = $database->schema();
    $this->logger = $logger_factory->get('myeventlane_analytics');
    $this->orderItemClassifier = $orderItemClassifier;
    $this->entityTypeManager = $entity_type_manager;
    $this->publicEventVisibility = $public_event_visibility;
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
    $nids = array_values(array_filter(array_map(static function ($nid): int {
      if (is_int($nid)) {
        return $nid;
      }
      if (is_float($nid)) {
        return (int) $nid;
      }
      if (is_string($nid) && is_numeric($nid)) {
        return (int) $nid;
      }
      return 0;
    }, $nids), static fn (int $nid): bool => $nid > 0));
    if ($nids === []) {
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

      // Past handling: retained for sort stability among listable rows.
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

    return $this->filterPubliclyListableRows($rows);
  }

  /**
   * Restricts rows to canonical public discovery eligibility.
   *
   * @param array<int, array{nid:int, score:int, tickets_sold:int, rsvps:int, going:int, is_past:bool}> $rows
   *
   * @return array<int, array{nid:int, score:int, tickets_sold:int, rsvps:int, going:int, is_past:bool}>
   */
  private function filterPubliclyListableRows(array $rows): array {
    if ($rows === []) {
      return [];
    }

    $nids = array_values(array_unique(array_map(static fn (array $row): int => (int) ($row['nid'] ?? 0), $rows)));
    $nids = array_values(array_filter($nids, static fn (int $nid): bool => $nid > 0));
    if ($nids === []) {
      return [];
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);

    return array_values(array_filter($rows, function (array $row) use ($nodes): bool {
      $nid = (int) ($row['nid'] ?? 0);
      if ($nid < 1) {
        return FALSE;
      }
      $node = $nodes[$nid] ?? NULL;
      return $node instanceof NodeInterface
        && $this->publicEventVisibility->isPubliclyListable($node);
    }));
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

    // Exclude donation and Boost order items (platform revenue only).
    $query->condition('oi.type', $this->orderItemClassifier->getExcludedTypes(), 'NOT IN');

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
      $nid = is_numeric($nid) ? (int) $nid : 0;
      if ($nid < 1) {
        continue;
      }
      $out[$nid] = (int) round((float) ($row->tickets_sold ?? 0));
    }
    return $out;
  }

  /**
   * RSVPs: count confirmed rsvp_submission rows per event over last N seconds.
   *
   * Uses the canonical rsvp_submission base table (event_id column), aligned
   * with RsvpStatsService. Legacy myeventlane_rsvp rows are merged when present.
   *
   * @return array<int, int>
   *   [nid => rsvps]
   */
  private function getRsvpsByEvent(int $since_ts): array {
    $out = $this->getRsvpsFromSubmissionTable($since_ts);

    if ($this->schema->tableExists('myeventlane_rsvp')) {
      foreach ($this->getRsvpsFromLegacyTable($since_ts) as $nid => $count) {
        $out[$nid] = ($out[$nid] ?? 0) + $count;
      }
    }

    return $out;
  }

  /**
   * Confirmed RSVPs from the rsvp_submission content entity base table.
   *
   * @return array<int, int>
   *   [nid => rsvps]
   */
  private function getRsvpsFromSubmissionTable(int $since_ts): array {
    if (!$this->schema->tableExists('rsvp_submission')) {
      return [];
    }

    if (!$this->schema->tableExists('node_field_data')) {
      return [];
    }

    $query = $this->database->select('rsvp_submission', 'rs');
    $query->innerJoin('node_field_data', 'n', 'n.nid = rs.event_id AND n.type = :event_type AND n.status = 1', [
      ':event_type' => 'event',
    ]);
    $query->condition('rs.status', 'confirmed');
    $query->condition('rs.event_id', 0, '>');
    if ($this->schema->fieldExists('rsvp_submission', 'created')) {
      $query->condition('rs.created', $since_ts, '>=');
    }

    $query->addExpression('COUNT(rs.id)', 'rsvps');
    $query->addField('rs', 'event_id', 'nid');
    $query->groupBy('rs.event_id');

    $result = $query->execute()->fetchAllAssoc('nid');

    $out = [];
    foreach ($result as $nid => $row) {
      $nid = is_numeric($nid) ? (int) $nid : 0;
      if ($nid < 1) {
        continue;
      }
      $out[$nid] = (int) ($row->rsvps ?? 0);
    }

    return $out;
  }

  /**
   * Legacy RSVP rows from myeventlane_rsvp (active only).
   *
   * @return array<int, int>
   *   [nid => rsvps]
   */
  private function getRsvpsFromLegacyTable(int $since_ts): array {
    if (!$this->schema->tableExists('myeventlane_rsvp')) {
      return [];
    }

    if (!$this->schema->tableExists('node_field_data')) {
      return [];
    }

    $query = $this->database->select('myeventlane_rsvp', 'r');
    $query->innerJoin('node_field_data', 'n', 'n.nid = r.event_nid AND n.type = :event_type AND n.status = 1', [
      ':event_type' => 'event',
    ]);
    $query->condition('r.status', 'active');
    $query->condition('r.event_nid', 0, '>');
    if ($this->schema->fieldExists('myeventlane_rsvp', 'created')) {
      $query->condition('r.created', $since_ts, '>=');
    }

    $query->addExpression('COUNT(r.id)', 'rsvps');
    $query->addField('r', 'event_nid', 'nid');
    $query->groupBy('r.event_nid');

    $result = $query->execute()->fetchAllAssoc('nid');

    $out = [];
    foreach ($result as $nid => $row) {
      $nid = is_numeric($nid) ? (int) $nid : 0;
      if ($nid < 1) {
        continue;
      }
      $out[$nid] = (int) ($row->rsvps ?? 0);
    }

    return $out;
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
