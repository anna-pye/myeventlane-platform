<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\myeventlane_boost\Service\BoostMetricsService;
use Drupal\myeventlane_metrics\Service\EventMetricsServiceInterface;
use Drupal\node\NodeInterface;

/**
 * Aggregates metrics across ticket sales, RSVPs, audience, and boost.
 *
 * This service ONLY orchestrates calls to other services.
 * It does NOT perform calculations or queries directly.
 * All metrics are sourced from specialized services:
 * - TicketSalesService: Revenue, tickets sold, order counts
 * - RsvpStatsService: RSVP counts and summaries
 * - EventMetricsService: Attendee counts, capacity, check-ins
 * - BoostStatusService: Boost eligibility and status
 * - BoostMetricsService: Boost performance metrics (impressions, clicks, spend)
 * - CategoryAudienceService: Geographic audience breakdown.
 *
 * Handles unpublished events gracefully by returning safe defaults.
 */
final class MetricsAggregator {

  /**
   * Constructs the aggregator.
   */
  public function __construct(
    private readonly TicketSalesService $ticketSalesService,
    private readonly RsvpStatsService $rsvpStatsService,
    private readonly CategoryAudienceService $categoryAudienceService,
    private readonly BoostStatusService $boostStatusService,
    private readonly EventMetricsServiceInterface $eventMetricsService,
    private readonly BoostMetricsService $boostMetricsService,
  ) {}

  /**
   * Returns KPI cards for the vendor dashboard.
   *
   * Orchestrates calls to TicketSalesService and RsvpStatsService.
   * Metrics:
   * - Total Sales: Gross revenue from completed orders (published events only)
   * - RSVPs: Total confirmed RSVPs (published events only)
   * - Net Earnings: Gross revenue minus platform fees (published events only)
   * - Tickets Sold: Total tickets from completed orders (published events only)
   *
   * @param int $userId
   *   The vendor user ID.
   *
   * @return array
   *   Array of KPI card arrays, each with: label (string), value (string), delta (array|null).
   *   Returns empty array if userId is invalid. Always returns safe default values (0/$0.00).
   */
  public function getVendorKpis(int $userId): array {
    if ($userId <= 0) {
      return [];
    }

    // Get revenue data from TicketSalesService (includes published events filter).
    $revenue = $this->ticketSalesService->getVendorRevenue($userId);
    // Get RSVP count from RsvpStatsService (includes published events filter).
    $rsvpCount = $this->rsvpStatsService->getVendorRsvpCount($userId);

    return [
      [
        'label' => 'Total Sales',
        'value' => $revenue['gross'] ?? '$0.00',
        'delta' => NULL,
      ],
      [
        'label' => 'RSVPs',
        'value' => (string) $rsvpCount,
        'delta' => NULL,
      ],
      [
        'label' => 'Net Earnings',
        'value' => $revenue['net'] ?? '$0.00',
        'delta' => NULL,
      ],
      [
        'label' => 'Tickets Sold',
        'value' => (string) ($revenue['tickets'] ?? 0),
        'delta' => NULL,
      ],
    ];
  }

