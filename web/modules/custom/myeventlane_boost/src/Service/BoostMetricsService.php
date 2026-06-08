<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\node\NodeInterface;
use Drupal\myeventlane_boost\BoostManager;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_core\Utility\EntityLoadIds;
use Drupal\myeventlane_rsvp\Entity\RsvpSubmissionInterface;

/**
 * Aggregates Boost metrics for display in vendor dashboard.
 *
 * Phase 1: spend, impressions, clicks, CTR, cost per click, sales during period, placements.
 * Phase 2: chart data (daily), orders following click, placement comparison, recommendations.
 */
final class BoostMetricsService {

  use StringTranslationTrait;

  private const CHART_DAYS = 30;

  /**
   * Constructs a BoostMetricsService.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\myeventlane_boost\BoostManager $boostManager
   *   The boost manager.
   * @param \Drupal\myeventlane_boost\Service\BoostEntitlementManager $entitlementManager
   *   The boost entitlement manager.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly BoostManager $boostManager,
    private readonly BoostEntitlementManager $entitlementManager,
    private readonly TimeInterface $time,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Gets Boost metrics summary for an event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Metrics array with keys:
   *   - spend: Total Boost spend (string, formatted currency)
   *   - impressions: Total impressions (int)
   *   - clicks: Total clicks (int)
   *   - ctr: Click-through rate (float, 0.0-1.0)
   *   - cost_per_click: Cost per click (string, formatted currency or NULL)
   *   - sales_during_period: Sales during boost period (array or NULL)
   *   - sales_during_boost: Formatted revenue during entitlement windows (string)
   *   - revenue_during_boost: Raw revenue during entitlement windows (float)
   *   - orders_during_boost: Completed order count during entitlement windows (int)
   *   - tickets_during_boost: Ticket quantity during entitlement windows (int)
   *   - rsvps_during_boost: Non-cancelled RSVP count during entitlement windows (int)
   *   - donation_revenue_during_boost: RSVP donation revenue during windows (float)
   *   - average_donation_during_boost: Average RSVP donation during windows (float)
   *   - donation_revenue_during_boost_formatted: Formatted donation revenue (string)
   *   - average_donation_during_boost_formatted: Formatted average donation (string)
   *   - rsvp_donation_count_during_boost: RSVPs with a donation during windows (int)
   *   - placements: Array of placement-level metrics
   */
  public function getEventBoostMetrics(NodeInterface $event): array {
    $eventId = (int) $event->id();
    $salesDuringBoostMetrics = $this->computeSalesDuringBoostMetrics($event);
    $rsvpsDuringBoostMetrics = $this->computeRsvpsDuringBoostMetrics($event);
    $outcomeMetrics = array_merge(
      $this->normalizeSalesDuringBoostMetrics($salesDuringBoostMetrics),
      $this->normalizeRsvpsDuringBoostMetrics($rsvpsDuringBoostMetrics),
    );

    // Get all Boost order items for this event.
    $boostOrderItems = $this->getBoostOrderItemsForEvent($eventId);

    if (empty($boostOrderItems)) {
      return array_merge([
        'spend' => '$0.00',
        'impressions' => 0,
        'clicks' => 0,
        'ctr' => 0.0,
        'cost_per_click' => NULL,
        'sales_during_period' => $this->formatSalesDuringPeriod($salesDuringBoostMetrics),
        'placements' => [],
        'chart_data' => NULL,
        'orders_following_click' => NULL,
        'placement_comparison' => [],
        'recommendations' => [],
      ], $outcomeMetrics);
    }

    // Calculate total spend.
    $totalSpend = 0;
    $orderItemIds = [];
    foreach ($boostOrderItems as $orderItem) {
      $totalPrice = $orderItem->getTotalPrice();
      if ($totalPrice) {
        $totalSpend += (float) $totalPrice->getNumber();
      }
      $orderItemIds[] = (int) $orderItem->id();
    }

    // Get aggregated stats from database.
    $stats = $this->getAggregatedStats($orderItemIds);

    // Calculate CTR.
    $totalImpressions = $stats['total_impressions'] ?? 0;
    $totalClicks = $stats['total_clicks'] ?? 0;
    $ctr = $totalImpressions > 0 ? ($totalClicks / $totalImpressions) : 0.0;

    // Calculate cost per click.
    $costPerClick = NULL;
    if ($totalClicks > 0 && $totalSpend > 0) {
      $costPerClick = $totalSpend / $totalClicks;
    }

    // Get sales during boost entitlement windows.
    $salesDuringPeriod = $this->formatSalesDuringPeriod($salesDuringBoostMetrics);

    // Get placement-level breakdown.
    $placements = $this->getPlacementBreakdown($orderItemIds);

    $chartData = $this->getChartData($orderItemIds, $eventId);
    $ordersFollowingClick = $this->getOrdersFollowingClick($event, $boostOrderItems);
    $placementComparison = $this->getPlacementComparison($orderItemIds, $placements);
    $recommendations = $this->getRecommendations($placements, $placementComparison, $chartData);

    return array_merge([
      'spend' => '$' . number_format($totalSpend, 2, '.', ','),
      'impressions' => $totalImpressions,
      'clicks' => $totalClicks,
      'ctr' => $ctr,
      'cost_per_click' => $costPerClick !== NULL ? '$' . number_format($costPerClick, 2, '.', ',') : NULL,
      'sales_during_period' => $salesDuringPeriod,
      'placements' => $placements,
      'chart_data' => $chartData,
      'orders_following_click' => $ordersFollowingClick,
      'placement_comparison' => $placementComparison,
      'recommendations' => $recommendations,
    ], $outcomeMetrics);
  }

