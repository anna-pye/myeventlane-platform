<?php

declare(strict_types=1);

namespace Drupal\myeventlane_domain_events\ProjectionReadModel;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_commerce\Service\OrderItemClassifier;
use Drupal\myeventlane_core\Utility\EntityLoadIds;
use Psr\Log\LoggerInterface;

/**
 * Read model for per-event vendor finance with projection-first lookup.
 */
final class FinanceReadModel {

  private const PROJECTION_TTL = 300;
  private const FALLBACK_TTL = 60;

  /**
   * Order states counted as paid for vendor line-item revenue.
   *
   * Aligns with BoostMetricsService and MetricsAggregator donation analytics.
   */
  private const PAID_ORDER_STATES = ['completed', 'fulfillment'];

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CacheBackendInterface $cache,
    private readonly LoggerInterface $logger,
    private readonly OrderItemClassifier $orderItemClassifier,
  ) {}

  /**
   * Returns event finance from projection, with safe fallback if empty.
   *
   * @return array{
   *   gross_revenue:float,
   *   mel_fee:float,
   *   stripe_fee:float,
   *   net_revenue:float,
   *   refund_total:float,
   *   organiser_donation_revenue:float
   * }
   *   Finance payload.
   */
  public function getEventFinance(int $vendorId, int $eventId): array {
    if ($vendorId <= 0 || $eventId <= 0) {
      return $this->emptyFinance();
    }

    $cacheKey = sprintf('mel:finance:%d:%d', $vendorId, $eventId);
    $cached = $this->cache->get($cacheKey);
    if ($cached !== FALSE && is_array($cached->data)) {
      return $cached->data;
    }

    $projection = $this->database->select('myeventlane_projection_finance', 'f')
      ->fields('f', ['gross_revenue', 'mel_fee', 'stripe_fee', 'net_revenue', 'refund_total'])
      ->condition('vendor_id', $vendorId)
      ->condition('event_node_id', $eventId)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    if (is_array($projection)) {
      $finance = [
        'gross_revenue' => (float) ($projection['gross_revenue'] ?? 0.0),
        'mel_fee' => (float) ($projection['mel_fee'] ?? 0.0),
        'stripe_fee' => (float) ($projection['stripe_fee'] ?? 0.0),
        'net_revenue' => (float) ($projection['net_revenue'] ?? 0.0),
        'refund_total' => (float) ($projection['refund_total'] ?? 0.0),
        'organiser_donation_revenue' => 0.0,
      ];
      $finance = $this->applyVendorLineItemRevenue($eventId, $finance);
      $this->cache->set(
        $cacheKey,
        $finance,
        time() + self::PROJECTION_TTL,
        [sprintf('finance:%d:%d', $vendorId, $eventId)]
      );
      return $finance;
    }

    // Expected until projector queue catches up.
    $fallback = $this->buildFallbackFinance($vendorId, $eventId);
    $fallback = $this->applyVendorLineItemRevenue($eventId, $fallback);
    $this->cache->set(
      $cacheKey,
      $fallback,
      time() + self::FALLBACK_TTL,
      [sprintf('finance:%d:%d', $vendorId, $eventId)]
    );
    return $fallback;
  }

  /**
   * Builds fallback finance from commerce_payment state/amount values.
   *
   * This path intentionally does not use myeventlane_refund_log.
   *
   * @return array{
   *   gross_revenue:float,
   *   mel_fee:float,
   *   stripe_fee:float,
   *   net_revenue:float,
   *   refund_total:float,
   *   organiser_donation_revenue:float
   * }
   *   Finance payload.
   */
  private function buildFallbackFinance(int $vendorId, int $eventId): array {
    try {
      if (!$this->eventBelongsToVendor($eventId, $vendorId)) {
        return $this->emptyFinance();
      }

      $orderIds = $this->loadOrderIdsForEvent($eventId);
      if ($orderIds === []) {
        return $this->emptyFinance();
      }

      $paymentStorage = $this->entityTypeManager->getStorage('commerce_payment');
      $paymentIds = $paymentStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('order_id', $orderIds, 'IN')
        ->condition('state', ['completed', 'partially_refunded', 'refunded'], 'IN')
        ->execute();

      if ($paymentIds === []) {
        return $this->emptyFinance();
      }

      $paymentIds = EntityLoadIds::normalizeForLoadMultiple($paymentIds);
      if ($paymentIds === []) {
        return $this->emptyFinance();
      }

      $grossRevenue = 0.0;
      $refundTotal = 0.0;
      $payments = $paymentStorage->loadMultiple($paymentIds);
      foreach ($payments as $payment) {
        $amount = $payment->getAmount();
        if ($amount !== NULL) {
          $grossRevenue += (float) $amount->getNumber();
        }

        $refunded = $payment->getRefundedAmount();
        if ($refunded !== NULL) {
          $refundTotal += (float) $refunded->getNumber();
        }
      }

      $grossRevenue = round($grossRevenue, 2);
      $refundTotal = round($refundTotal, 2);

      // Payment entities do not provide MEL/Stripe fee components directly.
      $melFee = 0.0;
      $stripeFee = 0.0;
      $netRevenue = round(max(0.0, $grossRevenue - $refundTotal), 2);

      return [
        'gross_revenue' => $grossRevenue,
        'mel_fee' => $melFee,
        'stripe_fee' => $stripeFee,
        'net_revenue' => $netRevenue,
        'refund_total' => $refundTotal,
        'organiser_donation_revenue' => 0.0,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Finance fallback failed for vendor {vendor_id}, event {event_id}: {message}', [
        'vendor_id' => $vendorId,
        'event_id' => $eventId,
        'message' => $e->getMessage(),
      ]);
      return $this->emptyFinance();
    }
  }

  /**
   * Verifies event ownership via configured vendor relation fields.
   */
  private function eventBelongsToVendor(int $eventId, int $vendorId): bool {
    foreach (['field_event_vendor', 'field_vendor', 'field_vendor_account'] as $fieldName) {
      $table = 'node__' . $fieldName;
      $column = $fieldName . '_target_id';
      if (!$this->database->schema()->tableExists($table)) {
        continue;
      }

      $exists = $this->database->select($table, 'f')
        ->fields('f', ['entity_id'])
        ->condition('entity_id', $eventId)
        ->condition($column, $vendorId)
        ->range(0, 1)
        ->execute()
        ->fetchField();

      if ($exists !== FALSE) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Loads order IDs linked to a specific event.
   *
   * @return int[]
   *   Distinct order IDs.
   */
  private function loadOrderIdsForEvent(int $eventId): array {
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
   * @return array{
   *   gross_revenue:float,
   *   mel_fee:float,
   *   stripe_fee:float,
   *   net_revenue:float,
   *   refund_total:float,
   *   organiser_donation_revenue:float
   * }
   *   Empty finance payload.
   */
  private function emptyFinance(): array {
    return [
      'gross_revenue' => 0.0,
      'mel_fee' => 0.0,
      'stripe_fee' => 0.0,
      'net_revenue' => 0.0,
      'refund_total' => 0.0,
      'organiser_donation_revenue' => 0.0,
    ];
  }

  /**
   * Aligns finance totals with vendor line-item revenue (tickets + organiser donations).
   *
   * Excludes platform_donation from vendor gross/net.
   *
   * @param array<string, float> $finance
   *   Base finance payload.
   *
   * @return array<string, float>
   *   Finance payload with vendor-aligned gross/net.
   */
  private function applyVendorLineItemRevenue(int $eventId, array $finance): array {
    $ticketRevenue = $this->sumTicketRevenueForEvent($eventId);
    $organiserDonationRevenue = $this->sumOrganiserDonationRevenueForEvent($eventId);
    $grossRevenue = round($ticketRevenue + $organiserDonationRevenue, 2);

    // Prefer event-scoped refunds when logged; otherwise keep projection/payment fallback.
    $loggedRefunds = $this->sumEventScopedRefundTotal($eventId);
    $fallbackRefunds = (float) ($finance['refund_total'] ?? 0.0);
    $refundTotal = $loggedRefunds > 0.0 ? $loggedRefunds : $fallbackRefunds;
    $melFee = (float) ($finance['mel_fee'] ?? 0.0);
    $stripeFee = (float) ($finance['stripe_fee'] ?? 0.0);
    $netRevenue = round(max(0.0, $grossRevenue - $refundTotal - $melFee - $stripeFee), 2);

    $finance['gross_revenue'] = $grossRevenue;
    $finance['net_revenue'] = $netRevenue;
    $finance['refund_total'] = $refundTotal;
    $finance['organiser_donation_revenue'] = round($organiserDonationRevenue, 2);

    return $finance;
  }

  /**
   * Sums completed refunds attributed to a single event.
   *
   * @param int $eventId
   *   Event node ID.
   *
   * @return float
   *   Refund total in major currency units.
   */
  private function sumEventScopedRefundTotal(int $eventId): float {
    if (
      $eventId <= 0
      || !$this->database->schema()->tableExists('myeventlane_refund_log')
    ) {
      return 0.0;
    }

    $query = $this->database->select('myeventlane_refund_log', 'r');
    $query->addExpression('COALESCE(SUM(r.amount_cents), 0)', 'sum_cents');
    $query->condition('r.event_id', $eventId);
    $query->condition('r.status', 'completed');
    $query->condition('r.amount_cents', 0, '>');

    $sumCents = (int) $query->execute()->fetchField();

    return round($sumCents / 100, 2);
  }

  /**
   * Sums ticket and vendor-eligible line revenue for an event (excludes all donations).
   */
  private function sumTicketRevenueForEvent(int $eventId): float {
    if (
      $eventId <= 0
      || !$this->database->schema()->tableExists('commerce_order_item')
      || !$this->database->schema()->tableExists('commerce_order')
      || !$this->database->schema()->tableExists('commerce_order_item__field_target_event')
    ) {
      return 0.0;
    }

    $excludedTypes = $this->orderItemClassifier->getExcludedTypes();
    $query = $this->database->select('commerce_order_item', 'oi');
    $query->join('commerce_order', 'o', 'o.order_id = oi.order_id');
    $query->join('commerce_order_item__field_target_event', 'lnk', 'lnk.entity_id = oi.order_item_id');
    $query->addExpression('COALESCE(SUM(oi.unit_price__number * oi.quantity), 0)', 'revenue');
    $query->condition('o.state', self::PAID_ORDER_STATES, 'IN');
    $query->condition('lnk.field_target_event_target_id', $eventId);
    $query->condition('oi.type', $excludedTypes, 'NOT IN');

    return round((float) $query->execute()->fetchField(), 2);
  }

  /**
   * Sums organiser donation line revenue for an event.
   */
  private function sumOrganiserDonationRevenueForEvent(int $eventId): float {
    $organiserTypes = $this->orderItemClassifier->getOrganiserDonationTypes();
    if (
      $eventId <= 0
      || $organiserTypes === []
      || !$this->database->schema()->tableExists('commerce_order_item')
      || !$this->database->schema()->tableExists('commerce_order')
      || !$this->database->schema()->tableExists('commerce_order_item__field_target_event')
    ) {
      return 0.0;
    }

    $query = $this->database->select('commerce_order_item', 'oi');
    $query->join('commerce_order', 'o', 'o.order_id = oi.order_id');
    $query->join('commerce_order_item__field_target_event', 'lnk', 'lnk.entity_id = oi.order_item_id');
    $query->addExpression('COALESCE(SUM(oi.unit_price__number * oi.quantity), 0)', 'revenue');
    $query->condition('o.state', self::PAID_ORDER_STATES, 'IN');
    $query->condition('lnk.field_target_event_target_id', $eventId);
    $query->condition('oi.type', $organiserTypes, 'IN');

    return round((float) $query->execute()->fetchField(), 2);
  }

}
