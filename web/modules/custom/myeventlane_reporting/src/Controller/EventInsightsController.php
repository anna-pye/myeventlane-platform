<?php

declare(strict_types=1);

namespace Drupal\myeventlane_reporting\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_attendee\Service\AttendeeRepositoryResolver;
use Drupal\myeventlane_automation\Service\AutomationAuditLogger;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_metrics\Service\EventMetricsServiceInterface;
use Drupal\myeventlane_vendor\Controller\VendorConsoleBaseController;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for event-level insights with tabs.
 */
final class EventInsightsController extends VendorConsoleBaseController {

  /**
   * Constructs the controller.
   */
  public function __construct(
    DomainDetector $domainDetector,
    AccountProxyInterface $currentUser,
    MessengerInterface $messenger,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EventMetricsServiceInterface $metricsService,
    private readonly AttendeeRepositoryResolver $repositoryResolver,
    private readonly AutomationAuditLogger $auditLogger,
  ) {
    parent::__construct($domainDetector, $currentUser, $messenger);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('messenger'),
      $container->get('entity_type.manager'),
      $container->get('myeventlane_metrics.service'),
      $container->get('myeventlane_attendee.repository_resolver'),
      $container->get('myeventlane_automation.audit_logger'),
    );
  }

  /**
   * Access callback for event insights.
   */
  public function access(NodeInterface $event, AccountInterface $account): AccessResultInterface {
    $this->assertEventOwnership($event);

    // Log access (fail silently if table doesn't exist).
    try {
      $this->auditLogger->log(
        (int) $event->id(),
        'reporting_access',
        'event_insights',
        NULL,
        ['user_id' => (int) $account->id()]
      );
    }
    catch (\Exception $e) {
      // Audit table may not exist yet - log error but don't break the page.
      \Drupal::logger('myeventlane_reporting')->warning('Failed to log audit entry: @message', ['@message' => $e->getMessage()]);
    }

    return AccessResult::allowed()
      ->cachePerUser()
      ->addCacheableDependency($event);
  }

  /**
   * Event insights overview page.
   */
  public function overview(NodeInterface $event): array {
    $this->assertEventOwnership($event);

    // Build KPIs.
    $kpis = $this->buildEventKpis($event);

    // Build tabs.
    $tabs = $this->buildTabs($event);

    return $this->buildVendorPage('myeventlane_reporting_event_insights', [
      'event' => $event,
      'kpis' => $kpis,
      'tabs' => $tabs,
      'active_tab' => 'overview',
      '#attached' => [
        'library' => [
          'myeventlane_reporting/reporting',
          'myeventlane_vendor_theme/global-styling',
        ],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['node:' . $event->id()],
        'max-age' => 300,
      ],
    ]);
  }

  /**
   * Sales insights tab.
   */
  public function sales(NodeInterface $event): array {
    $this->assertEventOwnership($event);

    $kpis = $this->buildSalesKpis($event);
    $tabs = $this->buildTabs($event);

    return $this->buildVendorPage('myeventlane_reporting_event_insights', [
      'event' => $event,
      'kpis' => $kpis,
      'tabs' => $tabs,
      'active_tab' => 'sales',
      'chart_data' => [
        'sales' => Url::fromRoute('myeventlane_reporting.chart.sales', ['event' => $event->id()])->toString(),
        'ticket_breakdown' => Url::fromRoute('myeventlane_reporting.chart.ticket_breakdown', ['event' => $event->id()])->toString(),
        'revenue' => Url::fromRoute('myeventlane_reporting.chart.revenue', ['event' => $event->id()])->toString(),
      ],
      '#attached' => [
        'library' => [
          'myeventlane_reporting/reporting',
          'myeventlane_vendor_theme/global-styling',
        ],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['node:' . $event->id()],
        'max-age' => 300,
      ],
    ]);
  }

  /**
   * Attendee insights tab.
   */
  public function attendees(NodeInterface $event): array {
    $this->assertEventOwnership($event);

    $kpis = $this->buildAttendeeKpis($event);
    $tabs = $this->buildTabs($event);

    // Get attendee breakdown by source.
    $repository = $this->repositoryResolver->getRepository($event);
    $attendees = $repository->loadByEvent($event);

    $bySource = ['rsvp' => 0, 'ticket' => 0];
    foreach ($attendees as $attendee) {
      $source = $attendee->getSource() ?? 'rsvp';
      $bySource[$source] = ($bySource[$source] ?? 0) + 1;
    }

    return $this->buildVendorPage('myeventlane_reporting_event_insights', [
      'event' => $event,
      'kpis' => $kpis,
      'tabs' => $tabs,
      'active_tab' => 'attendees',
      'breakdown' => [
        'by_source' => $bySource,
      ],
      '#attached' => [
        'library' => [
          'myeventlane_reporting/reporting',
          'myeventlane_vendor_theme/global-styling',
        ],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['node:' . $event->id()],
        'max-age' => 300,
      ],
    ]);
  }

  /**
   * Check-in insights tab.
   */
  public function checkins(NodeInterface $event): array {
    $this->assertEventOwnership($event);

    $kpis = $this->buildCheckInKpis($event);
    $tabs = $this->buildTabs($event);

    return $this->buildVendorPage('myeventlane_reporting_event_insights', [
      'event' => $event,
      'kpis' => $kpis,
      'tabs' => $tabs,
      'active_tab' => 'checkins',
      'chart_data' => [
        'checkins' => Url::fromRoute('myeventlane_reporting.chart.checkins', ['event' => $event->id()])->toString(),
      ],
      '#attached' => [
        'library' => [
          'myeventlane_reporting/reporting',
          'myeventlane_vendor_theme/global-styling',
        ],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['node:' . $event->id()],
        'max-age' => 300,
      ],
    ]);
  }

  /**
   * Traffic insights tab (stub if pageview tracking not available).
   */
  public function traffic(NodeInterface $event): array {
    $this->assertEventOwnership($event);

    $tabs = $this->buildTabs($event);

    return $this->buildVendorPage('myeventlane_reporting_event_insights', [
      'event' => $event,
      'kpis' => [],
      'tabs' => $tabs,
      'active_tab' => 'traffic',
      'stub' => TRUE,
      'stub_message' => $this->t('Pageview tracking is not yet implemented. This feature will show event page views, conversion rates, and traffic sources.'),
      '#attached' => [
        'library' => [
          'myeventlane_reporting/reporting',
          'myeventlane_vendor_theme/global-styling',
        ],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['node:' . $event->id()],
        'max-age' => 300,
      ],
    ]);
  }

  /**
   * Builds event-level KPIs.
   */
  private function buildEventKpis(NodeInterface $event): array {
    $capacityTotal = $this->metricsService->getCapacityTotal($event);
    $remainingCapacity = $this->metricsService->getRemainingCapacity($event);
    $attendeeCount = $this->metricsService->getAttendeeCount($event);
    $checkedInCount = $this->metricsService->getCheckedInCount($event);
    $checkInRate = $this->metricsService->getCheckInRate($event);
    $revenue = $this->metricsService->getRevenue($event);
    $isSoldOut = $this->metricsService->isSoldOut($event);

    return [
      [
        'label' => 'Capacity',
        'value' => $capacityTotal ? $capacityTotal . ' total' : 'Unlimited',
        'subtitle' => $remainingCapacity !== NULL ? $remainingCapacity . ' remaining' : 'Unlimited',
        'icon' => 'users',
      ],
      [
        'label' => 'Total Attendees',
        'value' => (string) $attendeeCount,
        'subtitle' => $checkedInCount . ' checked in',
        'icon' => 'user-check',
      ],
      [
        'label' => 'Check-in Rate',
        'value' => $checkInRate !== NULL ? $checkInRate . '%' : '0%',
        'subtitle' => $checkedInCount . ' of ' . $attendeeCount,
        'icon' => 'check-circle',
      ],
      [
        'label' => 'Revenue',
        'value' => $revenue ? '$' . number_format((float) $revenue->getNumber(), 2) : 'N/A',
        'subtitle' => $isSoldOut ? 'Sold out' : 'Active sales',
        'icon' => 'dollar-sign',
      ],
    ];
  }

  /**
   * Builds sales-specific KPIs.
   */
  private function buildSalesKpis(NodeInterface $event): array {
    $revenue = $this->metricsService->getRevenue($event);
    $ticketBreakdown = $this->metricsService->getTicketBreakdown($event);
    $totalTickets = 0;
    foreach ($ticketBreakdown as $type) {
      $totalTickets += $type['sold'] ?? 0;
    }

    return [
      [
        'label' => 'Total Revenue',
        'value' => $revenue ? '$' . number_format((float) $revenue->getNumber(), 2) : '$0.00',
        'subtitle' => 'Gross revenue',
        'icon' => 'dollar-sign',
      ],
      [
        'label' => 'Tickets Sold',
        'value' => (string) $totalTickets,
        'subtitle' => count($ticketBreakdown) . ' ticket types',
        'icon' => 'ticket',
      ],
      [
        'label' => 'Average Ticket Price',
        'value' => $totalTickets > 0 && $revenue ? '$' . number_format((float) $revenue->getNumber() / $totalTickets, 2) : '$0.00',
        'subtitle' => 'Per ticket',
        'icon' => 'tag',
      ],
    ];
  }

  /**
   * Builds attendee-specific KPIs.
   */
  private function buildAttendeeKpis(NodeInterface $event): array {
    $attendeeCount = $this->metricsService->getAttendeeCount($event);
    $repository = $this->repositoryResolver->getRepository($event);
    $attendees = $repository->loadByEvent($event);

    $bySource = ['rsvp' => 0, 'ticket' => 0];
    foreach ($attendees as $attendee) {
      $source = $attendee->getSource() ?? 'rsvp';
      $bySource[$source] = ($bySource[$source] ?? 0) + 1;
    }

    return [
      [
        'label' => 'Total Attendees',
        'value' => (string) $attendeeCount,
        'subtitle' => $bySource['ticket'] . ' tickets, ' . $bySource['rsvp'] . ' RSVPs',
        'icon' => 'users',
      ],
      [
        'label' => 'RSVPs',
        'value' => (string) $bySource['rsvp'],
        'subtitle' => 'Free registrations',
        'icon' => 'calendar',
      ],
      [
        'label' => 'Paid Tickets',
        'value' => (string) $bySource['ticket'],
        'subtitle' => 'Purchased tickets',
        'icon' => 'ticket',
      ],
    ];
  }

  /**
   * Builds check-in specific KPIs.
   */
  private function buildCheckInKpis(NodeInterface $event): array {
    $attendeeCount = $this->metricsService->getAttendeeCount($event);
    $checkedInCount = $this->metricsService->getCheckedInCount($event);
    $checkInRate = $this->metricsService->getCheckInRate($event);
    $notCheckedIn = $attendeeCount - $checkedInCount;

    return [
      [
        'label' => 'Checked In',
        'value' => (string) $checkedInCount,
        'subtitle' => $checkInRate !== NULL ? $checkInRate . '% check-in rate' : '0%',
        'icon' => 'check-circle',
      ],
      [
        'label' => 'Not Checked In',
        'value' => (string) $notCheckedIn,
        'subtitle' => 'Pending check-in',
        'icon' => 'clock',
      ],
      [
        'label' => 'Check-in Rate',
        'value' => $checkInRate !== NULL ? $checkInRate . '%' : '0%',
        'subtitle' => $checkedInCount . ' of ' . $attendeeCount,
        'icon' => 'percent',
      ],
    ];
  }

  /**
   * Builds tab navigation.
   */
  private function buildTabs(NodeInterface $event): array {
    $eventId = $event->id();
    return [
      [
        'label' => $this->t('Overview'),
        'url' => Url::fromRoute('myeventlane_reporting.event_insights.overview', ['event' => $eventId])->toString(),
        'id' => 'overview',
      ],
      [
        'label' => $this->t('Sales'),
        'url' => Url::fromRoute('myeventlane_reporting.event_insights.sales', ['event' => $eventId])->toString(),
        'id' => 'sales',
      ],
      [
        'label' => $this->t('Attendees'),
        'url' => Url::fromRoute('myeventlane_reporting.event_insights.attendees', ['event' => $eventId])->toString(),
        'id' => 'attendees',
      ],
      [
        'label' => $this->t('Check-ins'),
        'url' => Url::fromRoute('myeventlane_reporting.event_insights.checkins', ['event' => $eventId])->toString(),
        'id' => 'checkins',
      ],
      [
        'label' => $this->t('Traffic'),
        'url' => Url::fromRoute('myeventlane_reporting.event_insights.traffic', ['event' => $eventId])->toString(),
        'id' => 'traffic',
      ],
    ];
  }

}
