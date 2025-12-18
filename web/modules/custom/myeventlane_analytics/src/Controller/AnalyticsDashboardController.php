<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_analytics\Service\AnalyticsDataService;
use Drupal\myeventlane_analytics\Service\SalesAnalyticsService;
use Drupal\myeventlane_analytics\Service\ConversionAnalyticsService;
use Drupal\myeventlane_analytics\Service\ReportGeneratorService;
use Drupal\myeventlane_dashboard\Service\DashboardEventLoader;
use Drupal\myeventlane_vendor\Controller\VendorConsoleBaseController;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for analytics dashboard.
 */
final class AnalyticsDashboardController extends VendorConsoleBaseController implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * Constructs AnalyticsDashboardController.
   */
  public function __construct(
    DomainDetector $domainDetector,
    AccountProxyInterface $currentUser,
    private readonly AnalyticsDataService $dataService,
    private readonly SalesAnalyticsService $salesService,
    private readonly ConversionAnalyticsService $conversionService,
    private readonly ReportGeneratorService $reportService,
    private readonly DashboardEventLoader $eventLoader,
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
      $container->get('myeventlane_analytics.data'),
      $container->get('myeventlane_analytics.sales'),
      $container->get('myeventlane_analytics.conversion'),
      $container->get('myeventlane_analytics.report'),
      $container->get('myeventlane_dashboard.event_loader'),
    );
  }

  /**
   * Renders the main analytics dashboard.
   *
   * @return array
   *   Render array for the dashboard.
   */
  public function dashboard(): array {
    $events = $this->eventLoader->loadEvents(FALSE, 50);

    // Get summary stats for all events.
    $summaryStats = [
      'total_events' => count($events),
      'total_revenue' => 0.0,
      'total_tickets' => 0,
    ];

    $eventAnalytics = [];
    foreach ($events as $event) {
      $eventId = (int) $event->id();
      $timeSeries = $this->dataService->getSalesTimeSeries($eventId, 'day');

      $eventRevenue = 0.0;
      $eventTickets = 0;
      foreach ($timeSeries as $point) {
        $eventRevenue += $point['revenue'];
        $eventTickets += $point['ticket_count'];
      }

      $summaryStats['total_revenue'] += $eventRevenue;
      $summaryStats['total_tickets'] += $eventTickets;

      $eventAnalytics[] = [
        'event' => $event,
        'revenue' => $eventRevenue,
        'tickets' => $eventTickets,
        'url' => $event->toUrl('canonical')->toString(),
        'analytics_url' => Url::fromRoute('myeventlane_analytics.event', ['node' => $eventId])->toString(),
      ];
    }

    // Sort by revenue descending.
    usort($eventAnalytics, function ($a, $b) {
      return $b['revenue'] <=> $a['revenue'];
    });

    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      'title' => 'Analytics Dashboard',
      'body' => [
        '#theme' => 'myeventlane_analytics_dashboard',
        '#summary_stats' => $summaryStats,
        '#event_analytics' => $eventAnalytics,
      ],
      '#attached' => [
        'library' => [
          'myeventlane_vendor_theme/global-styling',
          'myeventlane_analytics/analytics',
        ],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['node_list', 'user:' . $this->currentUser->id()],
        'max-age' => 300,
      ],
    ]);
  }

  /**
   * Renders analytics for a specific event.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return array
   *   Render array for event analytics.
   */
  public function eventAnalytics(NodeInterface $node): array {
    if ($node->bundle() !== 'event') {
      return ['#markup' => 'Invalid event.'];
    }

    $eventId = (int) $node->id();

    // Get all analytics data.
    $timeSeries = $this->dataService->getSalesTimeSeries($eventId, 'day');
    $ticketBreakdown = $this->dataService->getTicketTypeBreakdown($eventId);
    $salesVelocity = $this->salesService->getSalesVelocity($eventId);
    $conversionFunnel = $this->conversionService->getConversionFunnel($eventId);
    $bottlenecks = $this->conversionService->identifyBottlenecks($eventId);

    // Calculate totals.
    $totalRevenue = 0.0;
    $totalTickets = 0;
    foreach ($timeSeries as $point) {
      $totalRevenue += $point['revenue'];
      $totalTickets += $point['ticket_count'];
    }

    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      'title' => 'Analytics: ' . $node->label(),
      'body' => [
        '#theme' => 'myeventlane_analytics_event',
        '#event' => $node,
        '#time_series' => $timeSeries,
        '#ticket_breakdown' => $ticketBreakdown,
        '#sales_velocity' => $salesVelocity,
        '#conversion_funnel' => $conversionFunnel,
        '#bottlenecks' => $bottlenecks,
        '#total_revenue' => $totalRevenue,
        '#total_tickets' => $totalTickets,
      ],
      '#attached' => [
        'library' => [
          'myeventlane_vendor_theme/global-styling',
          'myeventlane_analytics/analytics',
        ],
        'drupalSettings' => [
          'analytics' => [
            'timeSeries' => $timeSeries,
            'ticketBreakdown' => $ticketBreakdown,
            'conversionFunnel' => $conversionFunnel,
          ],
        ],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['node:' . $eventId],
        'max-age' => 300,
      ],
    ]);
  }

  /**
   * Page title callback for event analytics.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return string
   *   Page title.
   */
  public function eventTitle(NodeInterface $node): string {
    return (string) $this->t('Analytics: @event', ['@event' => $node->label()]);
  }

  /**
   * Exports PDF report for an event.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   PDF response.
   */
  public function exportPdf(NodeInterface $node): Response {
    if ($node->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }

    return $this->reportService->generatePdfReport((int) $node->id());
  }

  /**
   * Exports Excel report for an event.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   Excel response.
   */
  public function exportExcel(NodeInterface $node): Response {
    if ($node->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }

    return $this->reportService->generateExcelReport((int) $node->id());
  }

  /**
   * Access callback for event analytics.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Access result.
   */
  public function accessEvent(NodeInterface $node, AccountInterface $account): AccessResultInterface {
    if ($node->bundle() !== 'event') {
      return AccessResult::forbidden('Not an event.');
    }

    // Admin can access all.
    if ($account->hasPermission('administer event attendees')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    // Check if user is the event author.
    $isOwner = (int) $node->getOwnerId() === (int) $account->id();

    if ($isOwner && $account->hasPermission('access analytics dashboard')) {
      return AccessResult::allowed()
        ->cachePerUser()
        ->addCacheableDependency($node);
    }

    return AccessResult::forbidden('You do not have access to view analytics for this event.');
  }

}
