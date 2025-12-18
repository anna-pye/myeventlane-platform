<?php

declare(strict_types=1);

namespace Drupal\myeventlane_finance\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_finance\ValueObject\DateRange;

/**
 * BAS Report Service.
 *
 * Aggregates GST-relevant data for Business Activity Statement reporting.
 * This service is read-only and only aggregates existing transaction data.
 */
final class BasReportService {

  /**
   * Constructs BasReportService.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Gets platform-wide BAS summary for admin.
   *
   * @param \Drupal\myeventlane_finance\ValueObject\DateRange $range
   *   Date range for the report.
   *
   * @return array
   *   BAS summary array with:
   *   - period: Formatted date range string
   *   - g1_total_sales: Total sales amount (float)
   *   - gst_on_sales_1a: GST collected on sales (float)
   *   - gst_on_purchases_1b: GST on purchases (float)
   *   - gst_refunds: GST refunded (float)
   *   - net_gst: Net GST payable/refundable (float)
   */
  public function getAdminBasSummary(DateRange $range): array {
    $orders = $this->getCompletedOrdersInRange($range);

    $g1TotalSales = 0.0;
    $gstOnSales = 0.0;
    $gstOnPurchases = 0.0;
    $gstRefunds = 0.0;

    foreach ($orders as $order) {
      $orderData = $this->aggregateOrderData($order);
      $g1TotalSales += $orderData['total_sales'];
      $gstOnSales += $orderData['gst_collected'];
      $gstOnPurchases += $orderData['gst_on_purchases'];
      $gstRefunds += $orderData['gst_refunded'];
    }

    $netGst = $gstOnSales - $gstOnPurchases + $gstRefunds;

    return [
      'period' => $range->getFormattedRange(),
      'g1_total_sales' => round($g1TotalSales, 2),
      'gst_on_sales_1a' => round($gstOnSales, 2),
      'gst_on_purchases_1b' => round($gstOnPurchases, 2),
      'gst_refunds' => round($gstRefunds, 2),
      'net_gst' => round($netGst, 2),
    ];
  }

  /**
   * Gets vendor-specific BAS summary.
   *
   * @param int $vendorId
   *   The vendor entity ID.
   * @param \Drupal\myeventlane_finance\ValueObject\DateRange $range
   *   Date range for the report.
   *
   * @return array
   *   BAS summary array with same structure as getAdminBasSummary().
   *   Platform fees are excluded from vendor totals.
   */
  public function getVendorBasSummary(int $vendorId, DateRange $range): array {
    // Load vendor entity to verify it exists.
    $vendorStorage = $this->entityTypeManager->getStorage('myeventlane_vendor');
    $vendor = $vendorStorage->load($vendorId);

    if (!$vendor) {
      // Return empty summary if vendor doesn't exist.
      return $this->getEmptySummary($range);
    }

    // Get vendor's user ID (vendor owner).
    $vendorUserId = (int) $vendor->getOwnerId();
    if (!$vendorUserId) {
      return $this->getEmptySummary($range);
    }

    // Get all events owned by this vendor user.
    $eventIds = $this->getVendorEventIds($vendorUserId);

    if (empty($eventIds)) {
      return $this->getEmptySummary($range);
    }

    // Get orders for this vendor's events within date range.
    $orders = $this->getCompletedOrdersInRange($range, $eventIds);

    $g1TotalSales = 0.0;
    $gstOnSales = 0.0;
    $gstOnPurchases = 0.0;
    $gstRefunds = 0.0;

    foreach ($orders as $order) {
      // Only aggregate order items for this vendor's events.
      // Platform fees are excluded (they're not part of vendor sales).
      $orderData = $this->aggregateOrderDataForVendor($order, $eventIds);
      $g1TotalSales += $orderData['total_sales'];
      $gstOnSales += $orderData['gst_collected'];
      $gstOnPurchases += $orderData['gst_on_purchases'];
      $gstRefunds += $orderData['gst_refunded'];
    }

    $netGst = $gstOnSales - $gstOnPurchases + $gstRefunds;

    return [
      'period' => $range->getFormattedRange(),
      'g1_total_sales' => round($g1TotalSales, 2),
      'gst_on_sales_1a' => round($gstOnSales, 2),
      'gst_on_purchases_1b' => round($gstOnPurchases, 2),
      'gst_refunds' => round($gstRefunds, 2),
      'net_gst' => round($netGst, 2),
    ];
  }

  /**
   * Gets completed orders within date range.
   *
   * @param \Drupal\myeventlane_finance\ValueObject\DateRange $range
   *   Date range.
   * @param array|null $eventIds
   *   Optional array of event IDs to filter by.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface[]
   *   Array of order entities.
   */
  private function getCompletedOrdersInRange(DateRange $range, ?array $eventIds = NULL): array {
    $orderStorage = $this->entityTypeManager->getStorage('commerce_order');

    $query = $orderStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('state', 'completed')
      ->condition('completed', $range->getStartTimestamp(), '>=')
      ->condition('completed', $range->getEndTimestamp(), '<=');

    $orderIds = $query->execute();

    if (empty($orderIds)) {
      return [];
    }

    $orders = $orderStorage->loadMultiple($orderIds);

    // If event IDs filter is provided, filter orders by their order items.
    if ($eventIds !== NULL) {
      $filteredOrders = [];
      foreach ($orders as $order) {
        if ($this->orderHasEventItems($order, $eventIds)) {
          $filteredOrders[] = $order;
        }
      }
      return $filteredOrders;
    }

    return $orders;
  }