  /**
   * Aggregates Boost impressions and clicks across managed events.
   *
   * Uses myeventlane_boost_stats via existing order-item aggregation.
   *
   * @param list<int> $eventIds
   *   Published event node IDs for the vendor.
   *
   * @return array{impressions: int, clicks: int, ctr_percent: float}
   *   Vendor-level Boost reach metrics.
   */
  public function getVendorBoostRollup(array $eventIds): array {
    $eventIds = array_values(array_filter(array_map('intval', $eventIds), static fn(int $id): bool => $id > 0));
    if ($eventIds === []) {
      return [
        'impressions' => 0,
        'clicks' => 0,
        'ctr_percent' => 0.0,
      ];
    }

    try {
      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
      $candidateIds = $orderItemStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'boost')
        ->condition('field_target_event', $eventIds, 'IN')
        ->execute();

      if ($candidateIds === []) {
        return [
          'impressions' => 0,
          'clicks' => 0,
          'ctr_percent' => 0.0,
        ];
      }

      $candidateIds = array_values(array_unique(array_map('intval', EntityLoadIds::normalizeForLoadMultiple($candidateIds))));
      $orderItems = $orderItemStorage->loadMultiple($candidateIds);
      $orderItemIds = [];
      foreach ($orderItems as $orderItem) {
        if (!$orderItem instanceof OrderItemInterface) {
          continue;
        }
        $order = $this->loadParentOrder($orderItem);
        if (!$order || !in_array($order->getState()->getId(), ['completed', 'fulfillment'], TRUE)) {
          continue;
        }
        $orderItemIds[] = (int) $orderItem->id();
      }
    }
    catch (\Throwable) {
      return [
        'impressions' => 0,
        'clicks' => 0,
        'ctr_percent' => 0.0,
      ];
    }

    $stats = $this->getAggregatedStats($orderItemIds);
    $impressions = (int) ($stats['total_impressions'] ?? 0);
    $clicks = (int) ($stats['total_clicks'] ?? 0);
    $ctrPercent = $impressions > 0 ? round($clicks / $impressions * 100, 1) : 0.0;

