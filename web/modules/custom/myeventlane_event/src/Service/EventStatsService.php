<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Aggregated per-event ticket and revenue stats (Commerce + RSVP), cached.
 *
 * Uses a single SQL statement for Commerce aggregates plus scalar subqueries
 * for capacity and RSVP counts. Matches EventMetricsReadModel exclusion rules
 * for order item types and completed orders.
 */
final class EventStatsService {

  /**
   * Order item types excluded from ticket/revenue (platform revenue only).
   *
   * @var string[]
   */
  private const EXCLUDED_ORDER_ITEM_TYPES = [
    'boost',
    'checkout_donation',
    'platform_donation',
    'rsvp_donation',
  ];

  private const CACHE_TTL = 300;

  public function __construct(
    private readonly Connection $database,
    private readonly CacheBackendInterface $cache,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns cached stats for an event node.
   *
   * @return array{
   *   tickets_total: int,
   *   tickets_sold: int,
   *   attendees_count: int,
   *   revenue_total: float,
   *   sold_percentage: float,
   *   rsvp_count: int
   * }
   */
  public function getEventStats(int $event_id): array {
    if ($event_id <= 0) {
      return $this->emptyStats();
    }

    $cid = 'mel_event_stats:' . $event_id;
    if ($cached = $this->cache->get($cid)) {
      return is_array($cached->data) ? $cached->data : $this->emptyStats();
    }

    try {
      $data = $this->computeStats($event_id);
    }
    catch (\Throwable $e) {
      $this->logger->error('EventStatsService failed for event @id: @message', [
        '@id' => (string) $event_id,
        '@message' => $e->getMessage(),
      ]);
      $data = $this->emptyStats();
    }

    $tags = [
      'node:' . $event_id,
      'mel:event_stats:' . $event_id,
    ];
    $this->cache->set($cid, $data, time() + self::CACHE_TTL, $tags);

    return $data;
  }

  /**
   * Computes stats in one aggregate query (plus scalar subqueries).
   */
  private function computeStats(int $event_id): array {
    $schema = $this->database->schema();
    if (
      !$schema->tableExists('commerce_order_item') ||
      !$schema->tableExists('commerce_order') ||
      !$schema->tableExists('commerce_order_item__field_target_event')
    ) {
      return $this->emptyStats();
    }

    $args = [
      ':completed' => 'completed',
      ':eid' => $event_id,
    ];
    $exPlaceholders = [];
    foreach (self::EXCLUDED_ORDER_ITEM_TYPES as $i => $type) {
      $key = ':ex' . $i;
      $exPlaceholders[] = $key;
      $args[$key] = $type;
    }
    $exIn = implode(', ', $exPlaceholders);

    $capacitySql = '0';
    if ($schema->tableExists('node__field_event_capacity_total')) {
      $capacitySql = '(SELECT COALESCE(n.field_event_capacity_total_value, 0) FROM {node__field_event_capacity_total} n WHERE n.entity_id = ' . $event_id . ' AND n.deleted = 0 LIMIT 1)';
    }

    $rsvpSql = '0';
    if (
      $schema->tableExists('rsvp_submission') &&
      $schema->tableExists('rsvp_submission__event_id')
    ) {
      $rsvpSql = '(SELECT COUNT(r.id) FROM {rsvp_submission} r INNER JOIN {rsvp_submission__event_id} re ON re.entity_id = r.id WHERE re.event_id_target_id = ' . $event_id . " AND r.status = 'confirmed')";
    }

    $query = $this->database->query(
      "SELECT
        COALESCE(SUM(oi.quantity), 0) AS tickets_sold,
        COALESCE(SUM(oi.total_price__number), 0) AS revenue,
        {$capacitySql} AS tickets_total_cap,
        {$rsvpSql} AS rsvp_count
      FROM {commerce_order_item} oi
      INNER JOIN {commerce_order} o ON o.order_id = oi.order_id
      INNER JOIN {commerce_order_item__field_target_event} lnk ON lnk.entity_id = oi.order_item_id
      WHERE o.state = :completed
        AND lnk.field_target_event_target_id = :eid
        AND oi.unit_price__number > 0
        AND oi.type NOT IN ({$exIn})",
      $args
    );

    $row = $query->fetchAssoc();
    if (!$row) {
      return $this->emptyStats();
    }

    $ticketsSold = (int) ($row['tickets_sold'] ?? 0);
    $revenue = (float) ($row['revenue'] ?? 0.0);
    $rsvpCount = (int) ($row['rsvp_count'] ?? 0);
    $capacity = (int) ($row['tickets_total_cap'] ?? 0);

    $ticketsTotal = $capacity > 0 ? $capacity : max(1, $ticketsSold + $rsvpCount);
    $attendeesCount = $ticketsSold + $rsvpCount;
    $soldPercentage = $ticketsTotal > 0
      ? min(100.0, round(100.0 * $attendeesCount / $ticketsTotal, 1))
      : 0.0;

    return [
      'tickets_total' => $ticketsTotal,
      'tickets_sold' => $ticketsSold,
      'attendees_count' => $attendeesCount,
      'revenue_total' => round($revenue, 2),
      'sold_percentage' => $soldPercentage,
      'rsvp_count' => $rsvpCount,
    ];
  }

  /**
   * @return array{
   *   tickets_total: int,
   *   tickets_sold: int,
   *   attendees_count: int,
   *   revenue_total: float,
   *   sold_percentage: float,
   *   rsvp_count: int
   * }
   */
  private function emptyStats(): array {
    return [
      'tickets_total' => 0,
      'tickets_sold' => 0,
      'attendees_count' => 0,
      'revenue_total' => 0.0,
      'sold_percentage' => 0.0,
      'rsvp_count' => 0,
    ];
  }

}
