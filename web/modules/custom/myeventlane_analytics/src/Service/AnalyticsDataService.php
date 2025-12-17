<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Core analytics data service.
 *
 * Provides aggregated data for analytics dashboards.
 */
final class AnalyticsDataService {

  /**
   * Constructs AnalyticsDataService.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
  ) {}

  /**
   * Gets time-series sales data for an event.
   *
   * @param int $eventId
   *   The event node ID.
   * @param string $period
   *   Period: 'day', 'week', or 'month'.
   * @param int|null $startDate
   *   Unix timestamp for start date (optional).
   * @param int|null $endDate
   *   Unix timestamp for end date (optional).
   *
   * @return array
   *   Array of data points with 'date', 'ticket_count', 'revenue'.
   */
  public function getSalesTimeSeries(int $eventId, string $period = 'day', ?int $startDate = NULL, ?int $endDate = NULL): array {
    // Get all order items for this event.
    $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
    $orderItems = $orderItemStorage->loadByProperties([
      'field_target_event' => $eventId,
    ]);

    if (empty($orderItems)) {
      return [];
    }

    // Group by date period.
    $grouped = [];
    foreach ($orderItems as $item) {
      $order = $item->getOrder();
      if (!$order || $order->getState()->getId() !== 'completed') {
        continue;
      }

      $completedTime = (int) $order->getCompletedTime();
      if ($startDate && $completedTime < $startDate) {
        continue;
      }
      if ($endDate && $completedTime > $endDate) {
        continue;
      }

      // Group by period.
      $dateKey = $this->getDateKey($completedTime, $period);
      if (!isset($grouped[$dateKey])) {
        $grouped[$dateKey] = [
          'date' => $dateKey,
          'ticket_count' => 0,
          'revenue' => 0.0,
        ];
      }

      $quantity = (int) $item->getQuantity();
      $grouped[$dateKey]['ticket_count'] += $quantity;

      $totalPrice = $item->getTotalPrice();
      if ($totalPrice) {
        $grouped[$dateKey]['revenue'] += (float) $totalPrice->getNumber();
      }
    }

    // Sort by date.
    ksort($grouped);

    return array_values($grouped);
  }

  /**
   * Gets revenue breakdown by ticket type for an event.
   *
   * @param int $eventId
   *   The event node ID.
   *
   * @return array
   *   Array with 'ticket_type', 'sold', 'revenue', 'conversion_rate'.
   */
  public function getTicketTypeBreakdown(int $eventId): array {
    $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
    $orderItems = $orderItemStorage->loadByProperties([
      'field_target_event' => $eventId,
    ]);

    $breakdown = [];

    foreach ($orderItems as $item) {
      $order = $item->getOrder();
      if (!$order || $order->getState()->getId() !== 'completed') {
        continue;
      }

      $purchasedEntity = $item->getPurchasedEntity();
      if (!$purchasedEntity) {
        continue;
      }

      $variationTitle = $purchasedEntity->label();
      // Extract ticket type from variation title.
      $ticketType = $variationTitle;
      if (strpos($variationTitle, ' – ') !== FALSE) {
        $parts = explode(' – ', $variationTitle, 2);
        $ticketType = $parts[1] ?? $variationTitle;
      }

      if (!isset($breakdown[$ticketType])) {
        $breakdown[$ticketType] = [
          'ticket_type' => $ticketType,
          'sold' => 0,
          'revenue' => 0.0,
        ];
      }

      $quantity = (int) $item->getQuantity();
      $breakdown[$ticketType]['sold'] += $quantity;

      $totalPrice = $item->getTotalPrice();
      if ($totalPrice) {
        $breakdown[$ticketType]['revenue'] += (float) $totalPrice->getNumber();
      }
    }

    return array_values($breakdown);
  }

  /**
   * Gets conversion funnel data for an event.
   *
   * @param int $eventId
   *   The event node ID.
   *
   * @return array
   *   Array with funnel stages and counts.
   */
  public function getConversionFunnel(int $eventId): array {
    // Get event node views (approximate from page views).
    // Note: This requires page view tracking. For now, we'll estimate.
    $eventNode = $this->entityTypeManager->getStorage('node')->load($eventId);
    if (!$eventNode) {
      return [];
    }

    // Get cart additions (order items in cart state).
    $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
    $allOrderItems = $orderItemStorage->loadByProperties([
      'field_target_event' => $eventId,
    ]);

    $cartAdditions = 0;
    $checkoutStarted = 0;
    $completed = 0;

    foreach ($allOrderItems as $item) {
      $order = $item->getOrder();
      if (!$order) {
        continue;
      }

      $state = $order->getState()->getId();
      if ($state === 'completed') {
        $completed += (int) $item->getQuantity();
      }
      elseif (in_array($state, ['draft', 'cart'])) {
        $cartAdditions += (int) $item->getQuantity();
      }
      elseif (in_array($state, ['validation', 'checkout'])) {
        $checkoutStarted += (int) $item->getQuantity();
      }
    }

    // Estimate views (this is approximate - would need proper tracking).
    $views = max($completed * 10, 100); // Rough estimate.

    return [
      'views' => $views,
      'cart_additions' => $cartAdditions,
      'checkout_started' => $checkoutStarted,
      'completed' => $completed,
    ];
  }

  /**
   * Gets date key for grouping.
   *
   * @param int $timestamp
   *   Unix timestamp.
   * @param string $period
   *   Period: 'day', 'week', or 'month'.
   *
   * @return string
   *   Date key string.
   */
  private function getDateKey(int $timestamp, string $period): string {
    switch ($period) {
      case 'week':
        return date('Y-W', $timestamp);

      case 'month':
        return date('Y-m', $timestamp);

      case 'day':
      default:
        return date('Y-m-d', $timestamp);
    }
  }

}


















