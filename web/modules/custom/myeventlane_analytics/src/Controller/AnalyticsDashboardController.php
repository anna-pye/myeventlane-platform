<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Controller;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_analytics\Service\AnalyticsDataService;
use Drupal\myeventlane_analytics\Service\ConversionAnalyticsService;
use Drupal\myeventlane_analytics\Service\ReportGeneratorService;
use Drupal\myeventlane_analytics\Service\SalesAnalyticsService;
use Drupal\myeventlane_analytics\Service\VendorAnalyticsViewModelBuilder;
use Drupal\myeventlane_vendor\Controller\VendorConsoleBaseController;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
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
    MessengerInterface $messenger,
    private readonly AnalyticsDataService $dataService,
    private readonly SalesAnalyticsService $salesService,
    private readonly ConversionAnalyticsService $conversionService,
    private readonly ReportGeneratorService $reportService,
    private readonly VendorAnalyticsViewModelBuilder $vendorAnalyticsViewModelBuilder,
    private readonly EventVendorAccessChecker $eventAccessChecker,
    private readonly AccessManagerInterface $accessManager,
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
      $container->get('myeventlane_analytics.data'),
      $container->get('myeventlane_analytics.sales'),
      $container->get('myeventlane_analytics.conversion'),
      $container->get('myeventlane_analytics.report'),
      $container->get('myeventlane_analytics.vendor_view_model_builder'),
      $container->get('myeventlane_vendor.event_access_checker'),
      $container->get('access_manager'),
    );
  }

  /**
   * Renders the main analytics dashboard.
   *
   * @return array
   *   Render array for the dashboard.
   */
  public function dashboard(): array {
    $analyticsModel = $this->vendorAnalyticsViewModelBuilder->build($this->currentUser, []);

    $cacheTags = ['node_list', 'user:' . $this->currentUser->id()];
    foreach ($analyticsModel['events'] ?? [] as $row) {
      if (!empty($row['nid'])) {
        $cacheTags[] = 'node:' . (int) $row['nid'];
      }
    }

    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      'title' => $analyticsModel['title'] ?? 'Analytics',
      'body' => [
        '#theme' => 'myeventlane_analytics_dashboard',
        '#analytics_model' => $analyticsModel,
        '#summary_stats' => [],
        '#event_analytics' => [],
      ],
      '#attached' => [
        'library' => [
          'myeventlane_vendor_theme/global-styling',
          'myeventlane_analytics/analytics',
        ],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => $cacheTags,
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

    $workspaceBackUrl = $this->safeUrlString('myeventlane_vendor.console.event_workspace', ['event' => $eventId]);
    $vendorAnalyticsHomeUrl = $this->safeUrlString('myeventlane_analytics.dashboard', []);
    $exportPdfUrl = $this->safeExportUrl('myeventlane_analytics.export_pdf', ['node' => $eventId]);
    $exportExcelUrl = $this->safeExportUrl('myeventlane_analytics.export_excel', ['node' => $eventId]);

    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      'title' => 'Analytics: ' . $node->label(),
      'body' => [
        '#theme' => 'myeventlane_analytics_event',
        '#event' => $node,
        '#workspace_back_url' => $workspaceBackUrl,
        '#vendor_analytics_home_url' => $vendorAnalyticsHomeUrl,
        '#export_pdf_url' => $exportPdfUrl,
        '#export_excel_url' => $exportExcelUrl,
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

    if ($account->hasPermission('administer nodes')) {
      return AccessResult::allowed()->cachePerPermissions()->addCacheableDependency($node);
    }

    if ($account->hasPermission('administer event attendees')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    if (!$account->hasPermission('access analytics dashboard')) {
      return AccessResult::forbidden('Missing analytics permission.')
        ->cachePerPermissions()
        ->addCacheableDependency($node);
    }

    if ($this->eventAccessChecker->accountHasWorkspaceParityForEvent($node, $account)) {
      return AccessResult::allowed()
        ->cachePerUser()
        ->addCacheableDependency($node);
    }

    return AccessResult::forbidden('You do not have access to view analytics for this event.');
  }

  /**
   * Builds a URL string when the route exists; no access check.
   *
   * @param array<string, mixed> $parameters
   */
  private function safeUrlString(string $routeName, array $parameters): ?string {
    try {
      return Url::fromRoute($routeName, $parameters)->toString();
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Export/download URL only when the current user may run the route.
   *
   * @param array<string, mixed> $parameters
   */
  private function safeExportUrl(string $routeName, array $parameters): ?string {
    try {
      if (!$this->accessManager->checkNamedRoute($routeName, $parameters, $this->currentUser, TRUE)->isAllowed()) {
        return NULL;
      }
      return Url::fromRoute($routeName, $parameters)->toString();
    }
    catch (\Throwable) {
      return NULL;
    }
  }

}
