<?php

declare(strict_types=1);

namespace Drupal\myeventlane_reporting\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_metrics\Service\EventMetricsServiceInterface;
use Drupal\myeventlane_vendor\Controller\VendorConsoleBaseController;
use Drupal\myeventlane_vendor\Service\RsvpStatsService;
use Drupal\myeventlane_vendor\Service\TicketSalesService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for chart data endpoints (JSON).
 */
final class ChartDataController extends VendorConsoleBaseController {

  /**
   * Constructs the controller.
   */
  public function __construct(
    DomainDetector $domainDetector,
    AccountProxyInterface $currentUser,
    MessengerInterface $messenger,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EventMetricsServiceInterface $metricsService,
    private readonly TicketSalesService $ticketSalesService,
    private readonly RsvpStatsService $rsvpStatsService,
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
      $container->get('myeventlane_vendor.service.ticket_sales'),
      $container->get('myeventlane_vendor.service.rsvp_stats'),
    );
  }

  /**
   * Access callback for chart data.
   */
  public function access(NodeInterface $event, AccountInterface $account): AccessResultInterface {
    // Check event ownership.
    if ($account->id() === 1 || $account->hasPermission('administer nodes')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    // Check if user owns the event.
    if ((int) $event->getOwnerId() === (int) $account->id()) {
      return AccessResult::allowed()
        ->cachePerUser()
        ->addCacheableDependency($event);
    }

    return AccessResult::forbidden()->cachePerPermissions();
  }

  /**
   * Sales time-series chart data.
   */
  public function sales(NodeInterface $event): JsonResponse {
    $this->assertEventOwnership($event);

    $dailySales = $this->ticketSalesService->getDailySalesSeries($event);

    // Format for Chart.js.
    $labels = [];
    $data = [];
    $ticketCounts = [];

    foreach ($dailySales as $day) {
      $labels[] = $day['date'] ?? '';
      $data[] = $day['amount'] ?? 0.0;
      $ticketCounts[] = $day['tickets'] ?? 0;
    }

    return new JsonResponse([
      'labels' => $labels,
      'datasets' => [
        [
          'label' => 'Revenue (AUD)',
          'data' => $data,
          'borderColor' => 'rgb(75, 192, 192)',
          'backgroundColor' => 'rgba(75, 192, 192, 0.2)',
          'yAxisID' => 'y',
        ],
        [
          'label' => 'Tickets Sold',
          'data' => $ticketCounts,
          'borderColor' => 'rgb(255, 99, 132)',
          'backgroundColor' => 'rgba(255, 99, 132, 0.2)',
          'yAxisID' => 'y1',
        ],
      ],
    ]);
  }

  /**
   * Ticket breakdown chart data (donut/bar).
   */
  public function ticketBreakdown(NodeInterface $event): JsonResponse {
    $this->assertEventOwnership($event);

    $breakdown = $this->metricsService->getTicketBreakdown($event);

    $labels = [];
    $data = [];
    $revenue = [];

    foreach ($breakdown as $type) {
      $labels[] = $type['label'] ?? 'Unknown';
      $data[] = $type['sold'] ?? 0;
      $revenueAmount = $type['revenue'] ?? NULL;
      $revenue[] = $revenueAmount ? (float) $revenueAmount->getNumber() : 0.0;
    }

    return new JsonResponse([
      'labels' => $labels,
      'datasets' => [
        [
          'label' => 'Tickets Sold',
          'data' => $data,
          'backgroundColor' => [
            'rgb(255, 99, 132)',
            'rgb(54, 162, 235)',
            'rgb(255, 205, 86)',
            'rgb(75, 192, 192)',
            'rgb(153, 102, 255)',
            'rgb(255, 159, 64)',
          ],
        ],
      ],
      'revenue' => $revenue,
    ]);
  }

  /**
   * Check-ins time-series chart data.
   */
  public function checkins(NodeInterface $event): JsonResponse {
    $this->assertEventOwnership($event);
    $eventId = (int) $event->id();

    // Get check-in data from event_attendee entities directly.
    $attendeeStorage = $this->entityTypeManager->getStorage('event_attendee');
    $attendeeIds = $attendeeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $eventId)
      ->execute();

    // Initialize last 14 days.
    $days = [];
    for ($i = 13; $i >= 0; $i--) {
      $date = date('Y-m-d', strtotime("-{$i} days"));
      $days[$date] = ['checked_in' => 0, 'total' => 0];
    }

    if (!empty($attendeeIds)) {
      $attendees = $attendeeStorage->loadMultiple($attendeeIds);

      foreach ($attendees as $attendee) {
        // Count total by creation date.
        $createdTime = (int) $attendee->getCreatedTime();
        $createdDate = date('Y-m-d', $createdTime);
        if (isset($days[$createdDate])) {
          $days[$createdDate]['total']++;
        }

        // Count check-ins by checked_in_at date.
        if ($attendee->isCheckedIn() && $attendee->hasField('checked_in_at') && !$attendee->get('checked_in_at')->isEmpty()) {
          $checkedInTime = (int) $attendee->get('checked_in_at')->value;
          $checkInDate = date('Y-m-d', $checkedInTime);
          if (isset($days[$checkInDate])) {
            $days[$checkInDate]['checked_in']++;
          }
        }
      }
    }

    $labels = [];
    $checkedInData = [];
    $totalData = [];

    foreach ($days as $date => $counts) {
      $labels[] = $date;
      $checkedInData[] = $counts['checked_in'];
      $totalData[] = $counts['total'];
    }

    return new JsonResponse([
      'labels' => $labels,
      'datasets' => [
        [
          'label' => 'Checked In',
          'data' => $checkedInData,
          'borderColor' => 'rgb(75, 192, 192)',
          'backgroundColor' => 'rgba(75, 192, 192, 0.2)',
        ],
        [
          'label' => 'Total Attendees',
          'data' => $totalData,
          'borderColor' => 'rgb(255, 99, 132)',
          'backgroundColor' => 'rgba(255, 99, 132, 0.2)',
        ],
      ],
    ]);
  }

  /**
   * Revenue time-series chart data.
   */
  public function revenue(NodeInterface $event): JsonResponse {
    $this->assertEventOwnership($event);

    $dailySales = $this->ticketSalesService->getDailySalesSeries($event);

    $labels = [];
    $data = [];

    foreach ($dailySales as $day) {
      $labels[] = $day['date'] ?? '';
      $data[] = $day['amount'] ?? 0.0;
    }

    return new JsonResponse([
      'labels' => $labels,
      'datasets' => [
        [
          'label' => 'Revenue (AUD)',
          'data' => $data,
          'borderColor' => 'rgb(75, 192, 192)',
          'backgroundColor' => 'rgba(75, 192, 192, 0.2)',
          'fill' => TRUE,
        ],
      ],
    ]);
  }

}