    return [
      'impressions' => $impressions,
      'clicks' => $clicks,
      'ctr_percent' => $ctrPercent,
    ];
  }

  /**
   * Returns Boost CTR display values keyed by event node ID.
   *
   * @param list<int> $eventIds
   *   Event node IDs.
   *
   * @return array<int, array{impressions: int, clicks: int, ctr_display: string}>
   *   Per-event Boost CTR (em dash when no impressions).
   */
  public function getEventBoostCtrByEventIds(array $eventIds): array {
    $eventIds = array_values(array_filter(array_map('intval', $eventIds), static fn(int $id): bool => $id > 0));
    $map = [];
    foreach ($eventIds as $eventId) {
      $map[$eventId] = [
        'impressions' => 0,
        'clicks' => 0,
        'ctr_display' => '—',
      ];
    }
    if ($eventIds === []) {
      return $map;
    }

    try {
      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
      $candidateIds = $orderItemStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'boost')
        ->condition('field_target_event', $eventIds, 'IN')
        ->execute();
      if ($candidateIds === []) {
        return $map;
      }

      $candidateIds = array_values(array_unique(array_map('intval', EntityLoadIds::normalizeForLoadMultiple($candidateIds))));
      $orderItems = $orderItemStorage->loadMultiple($candidateIds);
      $eventOrderItemIds = [];
      foreach ($orderItems as $orderItem) {
        if (!$orderItem instanceof OrderItemInterface) {
          continue;
        }
        $order = $this->loadParentOrder($orderItem);
        if (!$order || !in_array($order->getState()->getId(), ['completed', 'fulfillment'], TRUE)) {
          continue;
        }
        if (!$orderItem->hasField('field_target_event') || $orderItem->get('field_target_event')->isEmpty()) {
          continue;
        }
        $eventId = (int) $orderItem->get('field_target_event')->target_id;
        if ($eventId <= 0) {
          continue;
        }
        $eventOrderItemIds[$eventId][] = (int) $orderItem->id();
      }

      if ($eventOrderItemIds === []) {
        return $map;
      }

      $allOrderItemIds = [];
      foreach ($eventOrderItemIds as $ids) {
        $allOrderItemIds = array_merge($allOrderItemIds, $ids);
      }
      $allOrderItemIds = array_values(array_unique($allOrderItemIds));
      $statsRows = $this->database->select('myeventlane_boost_stats', 's')
        ->fields('s', ['boost_order_item_id', 'impressions', 'clicks'])
        ->condition('s.boost_order_item_id', $allOrderItemIds, 'IN')
        ->execute()
        ->fetchAll();

      $itemStats = [];
      foreach ($statsRows as $row) {
        $itemId = (int) ($row->boost_order_item_id ?? 0);
        if ($itemId <= 0) {
          continue;
        }
        if (!isset($itemStats[$itemId])) {
          $itemStats[$itemId] = ['impressions' => 0, 'clicks' => 0];
        }
        $itemStats[$itemId]['impressions'] += (int) ($row->impressions ?? 0);
        $itemStats[$itemId]['clicks'] += (int) ($row->clicks ?? 0);
      }

      foreach ($eventOrderItemIds as $eventId => $itemIds) {
        $impressions = 0;
        $clicks = 0;
        foreach ($itemIds as $itemId) {
          $impressions += (int) ($itemStats[$itemId]['impressions'] ?? 0);
          $clicks += (int) ($itemStats[$itemId]['clicks'] ?? 0);
        }
        $map[$eventId] = [
          'impressions' => $impressions,
          'clicks' => $clicks,
          'ctr_display' => $impressions > 0
            ? number_format(round($clicks / $impressions * 100, 1), 1) . '%'
            : '—',
        ];
      }
    }
    catch (\Throwable) {
      return $map;
    }

    return $map;
  }

  /**
   * Gets all Boost order items for an event.
   *
   * @param int $eventId
   *   The event node ID.
   *
   * @return \Drupal\commerce_order\Entity\OrderItemInterface[]
   *   Array of Boost order items.
   */
  private function getBoostOrderItemsForEvent(int $eventId): array {
    $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');

    $query = $orderItemStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'boost')
      ->condition('field_target_event', $eventId);

    $orderItemIds = $query->execute();

    if (empty($orderItemIds)) {
      return [];
    }

    $orderItemIds = array_values(array_unique(array_map(
      static fn ($id): int => (int) $id,
      array_filter(
        EntityLoadIds::normalizeForLoadMultiple($orderItemIds),
        static fn ($id): bool => is_numeric($id) && (int) $id > 0,
      )
    )));
    if ($orderItemIds === []) {
      return [];
    }

    $orderItems = $orderItemStorage->loadMultiple($orderItemIds);

    // Filter to only items from completed/paid orders.
    $validOrderItems = [];
    foreach ($orderItems as $orderItem) {
      if (!$orderItem instanceof OrderItemInterface) {
        continue;
      }

      $order = $this->loadParentOrder($orderItem);
      if (!$order || !in_array($order->getState()->getId(), ['completed', 'fulfillment'], TRUE)) {
        continue;
      }

      $validOrderItems[] = $orderItem;
    }

    return $validOrderItems;
  }

  /**
   * Gets aggregated stats for Boost order items.
   *
   * @param array $orderItemIds
   *   Array of order item IDs.
   *
   * @return array
   *   Stats array with keys: total_impressions, total_clicks.
   */
  private function getAggregatedStats(array $orderItemIds): array {
    if (empty($orderItemIds)) {
      return ['total_impressions' => 0, 'total_clicks' => 0];
    }

    try {
      $query = $this->database->select('myeventlane_boost_stats', 's')
        ->condition('s.boost_order_item_id', $orderItemIds, 'IN')
        ->fields('s', ['impressions', 'clicks']);

      $results = $query->execute()->fetchAll();

      $totalImpressions = 0;
      $totalClicks = 0;

      foreach ($results as $row) {
        $totalImpressions += (int) $row->impressions;
        $totalClicks += (int) $row->clicks;
      }

      return [
        'total_impressions' => $totalImpressions,
        'total_clicks' => $totalClicks,
      ];
    }
    catch (\Exception $e) {
      return ['total_impressions' => 0, 'total_clicks' => 0];
    }
  }

  /**
   * Computes sales metrics during entitlement windows.
   *
   * Only for paid events. Counts completed orders whose placement timestamp
   * falls within any non-revoked entitlement window for the event.
   * Non-causal attribution - temporal correlation only.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Metrics array with keys:
   *   - sales_during_boost: formatted revenue string
   *   - revenue_during_boost: float
   *   - orders_during_boost: int
   *   - tickets_during_boost: int
   */
  private function computeSalesDuringBoostMetrics(NodeInterface $event): array {
    $eventId = (int) $event->id();
    $zeros = $this->zeroSalesDuringBoostMetrics();

    if (!$event->hasField('field_product_target') || $event->get('field_product_target')->isEmpty()) {
      return $zeros;
    }

    $windows = $this->entitlementManager->getEntitlementWindowsForEvent($eventId);
    if ($windows === []) {
      return $zeros;
    }

    $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
    $orderItems = $orderItemStorage->loadByProperties([
      'field_target_event' => $eventId,
    ]);

    if ($orderItems === []) {
      return [
        'sales_during_boost' => '$0.00',
        'revenue_during_boost' => 0.0,
        'orders_during_boost' => 0,
        'tickets_during_boost' => 0,
      ];
    }

    $orders = [];
    $totalRevenue = 0.0;
    $totalTickets = 0;

    foreach ($orderItems as $orderItem) {
      if (!$orderItem instanceof OrderItemInterface) {
        continue;
      }

      if ($this->isExcludedTicketSalesItem($orderItem)) {
        continue;
      }

      if (!$orderItem->hasField('field_target_event')
          || (int) ($orderItem->get('field_target_event')->target_id ?? 0) !== $eventId) {
        continue;
      }

      $order = $this->loadParentOrder($orderItem);
      if (!$order || !in_array($order->getState()->getId(), ['completed', 'fulfillment'], TRUE)) {
        continue;
      }

      $orderTimestamp = $this->getOrderSalesTimestamp($order);
      if (!$this->isTimestampWithinEntitlementWindows($orderTimestamp, $windows)) {
        continue;
      }

      $orderId = (int) $order->id();
      if (!isset($orders[$orderId])) {
        $orders[$orderId] = TRUE;
      }

      $totalTickets += (int) $orderItem->getQuantity();
      $totalPrice = $orderItem->getTotalPrice();
      if ($totalPrice) {
        $totalRevenue += (float) $totalPrice->getNumber();
      }
    }

    return [
      'sales_during_boost' => '$' . number_format($totalRevenue, 2, '.', ','),
      'revenue_during_boost' => $totalRevenue,
      'orders_during_boost' => count($orders),
      'tickets_during_boost' => $totalTickets,
    ];
  }

  /**
   * Computes RSVP metrics during entitlement windows.
   *
   * Counts non-cancelled RSVP submissions whose created timestamp falls
   * within any non-revoked entitlement window. Donation revenue prefers
   * realised completed rsvp_donation order item amounts linked to each
   * submission; falls back to the submission donation field when no paid
   * order item exists.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Metrics array with RSVP-during-boost keys.
   */
  private function computeRsvpsDuringBoostMetrics(NodeInterface $event): array {
    $eventId = (int) $event->id();
    $zeros = $this->zeroRsvpsDuringBoostMetrics();

    $windows = $this->entitlementManager->getEntitlementWindowsForEvent($eventId);
    if ($windows === []) {
      return $zeros;
    }

    if (!$this->entityTypeManager->hasDefinition('rsvp_submission')) {
      return $zeros;
    }

    try {
      $storage = $this->entityTypeManager->getStorage('rsvp_submission');
      $submissionIds = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('event_id', $eventId)
        ->condition('status', 'cancelled', '<>')
        ->execute();

      if ($submissionIds === []) {
        return $zeros;
      }

      /** @var \Drupal\myeventlane_rsvp\Entity\RsvpSubmissionInterface[] $submissions */
      $submissions = $storage->loadMultiple($submissionIds);
      $paidDonationsBySubmission = $this->loadCompletedRsvpDonationAmountsBySubmission($eventId);

      $rsvpCount = 0;
      $donationRevenue = 0.0;
      $donationCount = 0;

      foreach ($submissions as $submission) {
        if (!$submission instanceof RsvpSubmissionInterface) {
          continue;
        }

        $created = (int) $submission->get('created')->value;
        if (!$this->isTimestampWithinEntitlementWindows($created, $windows)) {
          continue;
        }

        $rsvpCount++;
        $submissionId = (int) $submission->id();
        $donationAmount = $paidDonationsBySubmission[$submissionId]
          ?? (float) ($submission->get('donation')->value ?? 0);
        if ($donationAmount > 0) {
          $donationRevenue += $donationAmount;
          $donationCount++;
        }
      }

      $averageDonation = $donationCount > 0 ? round($donationRevenue / $donationCount, 2) : 0.0;

      return [
        'rsvps_during_boost' => $rsvpCount,
        'donation_revenue_during_boost' => round($donationRevenue, 2),
        'average_donation_during_boost' => $averageDonation,
        'donation_revenue_during_boost_formatted' => '$' . number_format($donationRevenue, 2, '.', ','),
        'average_donation_during_boost_formatted' => '$' . number_format($averageDonation, 2, '.', ','),
        'rsvp_donation_count_during_boost' => $donationCount,
      ];
    }
    catch (\Exception $e) {
      return $zeros;
    }
  }

  /**
   * Loads paid RSVP donation amounts keyed by submission ID.
   *
   * Uses completed rsvp_donation order items linked via field_rsvp_submission.
   *
   * @return array<int, float>
   *   Submission ID => donation amount from completed orders.
   */
  private function loadCompletedRsvpDonationAmountsBySubmission(int $eventId): array {
    $amounts = [];

    try {
      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
      $orderItemIds = $orderItemStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'rsvp_donation')
        ->condition('field_target_event', $eventId)
        ->execute();

      if ($orderItemIds === []) {
        return $amounts;
      }

      $orderItems = $orderItemStorage->loadMultiple($orderItemIds);
      foreach ($orderItems as $orderItem) {
        if (!$orderItem instanceof OrderItemInterface) {
          continue;
        }

        if (!$orderItem->hasField('field_rsvp_submission') || $orderItem->get('field_rsvp_submission')->isEmpty()) {
          continue;
        }

        $order = $this->loadParentOrder($orderItem);
        if (!$order || !in_array($order->getState()->getId(), ['completed', 'fulfillment'], TRUE)) {
          continue;
        }

        $submissionId = (int) ($orderItem->get('field_rsvp_submission')->target_id ?? 0);
        if ($submissionId <= 0) {
          continue;
        }

        $totalPrice = $orderItem->getTotalPrice();
        if ($totalPrice) {
          $amounts[$submissionId] = (float) $totalPrice->getNumber();
        }
      }
    }
    catch (\Exception $e) {
      return $amounts;
    }

    return $amounts;
  }

  /**
   * Maps computed sales metrics to the legacy sales_during_period shape.
   *
   * @param array $metrics
   *   Result from computeSalesDuringBoostMetrics().
   *
   * @return array
   *   Legacy array with count and revenue.
   */
  private function formatSalesDuringPeriod(array $metrics): array {
    return [
      'count' => (int) ($metrics['orders_during_boost'] ?? 0),
      'revenue' => (string) ($metrics['sales_during_boost'] ?? '$0.00'),
    ];
  }

  /**
   * Normalizes sales-during-boost metrics for the public payload.
   *
   * @param array $metrics
   *   Result from computeSalesDuringBoostMetrics().
   *
   * @return array<string, mixed>
   *   Payload fragment with the four sales-during-boost keys.
   */
  private function normalizeSalesDuringBoostMetrics(array $metrics): array {
    return [
      'sales_during_boost' => $metrics['sales_during_boost'],
      'revenue_during_boost' => $metrics['revenue_during_boost'],
      'orders_during_boost' => $metrics['orders_during_boost'],
      'tickets_during_boost' => $metrics['tickets_during_boost'],
    ];
  }

  /**
   * Normalizes RSVP-during-boost metrics for the public payload.
   *
   * @param array $metrics
   *   Result from computeRsvpsDuringBoostMetrics().
   *
   * @return array<string, mixed>
   *   Payload fragment with RSVP-during-boost keys.
   */
  private function normalizeRsvpsDuringBoostMetrics(array $metrics): array {
    return [
      'rsvps_during_boost' => (int) ($metrics['rsvps_during_boost'] ?? 0),
      'donation_revenue_during_boost' => (float) ($metrics['donation_revenue_during_boost'] ?? 0.0),
      'average_donation_during_boost' => (float) ($metrics['average_donation_during_boost'] ?? 0.0),
      'donation_revenue_during_boost_formatted' => (string) ($metrics['donation_revenue_during_boost_formatted'] ?? '$0.00'),
      'average_donation_during_boost_formatted' => (string) ($metrics['average_donation_during_boost_formatted'] ?? '$0.00'),
      'rsvp_donation_count_during_boost' => (int) ($metrics['rsvp_donation_count_during_boost'] ?? 0),
    ];
  }

  /**
   * Zero-initialised paid-event boost outcome metrics.
   *
   * @return array<string, int|float|string>
   *   Sales-during-boost keys with zero defaults.
   */
  private function zeroSalesDuringBoostMetrics(): array {
    return [
      'sales_during_boost' => '$0.00',
      'revenue_during_boost' => 0.0,
      'orders_during_boost' => 0,
      'tickets_during_boost' => 0,
    ];
  }

  /**
   * Zero-initialised RSVP boost outcome metrics.
   *
   * @return array<string, int|float|string>
   *   RSVP-during-boost keys with zero defaults.
   */
  private function zeroRsvpsDuringBoostMetrics(): array {
    return [
      'rsvps_during_boost' => 0,
      'donation_revenue_during_boost' => 0.0,
      'average_donation_during_boost' => 0.0,
      'donation_revenue_during_boost_formatted' => '$0.00',
      'average_donation_during_boost_formatted' => '$0.00',
      'rsvp_donation_count_during_boost' => 0,
    ];
  }

  /**
   * Checks whether a timestamp falls within any entitlement window.
   *
   * Windows are inclusive at start and exclusive at end, matching entitlement
   * active semantics.
   *
   * @param int $timestamp
   *   Unix timestamp to test.
   * @param array<int, array{starts: int, ends: int}> $windows
   *   Entitlement windows.
   */
  private function isTimestampWithinEntitlementWindows(int $timestamp, array $windows): bool {
    foreach ($windows as $window) {
      if ($timestamp >= $window['starts'] && $timestamp < $window['ends']) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Resolves the order timestamp used for sales-during-boost attribution.
   */
  private function getOrderSalesTimestamp(OrderInterface $order): int {
    $completedTime = (int) $order->getCompletedTime();
    if ($completedTime > 0) {
      return $completedTime;
    }

    return (int) $order->getCreatedTime();
  }

  /**
   * Determines whether an order item should be excluded from ticket sales totals.
   */
  private function isExcludedTicketSalesItem(OrderItemInterface $orderItem): bool {
    return in_array($orderItem->bundle(), [
      'boost',
      'checkout_donation',
      'platform_donation',
      'rsvp_donation',
    ], TRUE);
  }

  /**
   * Gets placement-level breakdown.
   *
   * @param array $orderItemIds
   *   Array of order item IDs.
   *
   * @return array
   *   Array of placement metrics, each with:
   *   - placement: Placement key
   *   - impressions: Int
   *   - clicks: Int
   *   - ctr: Float
   *   - status: String (Active/Completed/Refunded)
   *   - date_range: String
   *   - budget: String (formatted currency)
   *   - spend_to_date: String (formatted currency)
   *   - start_ts: Int|null Unix timestamp (for export/date filtering)
   *   - end_ts: Int|null Unix timestamp (for export/date filtering)
   */
  private function getPlacementBreakdown(array $orderItemIds): array {
    if (empty($orderItemIds)) {
      return [];
    }

    try {
      $query = $this->database->select('myeventlane_boost_stats', 's')
        ->condition('s.boost_order_item_id', $orderItemIds, 'IN')
        ->fields('s', ['boost_order_item_id', 'placement', 'impressions', 'clicks']);

      $results = $query->execute()->fetchAll();

      $placements = [];
      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');

      foreach ($results as $row) {
        $orderItemId = (int) $row->boost_order_item_id;
        $placement = $row->placement;
        $impressions = (int) $row->impressions;
        $clicks = (int) $row->clicks;

        $ctr = $impressions > 0 ? ($clicks / $impressions) : 0.0;

        // Load order item to get budget and status.
        $orderItem = $orderItemStorage->load($orderItemId);
        $budget = '$0.00';
        $spendToDate = '$0.00';
        $status = 'Unknown';
        $dateRange = '—';
        $startTs = NULL;
        $endTs = NULL;

        if ($orderItem instanceof OrderItemInterface) {
          $totalPrice = $orderItem->getTotalPrice();
          if ($totalPrice) {
            $num = (float) $totalPrice->getNumber();
            $budget = '$' . number_format($num, 2, '.', ',');
            $spendToDate = $budget;
          }

          $order = $this->loadParentOrder($orderItem);
          if ($order) {
            $orderState = $order->getState()->getId();
            if ($orderState === 'completed' || $orderState === 'fulfillment') {
              $status = 'Active';
            }
            elseif (in_array($orderState, ['canceled', 'refunded'], TRUE)) {
              $status = 'Refunded';
            }
            else {
              $status = 'Scheduled';
            }

            // Get boost window from target event.
            $targetEventId = (int) ($orderItem->get('field_target_event')->target_id ?? 0);
            if ($targetEventId > 0) {
              $event = $this->entityTypeManager->getStorage('node')->load($targetEventId);
              if ($event instanceof NodeInterface) {
                $boostStatus = $this->boostManager->getBoostStatusForEvent($event);
                if ($boostStatus['end_timestamp']) {
                  $orderCreated = $order->getCreatedTime();
                  $startTs = $orderCreated;
                  $endTs = $boostStatus['end_timestamp'];
                  $startDate = date('M j, Y', $orderCreated);
                  $endDate = date('M j, Y', $boostStatus['end_timestamp']);
                  $dateRange = $startDate . ' – ' . $endDate;
                }
              }
            }
          }
        }

        $placements[] = [
          'placement' => $placement,
          'impressions' => $impressions,
          'clicks' => $clicks,
          'ctr' => $ctr,
          'status' => $status,
          'date_range' => $dateRange,
          'budget' => $budget,
          'spend_to_date' => $spendToDate,
          'start_ts' => $startTs,
          'end_ts' => $endTs,
        ];
      }

      return $placements;
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Chart data from daily rollups (Phase 2).
   *
   * Returns NULL if fewer than 3 data points. Max 30 days.
   *
   * @param int[] $orderItemIds
   *   Boost order item IDs.
   * @param int $eventId
   *   Event node ID.
   *
   * @return array|null
   *   'impressions_vs_clicks' => { labels, impressions, clicks }, 'ctr_by_placement' => { labels, data }, or NULL.
   */
  private function getChartData(array $orderItemIds, int $eventId): ?array {
    if (empty($orderItemIds) || !$this->database->schema()->tableExists('myeventlane_boost_stats_daily')) {
      return NULL;
    }

    $now = $this->time->getRequestTime();
    $from = gmdate('Y-m-d', $now - self::CHART_DAYS * 86400);

    try {
      $rows = $this->database->select('myeventlane_boost_stats_daily', 'd')
        ->fields('d', ['date', 'impressions', 'clicks', 'placement'])
        ->condition('d.boost_order_item_id', $orderItemIds, 'IN')
        ->condition('d.date', $from, '>=')
        ->orderBy('d.date', 'ASC')
        ->execute()
        ->fetchAll();
    }
    catch (\Exception $e) {
      return NULL;
    }

    $byDate = [];
    foreach ($rows as $r) {
      $date = $r->date;
      if (!isset($byDate[$date])) {
        $byDate[$date] = ['impressions' => 0, 'clicks' => 0];
      }
      $byDate[$date]['impressions'] += (int) $r->impressions;
      $byDate[$date]['clicks'] += (int) $r->clicks;
    }
    ksort($byDate);

    if (count($byDate) < 3) {
      return NULL;
    }

    $labels = array_keys($byDate);
    $impressions = array_values(array_map(static fn (array $d) => $d['impressions'], $byDate));
    $clicks = array_values(array_map(static fn (array $d) => $d['clicks'], $byDate));

    $ctrByPlacement = [];
    foreach ($rows as $r) {
      $p = $r->placement;
      if (!isset($ctrByPlacement[$p])) {
        $ctrByPlacement[$p] = ['impressions' => 0, 'clicks' => 0];
      }
      $ctrByPlacement[$p]['impressions'] += (int) $r->impressions;
      $ctrByPlacement[$p]['clicks'] += (int) $r->clicks;
    }
    $ctrLabels = array_keys($ctrByPlacement);
    $ctrData = [];
    foreach ($ctrByPlacement as $p => $v) {
      $ctrData[] = $v['impressions'] > 0 ? round(100.0 * $v['clicks'] / $v['impressions'], 2) : 0.0;
    }

    return [
      'impressions_vs_clicks' => [
        'labels' => $labels,
        'impressions' => $impressions,
        'clicks' => $clicks,
      ],
      'ctr_by_placement' => [
        'labels' => $ctrLabels,
        'data' => $ctrData,
      ],
    ];
  }

  /**
   * Orders following a Boost click (within 24h).
   *
   * Paid events only. Completed orders. Click occurred before order.
   * Label exactly "Orders following a Boost click (within 24h)".
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param \Drupal\commerce_order\Entity\OrderItemInterface[] $boostOrderItems
   *   Boost order items for this event.
   *
   * @return array|null
   *   { count, revenue } or NULL if not applicable.
   */
  private function getOrdersFollowingClick(NodeInterface $event, array $boostOrderItems): ?array {
    if (!$event->hasField('field_product_target') || $event->get('field_product_target')->isEmpty()) {
      return NULL;
    }

    if (!$this->database->schema()->tableExists('myeventlane_boost_click_log')) {
      return NULL;
    }

    $eventId = (int) $event->id();
    $orderItemIds = array_map(static fn ($i) => (int) $i->id(), $boostOrderItems);

    try {
      $clicks = $this->database->select('myeventlane_boost_click_log', 'c')
        ->fields('c', ['clicked_at'])
        ->condition('c.event_id', $eventId)
        ->condition('c.boost_order_item_id', $orderItemIds, 'IN')
        ->execute()
        ->fetchCol();
    }
    catch (\Exception $e) {
      return NULL;
    }

    if (empty($clicks)) {
      return ['count' => 0, 'revenue' => '$0.00'];
    }

    $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
    $orderItems = $orderItemStorage->loadByProperties(['field_target_event' => $eventId]);
    $matchedOrderIds = [];

    foreach ($orderItems as $item) {
      if (!$item instanceof OrderItemInterface || $item->bundle() === 'boost') {
        continue;
      }
      $order = $this->loadParentOrder($item);
      if (!$order || !in_array($order->getState()->getId(), ['completed', 'fulfillment'], TRUE)) {
        continue;
      }
      $orderCreated = $order->getCreatedTime();
      $orderId = (int) $order->id();
      if (isset($matchedOrderIds[$orderId])) {
        continue;
      }
      foreach ($clicks as $clickedAt) {
        $clickedAt = (int) $clickedAt;
        if ($orderCreated > $clickedAt && $orderCreated <= $clickedAt + 86400) {
          $matchedOrderIds[$orderId] = $order;
          break;
        }
      }
    }

    $revenue = 0.0;
    foreach ($matchedOrderIds as $order) {
      foreach ($order->getItems() as $item) {
        if (in_array($item->bundle(), ['boost', 'checkout_donation', 'platform_donation', 'rsvp_donation'], TRUE)) {
          continue;
        }
        if (!$item->hasField('field_target_event') || (int) ($item->get('field_target_event')->target_id ?? 0) !== $eventId) {
          continue;
        }
        $tp = $item->getTotalPrice();
        if ($tp) {
          $revenue += (float) $tp->getNumber();
        }
      }
    }

    return [
      'count' => count($matchedOrderIds),
      'revenue' => '$' . number_format($revenue, 2, '.', ','),
    ];
  }

  /**
   * Placement performance comparison (avg clicks/day).
   *
   * @param int[] $orderItemIds
   *   Boost order item IDs.
   * @param array $placements
   *   Result from getPlacementBreakdown.
   *
   * @return array
   *   Rows with placement, impressions, clicks, ctr, avg_clicks_per_day.
   */
  private function getPlacementComparison(array $orderItemIds, array $placements): array {
    if (empty($orderItemIds) || !$this->database->schema()->tableExists('myeventlane_boost_stats_daily')) {
      return [];
    }

    $now = $this->time->getRequestTime();
    $from = gmdate('Y-m-d', $now - self::CHART_DAYS * 86400);

    try {
      $rows = $this->database->select('myeventlane_boost_stats_daily', 'd')
        ->fields('d', ['placement', 'impressions', 'clicks', 'date'])
        ->condition('d.boost_order_item_id', $orderItemIds, 'IN')
        ->condition('d.date', $from, '>=')
        ->execute()
        ->fetchAll();
    }
    catch (\Exception $e) {
      return [];
    }

    $byPlacement = [];
    foreach ($rows as $r) {
      $p = $r->placement;
      if (!isset($byPlacement[$p])) {
        $byPlacement[$p] = ['impressions' => 0, 'clicks' => 0, 'days' => []];
      }
      $byPlacement[$p]['impressions'] += (int) $r->impressions;
      $byPlacement[$p]['clicks'] += (int) $r->clicks;
      $byPlacement[$p]['days'][$r->date] = TRUE;
    }

    $out = [];
    foreach ($byPlacement as $placement => $v) {
      $im = $v['impressions'];
      $cl = $v['clicks'];
      $days = count($v['days']) ?: 1;
      $ctr = $im > 0 ? ($cl / $im) : 0.0;
      $avgClicks = $days > 0 ? round($cl / $days, 1) : 0.0;
      $out[] = [
        'placement' => $placement,
        'impressions' => $im,
        'clicks' => $cl,
        'ctr' => $ctr,
        'avg_clicks_per_day' => $avgClicks,
      ];
    }

    return $out;
  }

  /**
   * Rules-based recommendations. No AI. Hidden if low confidence.
   *
   * @param array $placements
   *   Placement breakdown.
   * @param array $placementComparison
   *   Placement comparison.
   * @param array|null $chartData
   *   Chart data.
   *
   * @return string[]
   *   List of recommendation strings.
   */
  private function getRecommendations(array $placements, array $placementComparison, ?array $chartData): array {
    $out = [];

    if (count($placementComparison) < 2) {
      return $out;
    }

    usort($placementComparison, static fn ($a, $b) => $b['ctr'] <=> $a['ctr']);
    $best = $placementComparison[0];
    $worst = $placementComparison[count($placementComparison) - 1];
    if ($best['ctr'] > 0 && $worst['ctr'] >= 0 && $best['placement'] !== $worst['placement']) {
      if (str_starts_with($best['placement'], 'category_') && (str_starts_with($worst['placement'], 'homepage_') || $worst['placement'] === 'homepage_discover')) {
        $out[] = (string) $this->t('Category placements outperformed homepage for this event.');
      }
      elseif (str_starts_with($worst['placement'], 'category_') && str_starts_with($best['placement'], 'homepage_')) {
        $out[] = (string) $this->t('Homepage placements outperformed category for this event.');
      }
    }

    return $out;
  }

  /**
   * Safely loads the parent order for an order item.
   *
   * Avoids OrderItem::getOrder(), which can emit warnings when order_id is
   * unresolved during order refresh/post-load flows.
   */
  private function loadParentOrder(OrderItemInterface $orderItem): ?OrderInterface {
    if ($orderItem->get('order_id')->isEmpty()) {
      return NULL;
    }

    $orderId = (int) $orderItem->get('order_id')->target_id;
    if ($orderId <= 0) {
      return NULL;
    }

    $order = $this->entityTypeManager
      ->getStorage('commerce_order')
      ->load($orderId);

    return $order instanceof OrderInterface ? $order : NULL;
  }

}
