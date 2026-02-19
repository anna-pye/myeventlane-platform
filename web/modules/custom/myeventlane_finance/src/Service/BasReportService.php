<?php

declare(strict_types=1);

namespace Drupal\myeventlane_finance\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_analytics\Service\OrderItemClassifier;
use Drupal\myeventlane_finance\ValueObject\DateRange;

/**
 * BAS Report Service.
 *
 * Aggregates GST-relevant data for Business Activity Statement reporting.
 * Vendor BAS totals exclude donation and Boost order items (platform revenue).
 *
 * Donations and Boost are platform revenue and excluded from vendor BAS totals.
 */
final class BasReportService {

  /**
   * Constructs BasReportService.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly OrderItemClassifier $orderItemClassifier,
  ) {}

  /**
   * Gets platform-wide BAS summary for admin.
   *
   * @param \Drupal\myeventlane_finance\ValueObject\DateRange $range
   *   Date range for the report.
   *
   * @return array
   *   BAS summary array.
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
   * Donations and Boost are platform revenue; only vendor-revenue-eligible
   * order items are included.
   *
   * @param int $vendorId
   *   The vendor entity ID.
   * @param \Drupal\myeventlane_finance\ValueObject\DateRange $range
   *   Date range for the report.
   *
   * @return array
   *   BAS summary array.
   */
  public function getVendorBasSummary(int $vendorId, DateRange $range): array {
    $vendorStorage = $this->entityTypeManager->getStorage('myeventlane_vendor');
    $vendor = $vendorStorage->load($vendorId);

    if (!$vendor) {
      return $this->getEmptySummary($range);
    }

    $vendorUserId = (int) $vendor->getOwnerId();
    if (!$vendorUserId) {
      return $this->getEmptySummary($range);
    }

    $eventIds = $this->getVendorEventIds($vendorUserId);

    if (empty($eventIds)) {
      return $this->getEmptySummary($range);
    }

    $orders = $this->getCompletedOrdersInRange($range, $eventIds);

    $g1TotalSales = 0.0;
    $gstOnSales = 0.0;
    $gstOnPurchases = 0.0;
    $gstRefunds = 0.0;

    foreach ($orders as $order) {
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
   * @return \Drupal\commerce_order\Entity\OrderInterface[]
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
   */
  private function getEventIdFromOrderItem(OrderItemInterface $item): ?int {
    if ($item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
      return (int) $item->get('field_target_event')->target_id;
    }

    $purchasedEntity = $item->getPurchasedEntity();
    if ($purchasedEntity && $purchasedEntity->hasField('field_event') && !$purchasedEntity->get('field_event')->isEmpty()) {
      return (int) $purchasedEntity->get('field_event')->target_id;
    }

    return NULL;
  }

  /**
   * Aggregates GST data from an order (platform-wide).
   */
  private function aggregateOrderData(OrderInterface $order): array {
    $totalSales = 0.0;
    $gstCollected = 0.0;
    $gstOnPurchases = 0.0;
    $gstRefunded = 0.0;

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

    foreach ($order->getItems() as $item) {
      $itemTotalPrice = $item->getTotalPrice();
      if ($itemTotalPrice) {
        $totalSales += (float) $itemTotalPrice->getNumber();
      }

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
   * Only includes vendor-revenue-eligible order items (excludes donations
   * and Boost). Donations and Boost are platform revenue.
   */
  private function aggregateOrderDataForVendor(OrderInterface $order, array $eventIds): array {
    $totalSales = 0.0;
    $gstCollected = 0.0;
    $gstOnPurchases = 0.0;
    $gstRefunded = 0.0;

    foreach ($order->getItems() as $item) {
      $eventId = $this->getEventIdFromOrderItem($item);
      if (!$eventId || !in_array($eventId, $eventIds, TRUE)) {
        continue;
      }

      // Exclude donation and Boost order items (platform revenue only).
      if (!$this->orderItemClassifier->isVendorRevenueEligible($item)) {
        continue;
      }

      $itemTotalPrice = $item->getTotalPrice();
      if ($itemTotalPrice) {
        $totalSales += (float) $itemTotalPrice->getNumber();
      }

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
   * @return array<int>
   */
  private function getVendorEventIds(int $vendorUserId): array {
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $eventIds = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('uid', $vendorUserId)
      ->condition('status', 1)
      ->execute();

    return !empty($eventIds) ? array_map('intval', $eventIds) : [];
  }

  /**
   * Returns empty BAS summary structure.
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
