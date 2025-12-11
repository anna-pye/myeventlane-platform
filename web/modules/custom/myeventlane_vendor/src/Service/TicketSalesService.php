<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Ticket sales data provider for vendor console.
 *
 * Queries real Commerce order data for accurate sales metrics.
 */
final class TicketSalesService {

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns a sales summary for an event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Sales summary with gross, net, fees, tickets_sold, tickets_available.
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
        if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
          continue;
        }

        try {
          $order = $item->getOrder();
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

    // Platform fee is typically 2.9% + $0.30 per transaction (Stripe) + platform fee.
    // For simplicity, estimate 5% total fees.
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
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Array of ticket types with sold, available, revenue.
   */
  public function getTicketBreakdown(NodeInterface $event): array {
    $eventId = (int) $event->id();
    $breakdown = [];

    // Get the linked product.
    if (!$event->hasField('field_product_target') || $event->get('field_product_target')->isEmpty()) {
      return [];
    }

    $product = $event->get('field_product_target')->entity;
    if (!$product) {
      return [];
    }

    try {
      $variations = $product->getVariations();
      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');

      foreach ($variations as $variation) {
        $variationId = $variation->id();
        $title = $variation->getTitle();
        $price = $variation->getPrice();
        $priceNumber = $price ? (float) $price->getNumber() : 0;

        // Get stock if available.
        $stock = 'Unlimited';
        if ($variation->hasField('field_stock') && !$variation->get('field_stock')->isEmpty()) {
          $stock = (int) $variation->get('field_stock')->value;
        }

        // Query order items for this variation.
        $sold = 0;
        $revenue = 0.0;

        $orderItems = $orderItemStorage->loadByProperties([
          'purchased_entity' => $variationId,
        ]);

        foreach ($orderItems as $item) {
          if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
            continue;
          }

          try {
            $order = $item->getOrder();
            if ($order && $order->getState()->getId() === 'completed') {
              $sold += (int) $item->getQuantity();
              $totalPrice = $item->getTotalPrice();
              if ($totalPrice) {
                $revenue += (float) $totalPrice->getNumber();
              }
            }
          }
          catch (\Exception) {
            continue;
          }
        }

        $available = is_int($stock) ? max(0, $stock - $sold) : $stock;

        $breakdown[] = [
          'label' => $title,
          'price' => '$' . number_format($priceNumber, 2),
          'sold' => $sold,
          'available' => $available,
          'revenue' => '$' . number_format($revenue, 2),
          'revenue_raw' => $revenue,
        ];
      }
    }
    catch (\Exception) {
      // Commerce may not be available.
    }

    return $breakdown;
  }

  /**
   * Returns daily sales series for charts.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Array of daily sales data for charting.
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
        if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
          continue;
        }

        try {
          $order = $item->getOrder();
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
   * Gets total revenue for a vendor (all events).
   *
   * @param int $userId
   *   The vendor user ID.
   *
   * @return array
   *   Revenue summary.
   */
  public function getVendorRevenue(int $userId): array {
    $totalGross = 0.0;
    $totalTickets = 0;

    try {
      // Get all events owned by this user.
      $nodeStorage = $this->entityTypeManager->getStorage('node');
      $eventIds = $nodeStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'event')
        ->condition('uid', $userId)
        ->execute();

      if (empty($eventIds)) {
        return [
          'gross' => '$0.00',
          'net' => '$0.00',
          'tickets' => 0,
        ];
      }

      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
      $orderItems = $orderItemStorage->loadByProperties([
        'field_target_event' => array_values($eventIds),
      ]);

      foreach ($orderItems as $item) {
        if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
          continue;
        }

        try {
          $order = $item->getOrder();
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

    $fees = $totalGross * 0.05;
    $net = $totalGross - $fees;

    return [
      'gross' => '$' . number_format($totalGross, 2),
      'net' => '$' . number_format($net, 2),
      'fees' => '$' . number_format($fees, 2),
      'gross_raw' => $totalGross,
      'tickets' => $totalTickets,
    ];
  }

}
