<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Ticket sales data provider for vendor console.
 */
final class TicketSalesService {

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns a lightweight sales summary for an event.
   */
  public function getSalesSummary(NodeInterface $event): array {
    // Placeholder implementation; swap with commerce queries as data is ready.
    return [
      'gross' => 12430.00,
      'net' => 11840.00,
      'fees' => 590.00,
      'currency' => 'USD',
      'tickets_sold' => 684,
      'tickets_available' => 1200,
      'conversion' => 0.042,
    ];
  }

  /**
   * Returns ticket type breakdown for an event.
   */
  public function getTicketBreakdown(NodeInterface $event): array {
    // Replace with commerce_product or price list query.
    return [
      [
        'label' => 'General Admission',
        'sold' => 420,
        'available' => 800,
        'revenue' => 8200.00,
      ],
      [
        'label' => 'VIP',
        'sold' => 64,
        'available' => 120,
        'revenue' => 3240.00,
      ],
    ];
  }

  /**
   * Returns daily sales series for charts.
   */
  public function getDailySalesSeries(NodeInterface $event): array {
    $series = [];
    $day = new \DateTimeImmutable('-14 days');
    for ($i = 0; $i < 14; $i++) {
      $series[] = [
        'date' => $day->format('Y-m-d'),
        'amount' => 400 + ($i * 25),
        'tickets' => 12 + ($i % 4),
      ];
      // Move to next day for next iteration.
      $day = $day->modify('+1 day');
    }
    return $series;
  }

}
