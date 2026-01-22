<?php

declare(strict_types=1);

namespace Drupal\myeventlane_reporting\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_attendee\Service\AttendeeRepositoryResolver;
use Drupal\myeventlane_metrics\Service\EventMetricsServiceInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for admin reporting console.
 */
final class AdminReportsController extends ControllerBase {

  /**
   * The metrics service.
   */
  private readonly EventMetricsServiceInterface $metricsService;

  /**
   * The repository resolver.
   */
  private readonly AttendeeRepositoryResolver $repositoryResolver;

  /**
   * Constructs the controller.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    EventMetricsServiceInterface $metricsService,
    AttendeeRepositoryResolver $repositoryResolver,
  ) {
    // Set parent's protected property.
    $this->entityTypeManager = $entityTypeManager;
    $this->metricsService = $metricsService;
    $this->repositoryResolver = $repositoryResolver;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('myeventlane_metrics.service'),
      $container->get('myeventlane_attendee.repository_resolver'),
    );
  }

  /**
   * Platform overview reports.
   */
  public function overview(): array {
    // Get platform-wide KPIs.
    $kpis = $this->buildPlatformKpis();

    return [
      '#theme' => 'myeventlane_reporting_admin_overview',
      '#kpis' => $kpis,
      '#attached' => [
        'library' => [
          'myeventlane_reporting/reporting',
        ],
      ],
      '#cache' => [
        'tags' => ['node_list', 'commerce_order_list'],
        'max-age' => 300,
      ],
    ];
  }

  /**
   * Vendor reports.
   */
  public function vendors(): array {
    // Get vendor-level aggregations.
    $vendorStats = $this->getVendorStats();

    return [
      '#theme' => 'myeventlane_reporting_admin_vendors',
      '#vendor_stats' => $vendorStats,
      '#attached' => [
        'library' => [
          'myeventlane_reporting/reporting',
        ],
      ],
      '#cache' => [
        'tags' => ['myeventlane_vendor_list'],
        'max-age' => 300,
      ],
    ];
  }

  /**
   * Event reports.
   */
  public function events(): array {
    // Get top events.
    $topEvents = $this->getTopEvents();

    return [
      '#theme' => 'myeventlane_reporting_admin_events',
      '#top_events' => $topEvents,
      '#attached' => [
        'library' => [
          'myeventlane_reporting/reporting',
        ],
      ],
      '#cache' => [
        'tags' => ['node_list'],
        'max-age' => 300,
      ],
    ];
  }

  /**
   * Finance reports.
   */
  public function finance(): array {
    // Get finance metrics.
    $financeKpis = $this->buildFinanceKpis();

    return [
      '#theme' => 'myeventlane_reporting_admin_finance',
      '#kpis' => $financeKpis,
      '#attached' => [
        'library' => [
          'myeventlane_reporting/reporting',
        ],
      ],
      '#cache' => [
        'tags' => ['commerce_order_list'],
        'max-age' => 300,
      ],
    ];
  }

  /**
   * Builds platform-wide KPIs.
   */
  private function buildPlatformKpis(): array {
    $now = time();
    $sevenDaysAgo = $now - (7 * 24 * 60 * 60);
    $thirtyDaysAgo = $now - (30 * 24 * 60 * 60);
    $ninetyDaysAgo = $now - (90 * 24 * 60 * 60);

    // Count events by time period.
    $totalEvents = $this->countEvents();
    $events7d = $this->countEvents($sevenDaysAgo);
    $events30d = $this->countEvents($thirtyDaysAgo);
    $events90d = $this->countEvents($ninetyDaysAgo);

    // Count vendors.
    $vendorCount = $this->countVendors();

    // Count total attendees across all events.
    $totalAttendees = $this->getTotalAttendees();

    // Get total revenue.
    $totalRevenue = $this->getTotalRevenue();

    // Get refund rate (stub for now).
    $refundRate = 0.0;

    return [
      [
        'label' => 'Total Events',
        'value' => (string) $totalEvents,
        'subtitle' => $events7d . ' in last 7 days',
        'icon' => 'calendar',
      ],
      [
        'label' => 'Active Vendors',
        'value' => (string) $vendorCount,
        'subtitle' => 'Vendors with events',
        'icon' => 'store',
      ],
      [
        'label' => 'Total Attendees',
        'value' => (string) $totalAttendees,
        'subtitle' => 'Across all events',
        'icon' => 'users',
      ],
      [
        'label' => 'Total Revenue',
        'value' => '$' . number_format($totalRevenue, 2),
        'subtitle' => 'Gross revenue',
        'icon' => 'dollar-sign',
      ],
      [
        'label' => 'Refund Rate',
        'value' => number_format($refundRate, 1) . '%',
        'subtitle' => 'Refunds / Total',
        'icon' => 'undo',
      ],
    ];
  }