  /**
   * Returns an event overview block using EventMetricsService and other services.
   *
   * Orchestrates calls to multiple services to build complete event metrics.
   * Metrics returned:
   * - attendees: Total and checked-in counts, check-in rate (from EventMetricsService)
   * - capacity: Total, remaining, sold-out status (from EventMetricsService)
   * - revenue: Total revenue Price object (from EventMetricsService)
   * - sales: Sales summary with gross/net/fees/tickets (from TicketSalesService)
   * - rsvps: RSVP count (from RsvpStatsService)
   * - audience: Geographic breakdown (from CategoryAudienceService)
   * - boost: Boost eligibility and status (from BoostStatusService)
   * - tickets: Ticket type breakdown (from EventMetricsService)
   *
   * Handles unpublished events gracefully by returning zero/empty values.
   * All metrics exclude cancelled/refunded orders and non-confirmed RSVPs.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Overview data array with keys: attendees, capacity, revenue, sales, rsvps,
   *   audience, boost, tickets. Structure is stable and predictable.
   */
  public function getEventOverview(NodeInterface $event): array {
    $event_id = (int) $event->id();
    $isPublished = $event->isPublished();

    // For unpublished events, return safe defaults (no calculations needed).
    if (!$isPublished) {
      return [
        'attendees' => [
          'total' => 0,
          'checked_in' => 0,
          'check_in_rate' => 0.0,
        ],
        'capacity' => [
          'total' => 0,
          'remaining' => 0,
          'sold_out' => FALSE,
        ],
        'revenue' => NULL,
        'sales' => [],
        'rsvps' => [
          'count' => 0,
        ],
        'audience' => [],
        'boost' => [
          'eligible' => FALSE,
          'active' => FALSE,
          'reason' => 'unpublished',
          'types' => [],
          'expires' => NULL,
        ],
        'tickets' => [],
      ];
    }

    // Orchestrate calls to EventMetricsService for core metrics (published events only).
    try {
      $attendeeCount = $this->eventMetricsService->getAttendeeCount($event);
      $checkedInCount = $this->eventMetricsService->getCheckedInCount($event);
      $remainingCapacity = $this->eventMetricsService->getRemainingCapacity($event);
      $isSoldOut = $this->eventMetricsService->isSoldOut($event);
      $revenue = $this->eventMetricsService->getRevenue($event);
      $checkInRate = $this->eventMetricsService->getCheckInRate($event);
      $ticketBreakdown = $this->eventMetricsService->getTicketBreakdown($event);
    }
    catch (\Exception $e) {
      // If EventMetricsService fails, return safe defaults (no calculations here).
      $attendeeCount = 0;
      $checkedInCount = 0;
      $remainingCapacity = 0;
      $isSoldOut = FALSE;
      $revenue = NULL;
      $checkInRate = 0.0;
      $ticketBreakdown = [];
    }

    // Orchestrate call to TicketSalesService for sales summary.
    try {
      $salesSummary = $this->ticketSalesService->getSalesSummary($event);
    }
    catch (\Exception $e) {
      $salesSummary = [];
    }

    // Orchestrate call to RsvpStatsService for RSVP summary.
    try {
      $rsvpSummary = $this->rsvpStatsService->getRsvpSummary($event_id);
    }
    catch (\Exception $e) {
      $rsvpSummary = ['count' => 0];
    }

    // Orchestrate call to BoostStatusService for boost status.
    try {
      $boost = $this->boostStatusService->getBoostStatuses($event_id);
    }
    catch (\Exception $e) {
      $boost = [
        'eligible' => FALSE,
        'active' => FALSE,
        'reason' => 'error',
        'types' => [],
        'expires' => NULL,
      ];
    }

    // Orchestrate call to BoostMetricsService for boost performance metrics.
    try {
      $boostMetrics = $this->boostMetricsService->getEventBoostMetrics($event);
    }
    catch (\Exception $e) {
      $boostMetrics = [
        'spend' => '$0.00',
        'impressions' => 0,
        'clicks' => 0,
        'ctr' => 0.0,
        'cost_per_click' => NULL,
        'sales_during_period' => NULL,
        'placements' => [],
      ];
    }

    // Orchestrate call to CategoryAudienceService for audience data.
    try {
      $audience = $this->categoryAudienceService->getGeoBreakdown($event);
    }
    catch (\Exception $e) {
      $audience = [];
    }

    // Orchestrate call to EventMetricsService for capacity total.
    try {
      $capacityTotal = $this->eventMetricsService->getCapacityTotal($event);
    }
    catch (\Exception $e) {
      $capacityTotal = 0;
    }

    return [
      'attendees' => [
        'total' => $attendeeCount,
        'checked_in' => $checkedInCount,
        'check_in_rate' => $checkInRate,
      ],
      'capacity' => [
        'total' => $capacityTotal,
        'remaining' => $remainingCapacity,
        'sold_out' => $isSoldOut,
      ],
      'revenue' => $revenue ? [
        'amount' => $revenue->getNumber(),
        'currency' => $revenue->getCurrencyCode(),
      ] : NULL,
      'sales' => $salesSummary,
      'rsvps' => $rsvpSummary,
      'audience' => $audience,
      'boost' => $boost,
      'boost_metrics' => $boostMetrics,
      'tickets' => $ticketBreakdown,
    ];
  }

  /**
   * Returns chart data for an event.
   *
   * Orchestrates calls to TicketSalesService for daily sales series.
   * Metrics:
   * - sales: Daily sales data for last 14 days (from TicketSalesService)
   * - rsvps: Empty array (RSVP daily series not implemented)
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Chart data array with keys: sales (array), rsvps (empty array).
   *   Returns empty arrays on error.
   */
  public function getEventCharts(NodeInterface $event): array {
    // Orchestrate call to TicketSalesService for daily sales series.
    try {
      $sales = $this->ticketSalesService->getDailySalesSeries($event);
    }
    catch (\Exception $e) {
      $sales = [];
    }

    // RSVP daily series not implemented - RsvpStatsService does not provide this.
    return [
      'sales' => $sales,
      'rsvps' => [],
    ];
  }

}
