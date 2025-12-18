<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Sales analytics service.
 *
 * Provides sales-specific analytics and insights.
 */
final class SalesAnalyticsService {

  /**
   * Constructs SalesAnalyticsService.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AnalyticsDataService $dataService,
  ) {}

  /**
   * Gets sales velocity metrics for an event.
   *
   * @param int $eventId
   *   The event node ID.
   *
   * @return array
   *   Array with velocity metrics.
   */
  public function getSalesVelocity(int $eventId): array {
    $timeSeries = $this->dataService->getSalesTimeSeries($eventId, 'day');

    if (empty($timeSeries)) {
      return [
        'average_per_day' => 0,
        'peak_day' => NULL,
        'peak_revenue' => 0.0,
        'trend' => 'stable',
      ];
    }

    $totalTickets = 0;
    $totalRevenue = 0.0;
    $peakDay = NULL;
    $peakRevenue = 0.0;
    $days = count($timeSeries);

    foreach ($timeSeries as $point) {
      $totalTickets += $point['ticket_count'];
      $totalRevenue += $point['revenue'];

      if ($point['revenue'] > $peakRevenue) {
        $peakRevenue = $point['revenue'];
        $peakDay = $point['date'];
      }
    }

    $averagePerDay = $days > 0 ? round($totalTickets / $days, 1) : 0;

    // Calculate trend (simple: compare last 3 days to previous 3 days).
    $trend = 'stable';
    if (count($timeSeries) >= 6) {
      $recent = array_slice($timeSeries, -3);
      $previous = array_slice($timeSeries, -6, 3);

      $recentAvg = 0;
      $previousAvg = 0;

      foreach ($recent as $point) {
        $recentAvg += $point['ticket_count'];
      }
      foreach ($previous as $point) {
        $previousAvg += $point['ticket_count'];
      }

      $recentAvg = $recentAvg / 3;
      $previousAvg = $previousAvg / 3;

      if ($recentAvg > $previousAvg * 1.1) {
        $trend = 'increasing';
      }
      elseif ($recentAvg < $previousAvg * 0.9) {
        $trend = 'decreasing';
      }
    }

    return [
      'average_per_day' => $averagePerDay,
      'peak_day' => $peakDay,
      'peak_revenue' => $peakRevenue,
      'trend' => $trend,
      'total_tickets' => $totalTickets,
      'total_revenue' => $totalRevenue,
    ];
  }

  /**
   * Gets peak sales periods for an event.
   *
   * @param int $eventId
   *   The event node ID.
   *
   * @return array
   *   Array of peak periods.
   */
  public function getPeakSalesPeriods(int $eventId): array {
    $timeSeries = $this->dataService->getSalesTimeSeries($eventId, 'day');

    if (empty($timeSeries)) {
      return [];
    }

    // Sort by revenue descending.
    usort($timeSeries, function ($a, $b) {
      return $b['revenue'] <=> $a['revenue'];
    });

    // Return top 5 peak days.
    return array_slice($timeSeries, 0, 5);
  }

}
