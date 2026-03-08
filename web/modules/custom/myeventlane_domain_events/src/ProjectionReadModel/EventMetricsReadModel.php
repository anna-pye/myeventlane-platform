<?php

declare(strict_types=1);

namespace Drupal\myeventlane_domain_events\ProjectionReadModel;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Psr\Log\LoggerInterface;

/**
 * Read model for per-event metrics with projection-first lookup.
 */
final class EventMetricsReadModel {

  private const PROJECTION_TTL = 300;
  private const FALLBACK_TTL = 60;

  /**
   * Order item types excluded from ticket/revenue calculations.
   *
   * These represent platform revenue, not vendor ticket revenue.
   *
   * @var string[]
   */
  private const EXCLUDED_ORDER_ITEM_TYPES = [
    'boost',
    'checkout_donation',
    'platform_donation',
    'rsvp_donation',
  ];

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CacheBackendInterface $cache,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns event metrics from projection, with safe fallback if empty.
   *
   * @return array{
   *   tickets_sold:int,
   *   rsvp_count:int,
   *   revenue_total:float,
   *   refund_count:int,
   *   checkin_count:int
   * }
   *   Event metrics payload.
   */
  public function getEventMetrics(int $eventId): array {
    if ($eventId <= 0) {
      return $this->emptyMetrics();
    }

    $cacheKey = sprintf('mel:event_metrics:%d', $eventId);
    $cached = $this->cache->get($cacheKey);
    if ($cached !== FALSE && is_array($cached->data)) {
      return $cached->data;
    }

    $projection = $this->database->select('myeventlane_projection_event_metrics', 'm')
      ->fields('m', ['tickets_sold', 'rsvp_count', 'revenue_total', 'refund_count', 'checkin_count'])
      ->condition('event_node_id', $eventId)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    if (is_array($projection)) {
      $metrics = [
        'tickets_sold' => (int) ($projection['tickets_sold'] ?? 0),
        'rsvp_count' => (int) ($projection['rsvp_count'] ?? 0),
        'revenue_total' => (float) ($projection['revenue_total'] ?? 0.0),
        'refund_count' => (int) ($projection['refund_count'] ?? 0),
        'checkin_count' => (int) ($projection['checkin_count'] ?? 0),
      ];
      $this->cache->set(
        $cacheKey,
        $metrics,
        time() + self::PROJECTION_TTL,
        [sprintf('event_metrics:%d', $eventId)]
      );
      return $metrics;
    }

    $this->logger->notice(
      'Projection miss for event {event_id}; using fallback metrics.',
      ['event_id' => $eventId]
    );

    $fallback = $this->buildFallbackMetrics($eventId);
    $this->cache->set(
      $cacheKey,
      $fallback,
      time() + self::FALLBACK_TTL,
      [sprintf('event_metrics:%d', $eventId)]
    );
    return $fallback;
  }

