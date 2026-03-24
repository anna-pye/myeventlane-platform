<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Service;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_event\Service\EventStatsService;
use Drupal\node\NodeInterface;

/**
 * Batch stats service for the Attendees & Sales dashboard.
 *
 * Uses EventStatsService when available (single-query Commerce + RSVP stats,
 * cached). Falls back to TicketSalesService, RsvpStatsService, and
 * EventCapacityService when the new service is unavailable.
 */
final class AttendeeEventStatsService {

  /**
   * Constructs the service.
   *
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter.
   * @param \Drupal\myeventlane_vendor\Service\TicketSalesService|null $ticketSales
   *   The ticket sales service (optional).
   * @param \Drupal\myeventlane_vendor\Service\RsvpStatsService|null $rsvpStats
   *   The RSVP stats service (optional).
   * @param \Drupal\myeventlane_capacity\Service\EventCapacityServiceInterface|null $capacityService
   *   The capacity service (optional).
   * @param \Drupal\myeventlane_event\Service\EventStatsService|null $eventStats
   *   Aggregated event stats (optional).
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   */
  public function __construct(
    private readonly DateFormatterInterface $dateFormatter,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ?\Drupal\myeventlane_vendor\Service\TicketSalesService $ticketSales = NULL,
    private readonly ?\Drupal\myeventlane_vendor\Service\RsvpStatsService $rsvpStats = NULL,
    private readonly ?\Drupal\myeventlane_capacity\Service\EventCapacityServiceInterface $capacityService = NULL,
    private readonly ?EventStatsService $eventStats = NULL,
  ) {}

  /**
   * Builds stats for multiple events and aggregate KPIs.
   *
   * @param array<\Drupal\node\NodeInterface> $events
   *   Event nodes (vendor-owned).
   *
   * @return array{cards: array<int, array>, kpis: array}
   *   'cards' keyed by event ID, 'kpis' with events, tickets_sold, revenue, upcoming.
   */
  public function buildStatsForEvents(array $events): array {
    $cards = [];
    $kpis = [
      'events' => 0,
      'tickets_sold' => 0,
      'revenue' => 0.0,
      'upcoming' => 0,
    ];

    $now = time();

    foreach ($events as $event) {
      if (!$event instanceof NodeInterface) {
        continue;
      }

      $eventId = (int) $event->id();

      $ticketsSold = 0;
      $revenue = 0.0;
      $rsvps = 0;
      $totalTickets = 0;
      $soldPercentage = 0.0;
      $attendees = 0;
      $usedEventStats = FALSE;

      if ($this->eventStats !== NULL) {
        try {
          $stats = $this->eventStats->getEventStats($eventId);
          $ticketsSold = (int) ($stats['tickets_sold'] ?? 0);
          $revenue = (float) ($stats['revenue_total'] ?? 0.0);
          $rsvps = (int) ($stats['rsvp_count'] ?? 0);
          $totalTickets = (int) ($stats['tickets_total'] ?? 0);
          $soldPercentage = (float) ($stats['sold_percentage'] ?? 0.0);
          $attendees = (int) ($stats['attendees_count'] ?? ($ticketsSold + $rsvps));
          $usedEventStats = TRUE;
        }
        catch (\Throwable $e) {
          $usedEventStats = FALSE;
        }
      }

      if (!$usedEventStats) {
        // Sales summary (tickets sold, revenue).
        if ($this->ticketSales !== NULL) {
          try {
            $summary = $this->ticketSales->getSalesSummary($event);
            $ticketsSold = (int) ($summary['tickets_sold'] ?? 0);
            $revenue = (float) ($summary['gross_raw'] ?? 0.0);
          }
          catch (\Throwable $e) {
            // Fallback to 0 on error.
          }
        }

        // RSVPs.
        if ($this->rsvpStats !== NULL) {
          try {
            $rsvps = (int) $this->rsvpStats->getEventRsvpCount($eventId);
          }
          catch (\Throwable $e) {
            // Fallback to 0.
          }
        }

        // Capacity and sold percentage.
        $capacity = NULL;
        if ($this->capacityService !== NULL) {
          try {
            $capacity = $this->capacityService->getCapacityTotal($event);
          }
          catch (\Throwable $e) {
            // Leave capacity null.
          }
        }

        $totalTickets = $capacity ?? $ticketsSold;
        if ($totalTickets <= 0) {
          $totalTickets = max(1, $ticketsSold + $rsvps);
        }

        $filled = $ticketsSold + $rsvps;
        $soldPercentage = $totalTickets > 0
          ? min(100.0, round(100.0 * $filled / $totalTickets, 1))
          : 0.0;

        $attendees = $ticketsSold + $rsvps;
      }

      // Event date.
      $dateFormatted = '';
      $startTimestamp = 0;
      if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
        $item = $event->get('field_event_start');
        if ($item->date) {
          $startTimestamp = $item->date->getTimestamp();
          $dateFormatted = $this->dateFormatter->format(
            $startTimestamp,
            'custom',
            'M j, Y',
          );
        }
        elseif (!empty($item->value)) {
          $startTimestamp = strtotime($item->value);
          $dateFormatted = $this->dateFormatter->format(
            $startTimestamp,
            'custom',
            'M j, Y',
          );
        }
      }

      // Status badge.
      $status = 'normal';
      if ($soldPercentage > 70) {
        $status = 'selling-fast';
      }
      elseif ($soldPercentage < 20) {
        $status = 'low';
      }

      // URLs via Drupal routes.
      $checkinUrl = '';
      try {
        $checkinUrl = Url::fromRoute('myeventlane_event.checkin_door', [
          'event' => $eventId,
        ])->toString();
      }
      catch (\Throwable $e) {
        try {
          $checkinUrl = Url::fromRoute('myeventlane_checkout_flow.vendor_checkin', [
            'node' => $eventId,
          ])->toString();
        }
        catch (\Throwable $e2) {
          $checkinUrl = '';
        }
      }

      $attendeesUrl = Url::fromRoute('myeventlane_checkout_flow.vendor_event_attendees', [
        'node' => $eventId,
      ])->toString();

      $exportUrl = '';
      try {
        if ($this->moduleHandler->moduleExists('myeventlane_event_attendees')) {
          $exportUrl = Url::fromRoute('myeventlane_event_attendees.vendor_export', [
            'node' => $eventId,
          ])->toString();
        }
        elseif ($this->moduleHandler->moduleExists('myeventlane_views')) {
          $exportUrl = Url::fromRoute('myeventlane_views.attendee_csv', [], [
            'query' => ['download_csv' => $eventId],
          ])->toString();
        }
      }
      catch (\Throwable $e) {
        // Route may not exist.
      }

      $cards[$eventId] = [
        'title' => $event->label(),
        'date' => $dateFormatted,
        'tickets' => (int) $totalTickets,
        'sold' => $ticketsSold,
        'attendees' => $attendees,
        'revenue' => number_format($revenue, 2),
        'sold_percentage' => $soldPercentage,
        'status' => $status,
        'checkin_url' => $checkinUrl,
        'attendees_url' => $attendeesUrl,
        'export_url' => $exportUrl,
      ];

      $kpis['events']++;
      $kpis['tickets_sold'] += $attendees;
      $kpis['revenue'] += $revenue;
      if ($startTimestamp > $now) {
        $kpis['upcoming']++;
      }
    }

    $kpis['revenue'] = number_format($kpis['revenue'], 2);

    return [
      'cards' => $cards,
      'kpis' => $kpis,
    ];
  }

}
