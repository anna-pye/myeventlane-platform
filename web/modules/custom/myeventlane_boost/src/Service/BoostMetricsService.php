<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Service;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\node\NodeInterface;
use Drupal\myeventlane_boost\BoostManager;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

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
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly BoostManager $boostManager,
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
   *   - placements: Array of placement-level metrics
   */
  public function getEventBoostMetrics(NodeInterface $event): array {
    $eventId = (int) $event->id();

    // Get all Boost order items for this event.
    $boostOrderItems = $this->getBoostOrderItemsForEvent($eventId);

    if (empty($boostOrderItems)) {
      return [
        'spend' => '$0.00',
        'impressions' => 0,
        'clicks' => 0,
        'ctr' => 0.0,
        'cost_per_click' => NULL,
        'sales_during_period' => NULL,
        'placements' => [],
        'chart_data' => NULL,
        'orders_following_click' => NULL,
        'placement_comparison' => [],
        'recommendations' => [],
      ];
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

    // Get sales during boost period.
    $salesDuringPeriod = $this->getSalesDuringBoostPeriod($event, $boostOrderItems);

    // Get placement-level breakdown.
    $placements = $this->getPlacementBreakdown($orderItemIds);

    $chartData = $this->getChartData($orderItemIds, $eventId);
    $ordersFollowingClick = $this->getOrdersFollowingClick($event, $boostOrderItems);
    $placementComparison = $this->getPlacementComparison($orderItemIds, $placements);
    $recommendations = $this->getRecommendations($placements, $placementComparison, $chartData);

    return [
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
    ];
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

    $orderItems = $orderItemStorage->loadMultiple($orderItemIds);

    // Filter to only items from completed/paid orders.
    $validOrderItems = [];
    foreach ($orderItems as $orderItem) {
      if (!$orderItem instanceof OrderItemInterface) {
        continue;
      }

      $order = $orderItem->getOrder();
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
   * Gets sales during boost period.
   *
   * Only for paid events. Counts completed orders created during boost active window.
   * Non-causal attribution - just temporal correlation.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param \Drupal\commerce_order\Entity\OrderItemInterface[] $boostOrderItems
   *   Array of Boost order items.
   *
   * @return array|null
   *   Sales array with keys: count, revenue (formatted string), or NULL if not applicable.
   */
  private function getSalesDuringBoostPeriod(NodeInterface $event, array $boostOrderItems): ?array {
    $eventId = (int) $event->id();

    // Only for paid events.
    if (!$event->hasField('field_product_target') || $event->get('field_product_target')->isEmpty()) {
      return NULL;
    }

    // Get boost active window from event's field_promo_expires.
    $boostStatus = $this->boostManager->getBoostStatusForEvent($event);
    if (!$boostStatus['active'] && !$boostStatus['expired']) {
      // Boost never active or not yet started.
      return NULL;
    }

    // For Phase 1, use current boost window if active, or historical if expired.
    // We need to determine the boost start time. Since we don't have field_promo_start,
    // we'll use the order creation time of the first boost order item as proxy.
    $boostStartTime = NULL;
    foreach ($boostOrderItems as $orderItem) {
      $order = $orderItem->getOrder();
      if ($order) {
        $orderCreated = $order->getCreatedTime();
        if ($boostStartTime === NULL || $orderCreated < $boostStartTime) {
          $boostStartTime = $orderCreated;
        }
      }
    }

    if ($boostStartTime === NULL) {
      return NULL;
    }

    $boostEndTime = $boostStatus['end_timestamp'] ?? time();

    // Query order items for this event, then filter by order state and date.
    $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
    $orderItems = $orderItemStorage->loadByProperties([
      'field_target_event' => $event->id(),
    ]);

    if (empty($orderItems)) {
      return [
        'count' => 0,
        'revenue' => '$0.00',
      ];
    }

    // Filter to completed orders created during boost window.
    $orders = [];
    $processedOrderIds = [];

    foreach ($orderItems as $orderItem) {
      if (!$orderItem instanceof OrderItemInterface) {
        continue;
      }

      // Exclude Boost items themselves.
      if ($orderItem->bundle() === 'boost') {
        continue;
      }

      $order = $orderItem->getOrder();
      if (!$order || !in_array($order->getState()->getId(), ['completed', 'fulfillment'], TRUE)) {
        continue;
      }

      // Check if order was created during boost window.
      $orderCreated = $order->getCreatedTime();
      if ($orderCreated < $boostStartTime || $orderCreated > $boostEndTime) {
        continue;
      }

      // Avoid counting same order multiple times if it has multiple items.
      $orderId = (int) $order->id();
      if (!isset($processedOrderIds[$orderId])) {
        $orders[$orderId] = $order;
        $processedOrderIds[$orderId] = TRUE;
      }
    }

    $totalRevenue = 0;
    $orderCount = count($orders);

    foreach ($orders as $order) {
      // Only count ticket revenue for items targeting this event.
      // Exclude boost items, donations, etc.
      foreach ($order->getItems() as $item) {
        if ($item->bundle() === 'boost'
            || $item->bundle() === 'checkout_donation'
            || $item->bundle() === 'platform_donation'
            || $item->bundle() === 'rsvp_donation') {
          continue;
        }

        // Only count items that target this event.
        if (!$item->hasField('field_target_event')
            || (int) ($item->get('field_target_event')->target_id ?? 0) !== $eventId) {
          continue;
        }

        $totalPrice = $item->getTotalPrice();
        if ($totalPrice) {
          $totalRevenue += (float) $totalPrice->getNumber();
        }
      }
    }

    return [
      'count' => $orderCount,
      'revenue' => '$' . number_format($totalRevenue, 2, '.', ','),
    ];
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

          $order = $orderItem->getOrder();
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
      $order = $item->getOrder();
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

}
