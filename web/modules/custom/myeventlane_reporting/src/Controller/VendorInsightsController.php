<?php

declare(strict_types=1);

namespace Drupal\myeventlane_reporting\Controller;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_attendee\Service\AttendeeRepositoryResolver;
use Drupal\myeventlane_automation\Service\AutomationAuditLogger;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_metrics\Service\EventMetricsServiceInterface;
use Drupal\myeventlane_vendor\Controller\VendorConsoleBaseController;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for vendor insights dashboard.
 */
final class VendorInsightsController extends VendorConsoleBaseController {

  /**
   * Constructs the controller.
   */
  public function __construct(
    DomainDetector $domainDetector,
    AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EventMetricsServiceInterface $metricsService,
    private readonly AttendeeRepositoryResolver $repositoryResolver,
    private readonly AutomationAuditLogger $auditLogger,
  ) {
    parent::__construct($domainDetector, $currentUser);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('myeventlane_metrics.service'),
      $container->get('myeventlane_attendee.repository_resolver'),
      $container->get('myeventlane_automation.audit_logger'),
    );
  }

  /**
   * Vendor insights dashboard.
   */
  public function dashboard(): array {
    $userId = (int) $this->currentUser->id();
    $this->assertVendorAccess();

    // Log access in audit log (fail silently if table doesn't exist).
    try {
      $this->auditLogger->log(
        NULL,
        'reporting_access',
        'vendor_insights',
        NULL,
        ['user_id' => $userId, 'path' => '/vendor/insights']
      );
    }
    catch (\Exception $e) {
      // Audit table may not exist yet - log error but don't break the page.
      \Drupal::logger('myeventlane_reporting')->warning('Failed to log audit entry: @message', ['@message' => $e->getMessage()]);
    }

    // Get vendor's events.
    $eventIds = $this->getUserEvents($userId);
    $events = [];
    if (!empty($eventIds)) {
      $events = $this->entityTypeManager->getStorage('node')->loadMultiple($eventIds);
    }

    // Calculate KPIs using metrics service.
    $kpis = $this->buildVendorKpis($userId, $events);

    // Get upcoming events needing attention.
    $attentionEvents = $this->getEventsNeedingAttention($events);

    // Get top performing event.
    $topEvent = $this->getTopPerformingEvent($events);

    return $this->buildVendorPage('myeventlane_reporting_vendor_insights', [
      'kpis' => $kpis,
      'attention_events' => $attentionEvents,
      'top_event' => $topEvent,
      'event_count' => count($events),
      '#attached' => [
        'library' => [
          'myeventlane_reporting/reporting',
          'myeventlane_vendor_theme/global-styling',
        ],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['node_list', 'user:' . $userId],
        'max-age' => 300,
      ],
    ]);
  }

  /**
   * Gets events owned by the user.
   */
  private function getUserEvents(int $userId): array {
    return $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'event')
      ->condition('uid', $userId)
      ->execute();
  }

  /**
   * Builds vendor-level KPIs.
   *
   * @param int $userId
   *   User ID.
   * @param array $events
   *   Array of event nodes.
   *
   * @return array
   *   Array of KPI card data.
   */
  private function buildVendorKpis(int $userId, array $events): array {
    $totalEvents = count($events);
    $totalAttendees = 0;
    $totalRevenue = 0.0;
    $totalTicketsSold = 0;
    $totalRefunds = 0;
    $totalCheckedIn = 0;

    // Aggregate metrics across all events.
    foreach ($events as $event) {
      if (!$event instanceof NodeInterface) {
        continue;
      }

      $attendeeCount = $this->metricsService->getAttendeeCount($event);
      $totalAttendees += $attendeeCount;

      $checkedInCount = $this->metricsService->getCheckedInCount($event);
      $totalCheckedIn += $checkedInCount;

      $revenue = $this->metricsService->getRevenue($event);
      if ($revenue) {
        $totalRevenue += (float) $revenue->getNumber();
      }

      // Count tickets sold from ticket breakdown.
      $ticketBreakdown = $this->metricsService->getTicketBreakdown($event);
      foreach ($ticketBreakdown as $type) {
        $totalTicketsSold += $type['sold'] ?? 0;
      }

      // @todo Get refund count from event state or commerce refunds.
      // For now, stub with 0.
    }

    // Count events by state.
    $eventsByState = $this->getEventsByState($events);

    // Calculate check-in rate.
    $checkInRate = $totalAttendees > 0 ? round(($totalCheckedIn / $totalAttendees) * 100, 1) : 0.0;

    return [
      [
        'label' => 'Total Events',
        'value' => (string) $totalEvents,
        'subtitle' => $eventsByState['published'] . ' published',
        'icon' => 'calendar',
      ],
      [
        'label' => 'Total Attendees',
        'value' => (string) $totalAttendees,
        'subtitle' => $totalCheckedIn . ' checked in',
        'icon' => 'users',
      ],
      [
        'label' => 'Tickets Sold',
        'value' => (string) $totalTicketsSold,
        'subtitle' => '$' . number_format($totalRevenue, 2) . ' revenue',
        'icon' => 'ticket',
      ],
      [
        'label' => 'Check-in Rate',
        'value' => $checkInRate . '%',
        'subtitle' => $totalCheckedIn . ' of ' . $totalAttendees,
        'icon' => 'check-circle',
      ],
    ];
  }

  /**
   * Gets events grouped by state.
   */
  private function getEventsByState(array $events): array {
    $byState = [
      'published' => 0,
      'draft' => 0,
      'scheduled' => 0,
      'sold_out' => 0,
    ];

    foreach ($events as $event) {
      if (!$event instanceof NodeInterface) {
        continue;
      }

      if (!$event->isPublished()) {
        $byState['draft']++;
        continue;
      }

      $byState['published']++;

      if ($this->metricsService->isSoldOut($event)) {
        $byState['sold_out']++;
      }

      // Check if event is scheduled (has future start date).
      if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
        $dateItem = $event->get('field_event_start');
        if ($dateItem->date && $dateItem->date->getTimestamp() > time()) {
          $byState['scheduled']++;
        }
      }
    }

    return $byState;
  }

  /**
   * Gets events needing attention.
   */
  private function getEventsNeedingAttention(array $events): array {
    $attention = [];
    $now = time();

    foreach ($events as $event) {
      if (!$event instanceof NodeInterface || !$event->isPublished()) {
        continue;
      }

      $needsAttention = FALSE;
      $reason = '';

      // Check if sold out.
      if ($this->metricsService->isSoldOut($event)) {
        $needsAttention = TRUE;
        $reason = 'Sold out';
      }

      // Check if sales start soon (within 24 hours) and no sales yet.
      if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
        $dateItem = $event->get('field_event_start');
        if ($dateItem->date) {
          $startTime = $dateItem->date->getTimestamp();
          $hoursUntilStart = ($startTime - $now) / 3600;

          if ($hoursUntilStart > 0 && $hoursUntilStart < 24) {
            $attendeeCount = $this->metricsService->getAttendeeCount($event);
            if ($attendeeCount === 0) {
              $needsAttention = TRUE;
              $reason = 'Sales start soon, no attendees yet';
            }
          }
        }
      }

      if ($needsAttention) {
        $attention[] = [
          'event' => $event,
          'reason' => $reason,
          'url' => $event->toUrl()->toString(),
        ];
      }
    }

    return array_slice($attention, 0, 5);
  }

  /**
   * Gets top performing event by attendance and revenue.
   */
  private function getTopPerformingEvent(array $events): ?array {
    $topEvent = NULL;
    $topScore = 0;

    foreach ($events as $event) {
      if (!$event instanceof NodeInterface) {
        continue;
      }

      $attendeeCount = $this->metricsService->getAttendeeCount($event);
      $revenue = $this->metricsService->getRevenue($event);
      $revenueAmount = $revenue ? (float) $revenue->getNumber() : 0.0;

      // Simple scoring: attendance count + revenue/10.
      $score = $attendeeCount + ($revenueAmount / 10);

      if ($score > $topScore) {
        $topScore = $score;
        $topEvent = [
          'event' => $event,
          'attendees' => $attendeeCount,
          'revenue' => $revenueAmount,
          'url' => $event->toUrl()->toString(),
        ];
      }
    }

    return $topEvent;
  }

}