  /**
   * Checks if order has items for specified events.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param array $eventIds
   *   Array of event IDs.
   *
   * @return bool
   *   TRUE if order has items for any of the events.
   */
  private function orderHasEventItems(OrderInterface $order, array $eventIds): bool {
    foreach ($order->getItems() as $item) {
      $eventId = $this->getEventIdFromOrderItem($item);
      if ($eventId && in_array($eventId, $eventIds, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Gets event ID from order item.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $item
   *   The order item.
   *
   * @return int|null
   *   Event ID or NULL if not found.
   */
  private function getEventIdFromOrderItem(OrderItemInterface $item): ?int {
    // Try field_target_event first.
    if ($item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
      return (int) $item->get('field_target_event')->target_id;
    }

    // Fallback: get from purchased entity (variation).
    $purchasedEntity = $item->getPurchasedEntity();
    if ($purchasedEntity && $purchasedEntity->hasField('field_event') && !$purchasedEntity->get('field_event')->isEmpty()) {
      return (int) $purchasedEntity->get('field_event')->target_id;
    }

    return NULL;
  }

  /**
   * Aggregates GST data from an order (platform-wide).
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array
   *   Array with total_sales, gst_collected, gst_on_purchases, gst_refunded.
   */
  private function aggregateOrderData(OrderInterface $order): array {
    $totalSales = 0.0;
    $gstCollected = 0.0;
    $gstOnPurchases = 0.0;
    $gstRefunded = 0.0;

    // Get order-level tax adjustments.
    $orderTaxAdjustments = $order->getAdjustments(['tax']);
    foreach ($orderTaxAdjustments as $adjustment) {
      $amount = (float) $adjustment->getAmount()->getNumber();
      if ($amount > 0) {
        $gstCollected += $amount;
      }
      else {
        $gstRefunded += abs($amount);
      }
    }

    // Get order item totals and tax adjustments.
    foreach ($order->getItems() as $item) {
      $itemTotalPrice = $item->getTotalPrice();
      if ($itemTotalPrice) {
        $totalSales += (float) $itemTotalPrice->getNumber();
      }

      // Get item-level tax adjustments.
      $itemTaxAdjustments = $item->getAdjustments(['tax']);
      foreach ($itemTaxAdjustments as $adjustment) {
        $amount = (float) $adjustment->getAmount()->getNumber();
        if ($amount > 0) {
          $gstCollected += $amount;
        }
        else {
          $gstRefunded += abs($amount);
        }
      }
    }

    // GST on purchases would typically come from platform expenses.
    // For now, return 0 as we're only reading existing data.
    // This would need to be populated from purchase records if they exist.
    return [
      'total_sales' => $totalSales,
      'gst_collected' => $gstCollected,
      'gst_on_purchases' => $gstOnPurchases,
      'gst_refunded' => $gstRefunded,
    ];
  }

  /**
   * Aggregates GST data from an order for a specific vendor.
   *
   * Only includes order items for the vendor's events.
   * Platform fees are excluded.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param array $eventIds
   *   Array of vendor's event IDs.
   *
   * @return array
   *   Array with total_sales, gst_collected, gst_on_purchases, gst_refunded.
   */
  private function aggregateOrderDataForVendor(OrderInterface $order, array $eventIds): array {
    $totalSales = 0.0;
    $gstCollected = 0.0;
    $gstOnPurchases = 0.0;
    $gstRefunded = 0.0;

    // Only process order items for this vendor's events.
    foreach ($order->getItems() as $item) {
      $eventId = $this->getEventIdFromOrderItem($item);
      if (!$eventId || !in_array($eventId, $eventIds, TRUE)) {
        // Skip items not for this vendor's events.
        continue;
      }

      $itemTotalPrice = $item->getTotalPrice();
      if ($itemTotalPrice) {
        $totalSales += (float) $itemTotalPrice->getNumber();
      }

      // Get item-level tax adjustments.
      $itemTaxAdjustments = $item->getAdjustments(['tax']);
      foreach ($itemTaxAdjustments as $adjustment) {
        $amount = (float) $adjustment->getAmount()->getNumber();
        if ($amount > 0) {
          $gstCollected += $amount;
        }
        else {
          $gstRefunded += abs($amount);
        }
      }
    }

    // GST on purchases would typically come from vendor expenses.
    // For now, return 0 as we're only reading existing data.
    return [
      'total_sales' => $totalSales,
      'gst_collected' => $gstCollected,
      'gst_on_purchases' => $gstOnPurchases,
      'gst_refunded' => $gstRefunded,
    ];
  }

  /**
   * Gets event IDs owned by a vendor user.
   *
   * @param int $vendorUserId
   *   The vendor user ID.
   *
   * @return array
   *   Array of event node IDs.
   */
  private function getVendorEventIds(int $vendorUserId): array {
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $query = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('uid', $vendorUserId)
      ->condition('status', 1);

    $eventIds = $query->execute();

    return !empty($eventIds) ? array_map('intval', $eventIds) : [];
  }

  /**
   * Returns empty BAS summary structure.
   *
   * @param \Drupal\myeventlane_finance\ValueObject\DateRange $range
   *   Date range.
   *
   * @return array
   *   Empty summary array.
   */
  private function getEmptySummary(DateRange $range): array {
    return [
      'period' => $range->getFormattedRange(),
      'g1_total_sales' => 0.0,
      'gst_on_sales_1a' => 0.0,
      'gst_on_purchases_1b' => 0.0,
      'gst_refunds' => 0.0,
      'net_gst' => 0.0,
    ];
  }

}
