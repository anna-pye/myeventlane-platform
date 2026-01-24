<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Ticket sales data provider for vendor console.
 *
 * Queries real Commerce order data for accurate sales metrics.
 * Excludes Boost purchases (admin revenue only).
 */
final class TicketSalesService {

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Checks if an order item is a Boost purchase.
   *
   * Boost purchases are admin revenue and must be excluded from vendor metrics.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $item
   *   The order item.
   *
   * @return bool
   *   TRUE if the item is a Boost purchase, FALSE otherwise.
   */
  private function isBoostItem(OrderItemInterface $item): bool {
    // Boost order items have bundle 'boost'.
    if ($item->bundle() === 'boost') {
      return TRUE;
    }

    // Also check the purchased entity's product/variation type.
    $purchasedEntity = $item->getPurchasedEntity();
    if ($purchasedEntity) {
      $product = $purchasedEntity->getProduct();
      if ($product && $product->bundle() === 'boost_upgrade') {
        return TRUE;
      }
      if ($purchasedEntity->bundle() === 'boost_duration') {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Returns a sales summary for an event.
   *
   * Counts: Completed orders only (order state='completed').
   * Excludes: Draft/cancelled/refunded orders.
   * Tables: commerce_order_item, commerce_order.
   * NOTE: Does not check if event is published - caller should filter drafts.
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

        // Exclude Boost purchases (admin revenue only).
        if ($this->isBoostItem($item)) {
          continue;
        }

        if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
          continue;
        }

        // Safely load the order entity to avoid getOrder() warnings.
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

    // Calculate tickets available from product variations.
    $ticketsAvailable = $this->getTicketsAvailable($event);

    // Platform fee rate: 5% total fees (2.9% + $0.30 Stripe + platform fee).
    // NOTE: This is hardcoded - should be configurable in production.
    $feeRate = 0.05;
    $fees = $gross * $feeRate;
    $net = $gross - $fees;

    // Calculate conversion (tickets sold / available).
    // Handle 'Unlimited' string or zero cases - conversion is 0 if unlimited or no tickets.
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
   * Aggregates by product variation from completed order items.
   * Groups by: oi.purchased_entity (variation ID)
   * Sums: oi.quantity, oi.total_price__number
   * Filters: o.state = 'completed', field_target_event = eventId.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Array of ticket types, each with: label (variation title), price (formatted),
   *   sold (int), available (int|string), revenue (formatted), revenue_raw (float).
   *   Returns empty array if no product or on error.
   */
  public function getTicketBreakdown(NodeInterface $event): array {
    $eventId = (int) $event->id();
    $breakdown = [];

    // Get the linked product for variation metadata (price, stock, title).
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

      // Query order items for this event (base query).
      $orderItems = $orderItemStorage->loadByProperties([
        'field_target_event' => $eventId,
      ]);

      // Group by variation ID and aggregate.
      // Key: variation_id, Value: ['sold' => int, 'revenue' => float, 'variation' => entity].
      $variationAggregates = [];

      foreach ($orderItems as $item) {
        if (!$item instanceof OrderItemInterface) {
          continue;
        }

        // Exclude Boost purchases (admin revenue only).
        if ($this->isBoostItem($item)) {
          continue;
        }

        // Get purchased entity (variation).
        $purchasedEntity = $item->getPurchasedEntity();
        if (!$purchasedEntity) {
          continue;
        }

        $variationId = $purchasedEntity->id();

        // Verify order is completed.
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

        // Initialize variation aggregate if not seen.
        if (!isset($variationAggregates[$variationId])) {
          // Load variation to get title, price, stock.
          $variation = $variationStorage->load($variationId);
          if (!$variation) {
            continue;
          }

          // Exclude Boost variations.
          if ($variation->bundle() === 'boost_duration') {
            continue;
          }

          // Verify variation belongs to the event's product.
          $variationProduct = $variation->getProduct();
          if (!$variationProduct || $variationProduct->id() !== $product->id()) {
            continue;
          }

          // Get variation title from title field directly.
          $variationTitle = '';
          if ($variation->hasField('title') && !$variation->get('title')->isEmpty()) {
            $variationTitle = $variation->get('title')->value;
          }

          // Fallback to label() if title field is empty.
          if (empty($variationTitle)) {
            $variationTitle = $variation->label();
          }

          $price = $variation->getPrice();
          $priceNumber = $price ? (float) $price->getNumber() : 0.0;

          // Get stock if available.
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

        // Aggregate: SUM(quantity), SUM(total_price__number).
        $variationAggregates[$variationId]['sold'] += (int) $item->getQuantity();
        $totalPrice = $item->getTotalPrice();
        if ($totalPrice) {
          $variationAggregates[$variationId]['revenue'] += (float) $totalPrice->getNumber();
        }
      }

      // Convert to final breakdown format.
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
   * Counts: Completed orders only (order state='completed').
   * Excludes: Draft/cancelled/refunded orders.
   * Time range: Last 14 days (based on order completion time).
   * Tables: commerce_order_item, commerce_order.
   * NOTE: Does not check if event is published - caller should filter drafts.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Array of daily sales data, each with: date (formatted), amount (float),
   *   tickets (int). Always returns 14 days (may have zero values).
   */
  public function getDailySalesSeries(NodeInterface $event): array {
    $eventId = (int) $event->id();
    $series = [];

    // Initialize last 14 days with zero values.
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

        // Exclude Boost purchases (admin revenue only).
        if ($this->isBoostItem($item)) {
          continue;
        }

        if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
          continue;
        }

        // Safely load the order entity to avoid getOrder() warnings.
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

    // Convert to series format.
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
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return int|string
   *   Total tickets available or 'Unlimited'.
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
        // Exclude Boost variations (admin revenue only).
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
   * Counts: Completed orders only (order state='completed').
   * Excludes: Draft events, cancelled/refunded orders.
   * Tables: commerce_order_item, commerce_order, node (event).
   * Fee rate: 5% (hardcoded, should be configurable).
   *
   * @param int $userId
   *   The vendor user ID.
   *
   * @return array
   *   Revenue summary with keys: gross, net, fees (formatted strings),
   *   gross_raw (float), tickets (int).
   *   Returns zeros if no events, invalid user, or on error.
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
      // Get all published events owned by this user.
      // NOTE: Only published events are included in vendor analytics.
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

        // Exclude Boost purchases (admin revenue only).
        if ($this->isBoostItem($item)) {
          continue;
        }

        if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
          continue;
        }

        // Safely load the order entity to avoid getOrder() warnings.
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

    // Platform fee rate: 5% total fees (hardcoded, should be configurable).
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
   * Counts: Completed orders only (order state='completed').
   * Excludes: Draft events, cancelled/refunded orders.
   * Tables: commerce_order_item, commerce_order, node (event).
   *
   * @param int $userId
   *   The vendor user ID.
   *
   * @return int
   *   Total order count. Returns 0 if no orders, invalid user, or on error.
   */
  public function getVendorOrderCount(int $userId): int {
    if ($userId <= 0) {
      return 0;
    }

    try {
      // Get all published events owned by this user.
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

        // Exclude Boost purchases (admin revenue only).
        if ($this->isBoostItem($item)) {
          continue;
        }

        if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
          continue;
        }

        // Safely load the order entity to avoid getOrder() warnings.
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
   * Counts: Completed orders only (order state='completed').
   * Excludes: Draft events, cancelled/refunded orders, orders outside time range.
   * Tables: commerce_order_item, commerce_order, node (event).
   *
   * @param int $userId
   *   The vendor user ID.
   * @param int $startTimestamp
   *   Start timestamp (inclusive).
   * @param int|null $endTimestamp
   *   End timestamp (inclusive). NULL for no upper limit.
   *
   * @return float
   *   Total revenue amount. Returns 0.0 if no revenue, invalid user, or on error.
   */
  public function getVendorRevenueInRange(int $userId, int $startTimestamp, ?int $endTimestamp = NULL): float {
    if ($userId <= 0) {
      return 0.0;
    }

    try {
      // Get all published events owned by this user.
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

        // Exclude Boost purchases (admin revenue only).
        if ($this->isBoostItem($item)) {
          continue;
        }

        if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
          continue;
        }

        // Safely load the order entity to avoid getOrder() warnings.
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