  /**
   * Builds fallback metrics using existing Commerce/RSVP/Ticket sources.
   *
   * Projection table is intentionally bypassed here.
   *
   * @return array{
   *   tickets_sold:int,
   *   rsvp_count:int,
   *   revenue_total:float,
   *   refund_count:int,
   *   checkin_count:int
   * }
   *   Event metrics payload.
   */
  private function buildFallbackMetrics(int $eventId): array {
    try {
      $orderIds = $this->loadEventOrderIds($eventId);

      $ticketsSold = $this->sumEventTicketQuantity($eventId);
      $rsvpCount = $this->countConfirmedEventRsvps($eventId);
      $revenueTotal = $this->sumEventRevenueFromPayments($orderIds);
      $refundCount = $this->countRefundedPayments($orderIds);
      $checkinCount = $this->countCheckedInTickets($eventId);

      return [
        'tickets_sold' => $ticketsSold,
        'rsvp_count' => $rsvpCount,
        'revenue_total' => $revenueTotal,
        'refund_count' => $refundCount,
        'checkin_count' => $checkinCount,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Event metrics fallback failed for event {event_id}: {message}', [
        'event_id' => $eventId,
        'message' => $e->getMessage(),
      ]);
      return $this->emptyMetrics();
    }
  }

  /**
   * @return int[]
   *   Distinct order IDs linked to event ticket order items.
   */
  private function loadEventOrderIds(int $eventId): array {
    if (
      !$this->database->schema()->tableExists('commerce_order_item') ||
      !$this->database->schema()->tableExists('commerce_order_item__field_target_event')
    ) {
      return [];
    }

    $query = $this->database->select('commerce_order_item', 'oi');
    $query->join('commerce_order_item__field_target_event', 'lnk', 'lnk.entity_id = oi.order_item_id');
    $query->addField('oi', 'order_id', 'order_id');
    $query->condition('lnk.field_target_event_target_id', $eventId);
    $query->distinct();

    $orderIds = [];
    foreach ($query->execute() as $row) {
      $orderId = (int) ($row->order_id ?? 0);
      if ($orderId > 0) {
        $orderIds[] = $orderId;
      }
    }

    return array_values(array_unique($orderIds));
  }

  /**
   * Sums paid ticket quantities for completed orders.
   */
  private function sumEventTicketQuantity(int $eventId): int {
    if (
      !$this->database->schema()->tableExists('commerce_order_item') ||
      !$this->database->schema()->tableExists('commerce_order') ||
      !$this->database->schema()->tableExists('commerce_order_item__field_target_event')
    ) {
      return 0;
    }

    $query = $this->database->select('commerce_order_item', 'oi');
    $query->join('commerce_order', 'o', 'o.order_id = oi.order_id');
    $query->join('commerce_order_item__field_target_event', 'lnk', 'lnk.entity_id = oi.order_item_id');
    $query->addExpression('COALESCE(SUM(oi.quantity), 0)', 'qty');
    $query->condition('o.state', 'completed');
    $query->condition('lnk.field_target_event_target_id', $eventId);
    $query->condition('oi.unit_price__number', '0', '>');
    $query->condition('oi.type', self::EXCLUDED_ORDER_ITEM_TYPES, 'NOT IN');

    return (int) $query->execute()->fetchField();
  }

  /**
   * Counts confirmed RSVPs for the event.
   */
  private function countConfirmedEventRsvps(int $eventId): int {
    if (
      !$this->database->schema()->tableExists('rsvp_submission') ||
      !$this->database->schema()->tableExists('rsvp_submission__event_id')
    ) {
      return 0;
    }

    $query = $this->database->select('rsvp_submission', 'r');
    $query->join('rsvp_submission__event_id', 're', 're.entity_id = r.id');
    $query->addExpression('COUNT(r.id)', 'cnt');
    $query->condition('re.event_id_target_id', $eventId);
    $query->condition('r.status', 'confirmed');

    return (int) $query->execute()->fetchField();
  }

  /**
   * Sums payment amounts for event orders from commerce_payment.
   */
  private function sumEventRevenueFromPayments(array $orderIds): float {
    if ($orderIds === []) {
      return 0.0;
    }

    $paymentStorage = $this->entityTypeManager->getStorage('commerce_payment');
    $paymentIds = $paymentStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_id', $orderIds, 'IN')
      ->condition('state', ['completed', 'partially_refunded', 'refunded'], 'IN')
      ->execute();

    if ($paymentIds === []) {
      return 0.0;
    }

    $total = 0.0;
    $payments = $paymentStorage->loadMultiple($paymentIds);
    foreach ($payments as $payment) {
      $amount = $payment->getAmount();
      if ($amount !== NULL) {
        $total += (float) $amount->getNumber();
      }
    }

    return round($total, 2);
  }

  /**
   * Counts refunded/partially_refunded payments for event orders.
   */
  private function countRefundedPayments(array $orderIds): int {
    if ($orderIds === []) {
      return 0;
    }

    $paymentStorage = $this->entityTypeManager->getStorage('commerce_payment');
    $paymentIds = $paymentStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_id', $orderIds, 'IN')
      ->condition('state', ['partially_refunded', 'refunded'], 'IN')
      ->execute();

    return count($paymentIds);
  }

  /**
   * Counts checked-in tickets from ticket entity state.
   */
  private function countCheckedInTickets(int $eventId): int {
    $ticketStorage = $this->entityTypeManager->getStorage('myeventlane_ticket');
    $ticketIds = $ticketStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event_id', $eventId)
      ->condition('status', 'checked_in')
      ->execute();

    return count($ticketIds);
  }

  /**
   * @return array{
   *   tickets_sold:int,
   *   rsvp_count:int,
   *   revenue_total:float,
   *   refund_count:int,
   *   checkin_count:int
   * }
   *   Empty metrics payload.
   */
  private function emptyMetrics(): array {
    return [
      'tickets_sold' => 0,
      'rsvp_count' => 0,
      'revenue_total' => 0.0,
      'refund_count' => 0,
      'checkin_count' => 0,
    ];
  }

}

