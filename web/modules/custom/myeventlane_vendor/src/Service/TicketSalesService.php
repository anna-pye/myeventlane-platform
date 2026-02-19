<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_analytics\Service\OrderItemClassifier;
use Drupal\node\NodeInterface;

/**
 * Ticket sales data provider for vendor console.
 *
 * Queries real Commerce order data for accurate sales metrics.
 * Excludes Boost and donation order items (platform revenue only).
 */
final class TicketSalesService {

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly OrderItemClassifier $orderItemClassifier,
  ) {}

  /**
   * Returns a sales summary for an event.
   *
   * Counts: Completed orders only (order state='completed').
   * Excludes: Draft/cancelled/refunded orders.
   * Excludes: Boost and donation order items.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Sales summary with keys: gross, net, fees (formatted strings),
   *   gross_raw, net_raw (floats), currency, tickets_sold (int),
   *   tickets_available (int|string), conversion (float).
   */
  public function getSalesSummary(NodeInterface $event): array {
    $eventId = (int) $event->id();
    $gross = 0.0;
    $ticketsSold = 0;

    try {
      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
      $orderItems = $orderItemStorage->loadByProperties([
        'field_target_event' => $eventId,
      ]);

      foreach ($orderItems as $item) {
        if (!$item instanceof OrderItemInterface) {
          continue;
        }

        if (!$this->orderItemClassifier->isVendorRevenueEligible($item)) {
          continue;
        }

        if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
          continue;
        }

        $order_id = $item->get('order_id')->target_id;
        if (!$order_id) {
          continue;
        }

        try {
          $order = $this->entityTypeManager
            ->getStorage('commerce_order')
            ->load($order_id);
          if ($order && $order->getState()->getId() === 'completed') {
            $totalPrice = $item->getTotalPrice();
            if ($totalPrice) {
              $gross += (float) $totalPrice->getNumber();
            }
            $ticketsSold += (int) $item->getQuantity();
          }
        }
        catch (\Exception) {
          continue;
        }
      }
    }
    catch (\Exception) {
      // Commerce module may not be available.
    }

    $ticketsAvailable = $this->getTicketsAvailable($event);
    $feeRate = 0.05;
    $fees = $gross * $feeRate;
    $net = $gross - $fees;

    $conversion = 0;
    if (is_numeric($ticketsAvailable) && (int) $ticketsAvailable > 0) {
      $conversion = $ticketsSold / (int) $ticketsAvailable;
    }

    return [
      'gross' => '$' . number_format($gross, 2),
      'net' => '$' . number_format($net, 2),
      'fees' => '$' . number_format($fees, 2),
      'gross_raw' => $gross,
      'net_raw' => $net,
      'currency' => 'USD',
      'tickets_sold' => $ticketsSold,
      'tickets_available' => $ticketsAvailable,
      'conversion' => $conversion,
    ];
  }

  /**
   * Returns ticket type breakdown for an event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Array of ticket types with label, price, sold, available, revenue.
   */
  public function getTicketBreakdown(NodeInterface $event): array {
    $eventId = (int) $event->id();
    $breakdown = [];

    if (!$event->hasField('field_product_target') || $event->get('field_product_target')->isEmpty()) {
      return [];
    }

    $product = $event->get('field_product_target')->entity;
    if (!$product) {
      return [];
    }

    try {
      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
      $variationStorage = $this->entityTypeManager->getStorage('commerce_product_variation');
      $orderStorage = $this->entityTypeManager->getStorage('commerce_order');

      $orderItems = $orderItemStorage->loadByProperties([
        'field_target_event' => $eventId,
      ]);

      $variationAggregates = [];

      foreach ($orderItems as $item) {
        if (!$item instanceof OrderItemInterface) {
          continue;
        }

        if (!$this->orderItemClassifier->isVendorRevenueEligible($item)) {
          continue;
        }

        $purchasedEntity = $item->getPurchasedEntity();
        if (!$purchasedEntity) {
          continue;
        }

        $variationId = $purchasedEntity->id();

        if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
          continue;
        }

        $order_id = $item->get('order_id')->target_id;
        if (!$order_id) {
          continue;
        }

        try {
          $order = $orderStorage->load($order_id);
          if (!$order || $order->getState()->getId() !== 'completed') {
            continue;
          }
        }
        catch (\Exception) {
          continue;
        }

        if (!isset($variationAggregates[$variationId])) {
          $variation = $variationStorage->load($variationId);
          if (!$variation) {
            continue;
          }

          if ($variation->bundle() === 'boost_duration') {
            continue;
          }

          $variationProduct = $variation->getProduct();
          if (!$variationProduct || $variationProduct->id() !== $product->id()) {
            continue;
          }

          $variationTitle = '';
          if ($variation->hasField('title') && !$variation->get('title')->isEmpty()) {
            $variationTitle = $variation->get('title')->value;
          }
          if (empty($variationTitle)) {
            $variationTitle = $variation->label();
          }

          $price = $variation->getPrice();
          $priceNumber = $price ? (float) $price->getNumber() : 0.0;

          $stock = 'Unlimited';
          if ($variation->hasField('field_stock') && !$variation->get('field_stock')->isEmpty()) {
            $stock = (int) $variation->get('field_stock')->value;
          }

          $variationAggregates[$variationId] = [
            'variation_id' => $variationId,
            'title' => $variationTitle,
            'price' => $priceNumber,
            'stock' => $stock,
            'sold' => 0,
            'revenue' => 0.0,
          ];
        }

        $variationAggregates[$variationId]['sold'] += (int) $item->getQuantity();
        $totalPrice = $item->getTotalPrice();
        if ($totalPrice) {
          $variationAggregates[$variationId]['revenue'] += (float) $totalPrice->getNumber();
        }
      }

      foreach ($variationAggregates as $data) {
        $available = is_int($data['stock']) ? max(0, $data['stock'] - $data['sold']) : $data['stock'];

        $breakdown[] = [
          'label' => $data['title'],
          'price' => '$' . number_format($data['price'], 2),
          'sold' => $data['sold'],
          'available' => $available,
          'revenue' => '$' . number_format($data['revenue'], 2),
          'revenue_raw' => $data['revenue'],
        ];
      }
    }
    catch (\Exception) {
      // Commerce may not be available.
    }

    return $breakdown;
  }

  /**
   * Returns daily sales series for charts (last 14 days).
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Array of daily sales data with date, amount, tickets.
   */
  public function getDailySalesSeries(NodeInterface $event): array {
    $eventId = (int) $event->id();
    $series = [];

    $days = [];
    for ($i = 13; $i >= 0; $i--) {
      $date = date('Y-m-d', strtotime("-{$i} days"));
      $days[$date] = ['amount' => 0.0, 'tickets' => 0];
    }

    try {
      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
      $orderItems = $orderItemStorage->loadByProperties([
        'field_target_event' => $eventId,
      ]);

      foreach ($orderItems as $item) {
        if (!$item instanceof OrderItemInterface) {
          continue;
        }

        if (!$this->orderItemClassifier->isVendorRevenueEligible($item)) {
          continue;
        }

        if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
          continue;
        }

        $order_id = $item->get('order_id')->target_id;
        if (!$order_id) {
          continue;
        }

        try {
          $order = $this->entityTypeManager
            ->getStorage('commerce_order')
            ->load($order_id);
          if ($order && $order->getState()->getId() === 'completed') {
            $completedTime = $order->getCompletedTime() ?? $order->getChangedTime();
            $date = date('Y-m-d', (int) $completedTime);

            if (isset($days[$date])) {
              $totalPrice = $item->getTotalPrice();
              if ($totalPrice) {
                $days[$date]['amount'] += (float) $totalPrice->getNumber();
              }
              $days[$date]['tickets'] += (int) $item->getQuantity();
            }
          }
        }
        catch (\Exception) {
          continue;
        }
      }
    }
    catch (\Exception) {
      // Commerce may not be available.
    }

    foreach ($days as $date => $data) {
      $series[] = [
        'date' => date('M j', strtotime($date)),
        'amount' => $data['amount'],
        'tickets' => $data['tickets'],
      ];
    }

    return $series;
  }

  /**
   * Gets total available tickets for an event.
   */
  private function getTicketsAvailable(NodeInterface $event): int|string {
    if (!$event->hasField('field_product_target') || $event->get('field_product_target')->isEmpty()) {
      return 0;
    }

    $product = $event->get('field_product_target')->entity;
    if (!$product) {
      return 0;
    }

    try {
      $variations = $product->getVariations();
      $total = 0;
      $hasUnlimited = FALSE;

      foreach ($variations as $variation) {
        if ($variation->bundle() === 'boost_duration') {
          continue;
        }

        if ($variation->hasField('field_stock') && !$variation->get('field_stock')->isEmpty()) {
          $total += (int) $variation->get('field_stock')->value;
        }
        else {
          $hasUnlimited = TRUE;
        }
      }

      return $hasUnlimited ? 'Unlimited' : $total;
    }
    catch (\Exception) {
      return 0;
    }
  }

  /**
   * Gets total revenue for a vendor (all published events).
   *
   * @param int $userId
   *   The vendor user ID.
   *
   * @return array
   *   Revenue summary with gross, net, fees, gross_raw, tickets.
   */
  public function getVendorRevenue(int $userId): array {
    if ($userId <= 0) {
      return [
        'gross' => '$0.00',
        'net' => '$0.00',
        'fees' => '$0.00',
        'gross_raw' => 0.0,
        'tickets' => 0,
      ];
    }

    $totalGross = 0.0;
    $totalTickets = 0;

    try {
      $nodeStorage = $this->entityTypeManager->getStorage('node');
      $eventIds = $nodeStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'event')
        ->condition('uid', $userId)
        ->condition('status', 1)
        ->execute();

      if (empty($eventIds)) {
        return [
          'gross' => '$0.00',
          'net' => '$0.00',
          'fees' => '$0.00',
          'gross_raw' => 0.0,
          'tickets' => 0,
        ];
      }

      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
      $orderItems = $orderItemStorage->loadByProperties([
        'field_target_event' => array_values($eventIds),
      ]);

      foreach ($orderItems as $item) {
        if (!$item instanceof OrderItemInterface) {
          continue;
        }

        if (!$this->orderItemClassifier->isVendorRevenueEligible($item)) {
          continue;
        }

        if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
          continue;
        }

        $order_id = $item->get('order_id')->target_id;
        if (!$order_id) {
          continue;
        }

        try {
          $order = $this->entityTypeManager
            ->getStorage('commerce_order')
            ->load($order_id);
          if ($order && $order->getState()->getId() === 'completed') {
            $totalPrice = $item->getTotalPrice();
            if ($totalPrice) {
              $totalGross += (float) $totalPrice->getNumber();
            }
            $totalTickets += (int) $item->getQuantity();
          }
        }
        catch (\Exception) {
          continue;
        }
      }
    }
    catch (\Exception) {
      // Commerce may not be available.
    }

    $feeRate = 0.05;
    $fees = $totalGross * $feeRate;
    $net = $totalGross - $fees;

    return [
      'gross' => '$' . number_format($totalGross, 2),
      'net' => '$' . number_format($net, 2),
      'fees' => '$' . number_format($fees, 2),
      'gross_raw' => $totalGross,
      'tickets' => $totalTickets,
    ];
  }

  /**
   * Gets total order count for a vendor (all published events).
   *
   * @param int $userId
   *   The vendor user ID.
   *
   * @return int
   *   Total order count.
   */
  public function getVendorOrderCount(int $userId): int {
    if ($userId <= 0) {
      return 0;
    }

    try {
      $nodeStorage = $this->entityTypeManager->getStorage('node');
      $eventIds = $nodeStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'event')
        ->condition('uid', $userId)
        ->condition('status', 1)
        ->execute();

      if (empty($eventIds)) {
        return 0;
      }

      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
      $orderItems = $orderItemStorage->loadByProperties([
        'field_target_event' => array_values($eventIds),
      ]);

      $processedOrders = [];
      foreach ($orderItems as $item) {
        if (!$item instanceof OrderItemInterface) {
          continue;
        }

        if (!$this->orderItemClassifier->isVendorRevenueEligible($item)) {
          continue;
        }

        if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
          continue;
        }

        $order_id = $item->get('order_id')->target_id;
        if (!$order_id) {
          continue;
        }

        try {
          $order = $this->entityTypeManager
            ->getStorage('commerce_order')
            ->load($order_id);
          if ($order && $order->getState()->getId() === 'completed') {
            $orderId = $order->id();
            if (!isset($processedOrders[$orderId])) {
              $processedOrders[$orderId] = TRUE;
            }
          }
        }
        catch (\Exception) {
          continue;
        }
      }

      return count($processedOrders);
    }
    catch (\Exception) {
      return 0;
    }
  }

  /**
   * Gets revenue for a vendor within a time range.
   *
   * @param int $userId
   *   The vendor user ID.
   * @param int $startTimestamp
   *   Start timestamp (inclusive).
   * @param int|null $endTimestamp
   *   End timestamp (inclusive). NULL for no upper limit.
   *
   * @return float
   *   Total revenue amount.
   */
  public function getVendorRevenueInRange(int $userId, int $startTimestamp, ?int $endTimestamp = NULL): float {
    if ($userId <= 0) {
      return 0.0;
    }

    try {
      $nodeStorage = $this->entityTypeManager->getStorage('node');
      $eventIds = $nodeStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'event')
        ->condition('uid', $userId)
        ->condition('status', 1)
        ->execute();

      if (empty($eventIds)) {
        return 0.0;
      }

      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
      $orderItems = $orderItemStorage->loadByProperties([
        'field_target_event' => array_values($eventIds),
      ]);

      $totalRevenue = 0.0;
      foreach ($orderItems as $item) {
        if (!$item instanceof OrderItemInterface) {
          continue;
        }

        if (!$this->orderItemClassifier->isVendorRevenueEligible($item)) {
          continue;
        }

        if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
          continue;
        }

        $order_id = $item->get('order_id')->target_id;
        if (!$order_id) {
          continue;
        }

        try {
          $order = $this->entityTypeManager
            ->getStorage('commerce_order')
            ->load($order_id);
          if ($order && $order->getState()->getId() === 'completed') {
            $orderTime = $order->getCompletedTime() ?? $order->getChangedTime();
            if ($orderTime >= $startTimestamp && ($endTimestamp === NULL || $orderTime <= $endTimestamp)) {
              $totalPrice = $item->getTotalPrice();
              if ($totalPrice) {
                $totalRevenue += (float) $totalPrice->getNumber();
              }
            }
          }
        }
        catch (\Exception) {
          continue;
        }
      }

      return $totalRevenue;
    }
    catch (\Exception) {
      return 0.0;
    }
  }

}
