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

  public function __construct(
    private readonly DateFormatterInterface $dateFormatter,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ?\Drupal\myeventlane_vendor\Service\TicketSalesService $ticketSales = NULL,
    private readonly ?\Drupal\myeventlane_vendor\Service\RsvpStatsService $rsvpStats = NULL,
    private readonly ?\Drupal\myeventlane_capacity\Service\EventCapacityServiceInterface $capacityService = NULL,
    private readonly ?EventStatsService $eventStats = NULL,
  ) {}

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
        if ($this->ticketSales !== NULL) {
          try {
            $summary = $this->ticketSales->getSalesSummary($event);
            $ticketsSold = (int) ($summary['tickets_sold'] ?? 0);
            $revenue = (float) ($summary['gross_raw'] ?? 0.0);
          }
          catch (\Throwable $e) {
          }
        }

        if ($this->rsvpStats !== NULL) {
          try {
            $rsvps = (int) $this->rsvpStats->getEventRsvpCount($eventId);
          }
          catch (\Throwable $e) {
          }
        }

        $capacity = NULL;
        if ($this->capacityService !== NULL) {
          try {
            $capacity = $this->capacityService->getCapacityTotal($event);
          }
          catch (\Throwable $e) {
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

      $dateFormatted = '';
      $startTimestamp = 0;
      if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
        $value = $event->get('field_event_start')->getValue();
        $raw = $value[0]['value'] ?? ($value[0]['date'] ?? '');
        if ($raw !== '' && is_string($raw)) {
          $startTimestamp = strtotime($raw) ?: 0;
          if ($startTimestamp) {
            $dateFormatted = $this->dateFormatter->format(
              $startTimestamp,
              'custom',
              'M j, Y',
            );
          }
        }
      }

      $status = 'normal';
      if ($soldPercentage > 70) {
        $status = 'selling-fast';
      }
      elseif ($soldPercentage < 20) {
        $status = 'low';
      }

      $checkinUrl = '';
      if ($this->moduleHandler->moduleExists('myeventlane_event_attendees')) {
        try {
          $checkinUrl = Url::fromRoute('myeventlane_event_attendees.vendor_operations_door', [
            'node' => $eventId,
          ])->toString();
        }
        catch (\Throwable $e) {
          $checkinUrl = '';
        }
      }
      $attendeesUrl = '';
      if ($this->moduleHandler->moduleExists('myeventlane_event_attendees')) {
        try {
          $attendeesUrl = Url::fromRoute('myeventlane_event_attendees.vendor_list', [
            'node' => $eventId,
          ])->toString();
        }
        catch (\Throwable $e) {
          $attendeesUrl = '';
        }
      }

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