  /**
   * Counts events.
   */
  private function countEvents(?int $since = NULL): int {
    $query = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('status', 1);

    if ($since !== NULL) {
      $query->condition('created', $since, '>=');
    }

    return (int) $query->count()->execute();
  }

  /**
   * Counts vendors.
   */
  private function countVendors(): int {
    try {
      return (int) $this->entityTypeManager
        ->getStorage('myeventlane_vendor')
        ->getQuery()
        ->accessCheck(FALSE)
        ->count()
        ->execute();
    }
    catch (\Exception) {
      return 0;
    }
  }

  /**
   * Gets total attendees across all events.
   */
  private function getTotalAttendees(): int {
    $eventIds = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('status', 1)
      ->execute();

    $total = 0;
    foreach ($eventIds as $eventId) {
      $event = $this->entityTypeManager->getStorage('node')->load($eventId);
      if ($event instanceof NodeInterface) {
        $total += $this->metricsService->getAttendeeCount($event);
      }
    }

    return $total;
  }

  /**
   * Gets total revenue across all events.
   */
  private function getTotalRevenue(): float {
    $eventIds = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('status', 1)
      ->execute();

    $total = 0.0;
    foreach ($eventIds as $eventId) {
      $event = $this->entityTypeManager->getStorage('node')->load($eventId);
      if ($event instanceof NodeInterface) {
        $revenue = $this->metricsService->getRevenue($event);
        if ($revenue) {
          $total += (float) $revenue->getNumber();
        }
      }
    }

    return $total;
  }

  /**
   * Gets vendor statistics.
   */
  private function getVendorStats(): array {
    // @todo Implement vendor-level aggregations.
    return [];
  }

  /**
   * Gets top events by attendance/revenue.
   */
  private function getTopEvents(): array {
    $eventIds = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->range(0, 20)
      ->execute();

    $events = [];
    foreach ($eventIds as $eventId) {
      $event = $this->entityTypeManager->getStorage('node')->load($eventId);
      if ($event instanceof NodeInterface) {
        $attendeeCount = $this->metricsService->getAttendeeCount($event);
        $revenue = $this->metricsService->getRevenue($event);
        $revenueAmount = $revenue ? (float) $revenue->getNumber() : 0.0;

        $events[] = [
          'event' => $event,
          'attendees' => $attendeeCount,
          'revenue' => $revenueAmount,
        ];
      }
    }

    // Sort by revenue descending.
    usort($events, function ($a, $b) {
      return $b['revenue'] <=> $a['revenue'];
    });

    return array_slice($events, 0, 10);
  }

  /**
   * Builds finance KPIs.
   */
  private function buildFinanceKpis(): array {
    $totalRevenue = $this->getTotalRevenue();

    return [
      [
        'label' => 'Total Revenue',
        'value' => '$' . number_format($totalRevenue, 2),
        'subtitle' => 'Gross revenue',
        'icon' => 'dollar-sign',
      ],
      // @todo Add more finance metrics (refunds, net revenue, etc.).
    ];
  }

}
