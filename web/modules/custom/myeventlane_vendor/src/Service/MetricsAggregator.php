<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\myeventlane_boost\Service\BoostMetricsService;
use Drupal\myeventlane_commerce\Service\OrderItemClassifier;
use Drupal\myeventlane_donations\Service\DonationService;
use Drupal\myeventlane_metrics\Service\EventMetricsServiceInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Aggregates metrics across ticket sales, RSVPs, donations, audience, and boost.
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
    private readonly OrderItemClassifier $orderItemClassifier,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ?DonationService $donationService = NULL,
  ) {}

  /**
   * Returns KPI cards for the vendor dashboard.
   *
   * Orchestrates calls to TicketSalesService and RsvpStatsService.
   * Metrics:
   * - Total Sales: Gross revenue from completed orders (published events the user manages)
   * - RSVPs: Total confirmed RSVPs (same published-event scope: author or vendor team)
   * - Net Earnings: Gross revenue minus platform fees (same scope as Total Sales)
   * - Tickets Sold: Total tickets from completed orders (same scope as Total Sales)
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

    // Revenue + RSVP use the same managed-events scope (author or vendor team).
    $revenue = $this->ticketSalesService->getManagedVendorRevenue($userId);
    // Get RSVP count from RsvpStatsService (published events filter).
    $rsvpCount = $this->rsvpStatsService->getManagedPublishedEventsRsvpCount($userId);

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
          'total_submissions' => 0,
          'confirmed' => 0,
          'pending' => 0,
          'waitlisted' => 0,
          'cancelled' => 0,
          'checkins' => 0,
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
        'donations' => ['total' => 0.0, 'count' => 0, 'average' => 0.0],
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

    try {
      $rsvpBreakdown = $this->rsvpStatsService->getRsvpStatusBreakdownForEvent($event_id);
    }
    catch (\Exception $e) {
      $rsvpBreakdown = [
        'total_non_cancelled' => 0,
        'confirmed' => 0,
        'pending' => 0,
        'waitlisted' => 0,
        'cancelled' => 0,
        'checkins' => 0,
      ];
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
        'sales_during_period' => ['count' => 0, 'revenue' => '$0.00'],
        'sales_during_boost' => '$0.00',
        'revenue_during_boost' => 0.0,
        'orders_during_boost' => 0,
        'tickets_during_boost' => 0,
        'rsvps_during_boost' => 0,
        'donation_revenue_during_boost' => 0.0,
        'average_donation_during_boost' => 0.0,
        'donation_revenue_during_boost_formatted' => '$0.00',
        'average_donation_during_boost_formatted' => '$0.00',
        'rsvp_donation_count_during_boost' => 0,
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

    // Organiser donations (checkout + RSVP); excludes platform_donation.
    $donationStats = $this->getOrganiserDonationStats($event_id);

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
      'rsvps' => array_merge($rsvpSummary, $rsvpBreakdown, [
        'total_submissions' => $rsvpBreakdown['total_non_cancelled'] ?? 0,
      ]),
      'audience' => $audience,
      'boost' => $boost,
      'boost_metrics' => $boostMetrics,
      'tickets' => $ticketBreakdown,
      'donations' => $donationStats,
    ];
  }

  /**
   * Returns organiser donation stats for an event (checkout + RSVP only).
   *
   * @return array{total: float, count: int, average: float}
   *   Donation totals for vendor-facing analytics.
   */
  private function getOrganiserDonationStats(int $eventId): array {
    if ($eventId <= 0) {
      return ['total' => 0.0, 'count' => 0, 'average' => 0.0];
    }

    $organiserTypes = $this->orderItemClassifier->getOrganiserDonationTypes();
    if ($organiserTypes === []) {
      return ['total' => 0.0, 'count' => 0, 'average' => 0.0];
    }

    $total = 0.0;
    $count = 0;

    try {
      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
      $orderStorage = $this->entityTypeManager->getStorage('commerce_order');
      $orderItemIds = $orderItemStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $organiserTypes, 'IN')
        ->condition('field_target_event', $eventId)
        ->execute();

      if ($orderItemIds === []) {
        return ['total' => 0.0, 'count' => 0, 'average' => 0.0];
      }

      $orderItems = $orderItemStorage->loadMultiple($orderItemIds);
      $completedOrderCache = [];
      foreach ($orderItems as $orderItem) {
        $orderId = (int) $orderItem->getOrderId();
        if ($orderId <= 0) {
          continue;
        }

        if (!array_key_exists($orderId, $completedOrderCache)) {
          $order = $orderStorage->load($orderId);
          $completedOrderCache[$orderId] = $order && $order->getState()->getId() === 'completed';
        }

        if (!$completedOrderCache[$orderId]) {
          continue;
        }

        $totalPrice = $orderItem->getTotalPrice();
        if ($totalPrice) {
          $total += (float) $totalPrice->getNumber();
          $count++;
        }
      }
    }
    catch (\Exception $e) {
      return ['total' => 0.0, 'count' => 0, 'average' => 0.0];
    }

    $total = round($total, 2);
    $average = $count > 0 ? round($total / $count, 2) : 0.0;

    return [
      'total' => $total,
      'count' => $count,
      'average' => $average,
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
