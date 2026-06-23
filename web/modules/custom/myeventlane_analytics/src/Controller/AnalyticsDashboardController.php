<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Controller;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
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
use Drupal\myeventlane_growth\Service\GrowthInsightService;
use Drupal\myeventlane_growth\Service\GrowthTrackingService;
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
    // Optional: existing growth guidance, surfaced (not recomputed) on the dashboard.
    private readonly ?GrowthInsightService $growthInsight = NULL,
    private readonly ?GrowthTrackingService $growthTracking = NULL,
    private readonly ?CsrfTokenGenerator $csrfToken = NULL,
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
      $container->has('myeventlane_growth.insight') ? $container->get('myeventlane_growth.insight') : NULL,
      $container->has('myeventlane_growth.tracking') ? $container->get('myeventlane_growth.tracking') : NULL,
      $container->get('csrf_token'),
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

    // Presentation-only: surface existing GrowthInsightService guidance. The
    // service performs its own deterministic calculations and DB-backed counts;
    // we pass no recomputed event metrics (events => []) so nothing is invented.
    $growthCards = $this->buildGrowthCards();

    $libraries = [
      'myeventlane_vendor_theme/global-styling',
      'myeventlane_analytics/analytics',
    ];
    $drupalSettings = [];
    if ($growthCards !== [] && $this->csrfToken) {
      $libraries[] = 'myeventlane_growth/dashboard_cards';
      $drupalSettings['melGrowth'] = [
        'dismissUrl' => Url::fromRoute('myeventlane_growth.dismiss')->toString(TRUE)->getGeneratedUrl(),
        'csrfToken' => $this->csrfToken->get('rest'),
      ];
    }

    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      'title' => $analyticsModel['title'] ?? 'Insights',
      'body' => [
        '#theme' => 'myeventlane_analytics_dashboard',
        '#analytics_model' => $analyticsModel,
        '#summary_stats' => [],
        '#event_analytics' => [],
        '#growth_cards' => $growthCards,
      ],
      '#attached' => [
        'library' => $libraries,
        'drupalSettings' => $drupalSettings,
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => $cacheTags,
        'max-age' => 300,
      ],
    ]);
  }

  /**
   * Surfaces existing organiser growth insights (no new logic or data).
   *
   * Reuses GrowthInsightService calculations and myeventlane_growth.settings.
   * Account-level rules (Pro nudges, retention, engagement) apply; per-event
   * Boost rules are intentionally inactive here because we do not recompute
   * per-event metric rows on this surface.
   *
   * @return array<int, array<string, mixed>>
   */
  private function buildGrowthCards(): array {
    if (!$this->growthInsight) {
      return [];
    }

    $uid = (int) $this->currentUser->id();
    try {
      $cards = $this->growthInsight->buildInsights([
        'uid' => $uid,
        'account' => $this->currentUser,
        'surface' => 'analytics',
        'events' => [],
      ]);
    }
    catch (\Throwable $e) {
      \Drupal::logger('myeventlane_analytics')->warning('Growth cards failed on analytics: @m', ['@m' => $e->getMessage()]);
      return [];
    }

    if ($cards === [] || !$this->growthTracking) {
      return $cards;
    }

    // Record impressions exactly as the dashboard does, so cooldowns advance.
    foreach ($cards as $card) {
      if (!is_array($card)) {
        continue;
      }
      $eid = isset($card['event_id']) ? (int) $card['event_id'] : NULL;
      if ($eid !== NULL && $eid <= 0) {
        $eid = NULL;
      }
      $this->growthTracking->recordImpression(
        (string) ($card['key'] ?? ''),
        $uid,
        $eid,
        'analytics',
        ['title' => (string) ($card['title'] ?? '')],
      );
    }

    return $cards;
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

    // Presentation-only: turn existing metrics into a plain-language health
    // line and existing-route next actions. No new metrics or thresholds.
    $views = (int) ($conversionFunnel['views'] ?? 0);
    $trend = (string) ($salesVelocity['trend'] ?? 'stable');
    $eventHealth = $this->buildEventHealthLine($node, $totalTickets, $views, $trend);
    $growthActions = $this->buildGrowthActions($eventId);

    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      'title' => 'Insights: ' . $node->label(),
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
        '#event_health' => $eventHealth,
        '#growth_actions' => $growthActions,
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
    return (string) $this->t('Insights: @event', ['@event' => $node->label()]);
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
   * Plain-language event health line derived from existing metrics only.
   *
   * Maps the already-computed sales velocity trend and current totals onto a
   * human sentence. No thresholds, scoring, or data fetching are introduced.
   *
   * @return array{headline: string, detail: string, tone: string}
   */
  private function buildEventHealthLine(NodeInterface $node, int $totalTickets, int $views, string $trend): array {
    if (!$node->isPublished()) {
      return [
        'headline' => (string) $this->t('This event is a draft.'),
        'detail' => (string) $this->t('Publish it so people can find and book.'),
        'tone' => 'muted',
      ];
    }

    if ($totalTickets <= 0 && $views <= 0) {
      return [
        'headline' => (string) $this->t('Your event is newly published.'),
        'detail' => (string) $this->t('Numbers appear here as people discover it. Sharing helps it get seen.'),
        'tone' => 'info',
      ];
    }

    if ($totalTickets <= 0 && $views > 0) {
      return [
        'headline' => (string) $this->t('Your event is gaining interest.'),
        'detail' => (string) $this->t('People are viewing it — a nudge can turn interest into bookings.'),
        'tone' => 'info',
      ];
    }

    if ($trend === 'increasing') {
      return [
        'headline' => (string) $this->t('Bookings are increasing.'),
        'detail' => (string) $this->t('Momentum is building. Keep sharing to make the most of it.'),
        'tone' => 'ready',
      ];
    }

    if ($trend === 'decreasing') {
      return [
        'headline' => (string) $this->t('Sales have slowed recently.'),
        'detail' => (string) $this->t('A fresh share or a boost can help more people see it.'),
        'tone' => 'attention',
      ];
    }

    return [
      'headline' => (string) $this->t('Your event is ticking along steadily.'),
      'detail' => (string) $this->t('Bookings are steady. Sharing again can lift them further.'),
      'tone' => 'ready',
    ];
  }

  /**
   * Next-step actions using existing, access-checked routes only.
   *
   * @return array<int, array{label: string, url: string}>
   */
  private function buildGrowthActions(int $eventId): array {
    $candidates = [
      [(string) $this->t('Grow event'), 'myeventlane_boost.wizard.step1', ['event' => $eventId]],
      [(string) $this->t('View attendees'), 'myeventlane_vendor.console.audience', []],
      [(string) $this->t('View event'), 'entity.node.canonical', ['node' => $eventId]],
    ];

    $actions = [];
    foreach ($candidates as [$label, $route, $params]) {
      $url = $this->safeExportUrl($route, $params);
      if ($url !== NULL) {
        $actions[] = ['label' => $label, 'url' => $url];
      }
    }
    return $actions;
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
