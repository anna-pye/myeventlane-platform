<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_commerce\Service\EventOperationalExtrasSalesSummaryBuilder;
use Drupal\myeventlane_commerce\Service\TicketTierAnalyticsService;
use Drupal\myeventlane_event\Service\TicketTierLifecycleService;
use Drupal\myeventlane_event_studio\Service\EventStudioCommerceSalesSummaryBuilder;
use Drupal\myeventlane_pro\Service\ProActiveResolver;
use Drupal\node\NodeInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Service\MetricsAggregator;
use Drupal\myeventlane_vendor\Service\VendorEventTabsService;
use Drupal\user\UserInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Event analytics controller.
 *
 * Displays real analytics data from Commerce and RSVP.
 */
final class VendorEventAnalyticsController extends VendorConsoleBaseController {

  /**
   * Constructs the controller.
   */
  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MetricsAggregator $metricsAggregator,
    private readonly VendorEventTabsService $eventTabsService,
    private readonly TicketTierLifecycleService $ticketTierLifecycle,
    private readonly TicketTierAnalyticsService $ticketTierAnalytics,
    private readonly AccessManagerInterface $accessManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly ?ProActiveResolver $proActiveResolver = NULL,
    private readonly ?EventStudioCommerceSalesSummaryBuilder $commerceSalesSummaryBuilder = NULL,
    private readonly ?EventOperationalExtrasSalesSummaryBuilder $extrasSalesSummaryBuilder = NULL,
  ) {
    parent::__construct($domain_detector, $current_user, $messenger);
  }

  /**
   * Displays analytics for an event.
   */
  public function analytics(NodeInterface $event): array {
    $this->assertEventOwnership($event);
    if (!$this->proActiveResolver) {
      throw new AccessDeniedHttpException('Pro resolver service is unavailable.');
    }
    $user = $this->entityTypeManager->getStorage('user')->load((int) $this->currentUser->id());
    if (!$user instanceof UserInterface || !$this->proActiveResolver->isUserProActive($user)) {
      throw new AccessDeniedHttpException('Pro subscription is required.');
    }
    $tabs = $this->eventTabsService->getTabs($event, 'analytics');
    $overview = $this->metricsAggregator->getEventOverview($event);

    $ticketRows = array_values(array_filter(
      $this->ticketTierLifecycle->loadOrderedTicketsForEvent($event),
      static fn ($entity) => $entity instanceof TicketTypeInterface
    ));
    $ticket_tier_rollup = $this->ticketTierAnalytics->buildEventTierRollup($event, $ticketRows);
    $ticket_tier_rollup['tier_row_count'] = count($ticketRows);

    $charts = $this->metricsAggregator->getEventCharts($event);

    $chart_data = [
      'event-sales' => [
        'type' => 'line',
        'labels' => array_column($charts['sales'] ?? [], 'date'),
        'datasets' => [
          [
            'label' => 'Sales',
            'data' => array_column($charts['sales'] ?? [], 'amount'),
            'borderColor' => '#2563eb',
            'backgroundColor' => 'rgba(37, 99, 235, 0.12)',
          ],
        ],
      ],
      'event-rsvps' => [
        'type' => 'line',
        'labels' => array_column($charts['rsvps'] ?? [], 'date'),
        'datasets' => [
          [
            'label' => 'RSVPs',
            'data' => array_column($charts['rsvps'] ?? [], 'rsvps'),
            'borderColor' => '#10b981',
            'backgroundColor' => 'rgba(16, 185, 129, 0.12)',
          ],
        ],
      ],
    ];

    $ticket_mix_labels = [];
    $ticket_mix_sold = [];
    foreach ($overview['tickets'] ?? [] as $ticket) {
      if (!is_array($ticket)) {
        continue;
      }
      $sold = (int) ($ticket['sold'] ?? 0);
      if ($sold <= 0) {
        continue;
      }
      $label = trim((string) ($ticket['label'] ?? ''));
      if ($label === '') {
        continue;
      }
      $ticket_mix_labels[] = $label;
      $ticket_mix_sold[] = $sold;
    }
    if ($ticket_mix_labels !== []) {
      $chart_data['event-ticket-mix'] = [
        'type' => 'doughnut',
        'labels' => $ticket_mix_labels,
        'datasets' => [
          [
            'label' => (string) $this->t('Tickets sold'),
            'data' => $ticket_mix_sold,
            'backgroundColor' => [
              '#6C7EF2',
              '#F26D5B',
              '#5CC98B',
              '#FFD46F',
              '#8b5cf6',
              '#06b6d4',
            ],
          ],
        ],
      ];
    }

    $boost_page_url = NULL;
    try {
      $boost_page_url = Url::fromRoute('myeventlane_boost.vendor_event_boost', ['event' => $event->id()])->toString();
    }
    catch (\Throwable) {
      $boost_page_url = NULL;
    }

    $public_event_url = $this->domainDetector->publicUrl(Url::fromRoute('entity.node.canonical', ['node' => $event->id()])->toString());

    $workspace_back_url = NULL;
    try {
      $workspace_back_url = Url::fromRoute('myeventlane_vendor.console.event_workspace', ['event' => $event->id()])->toString();
    }
    catch (\Throwable) {
      $workspace_back_url = NULL;
    }

    $edit_event_url = NULL;
    try {
      $edit_event_url = Url::fromRoute('myeventlane_event_studio.edit', ['node' => $event->id()])->toString();
    }
    catch (\Throwable) {
      $edit_event_url = NULL;
    }

    $export_pdf_url = $this->exportUrlIfAccessible($event, 'myeventlane_analytics.export_pdf');
    $export_excel_url = $this->exportUrlIfAccessible($event, 'myeventlane_analytics.export_excel');

    $commerce_analytics = $this->buildCommerceAnalytics($event, $overview, $ticket_tier_rollup);

    return $this->buildVendorPage('mel_event_workspace', [
      'event' => $event,
      'tabs' => $tabs,
      'actions' => [
        [
          'label' => (string) $this->t('View orders'),
          'url' => Url::fromRoute('myeventlane_vendor.console.event_orders', ['event' => $event->id()])->toString(),
          'class' => 'mel-btn--secondary',
        ],
      ],
      'meta' => NULL,
      'sidebar' => NULL,
      'content' => [
        '#theme' => 'myeventlane_vendor_event_analytics',
        '#event' => $event,
        '#charts' => $charts,
        '#overview' => $overview,
        '#ticket_tier_rollup' => $ticket_tier_rollup,
        '#boost_page_url' => $boost_page_url,
        '#public_event_url' => $public_event_url,
        '#workspace_back_url' => $workspace_back_url,
        '#edit_event_url' => $edit_event_url,
        '#export_pdf_url' => $export_pdf_url,
        '#export_excel_url' => $export_excel_url,
        '#commerce_analytics' => $commerce_analytics,
        '#has_ticket_mix_chart' => isset($chart_data['event-ticket-mix']),
      ],
      '#attached' => [
        'library' => [
          'myeventlane_vendor_theme/analytics',
        ],
        'drupalSettings' => [
          'vendorCharts' => $chart_data,
        ],
      ],
    ]);
  }

  /**
   * Ticket vs extras commerce metrics (reuses Manage event summary services).
   *
   * @param array<string, mixed> $overview
   * @param array<string, mixed> $ticket_tier_rollup
   *
   * @return array<string, mixed>
   */
  private function buildCommerceAnalytics(NodeInterface $event, array $overview, array $ticket_tier_rollup): array {
    $sales = is_array($overview['sales'] ?? NULL) ? $overview['sales'] : [];
    $ticket_revenue = (string) ($sales['gross'] ?? '$0.00');
    $ticket_qty = (int) ($sales['tickets_sold'] ?? 0);
    if ($ticket_qty === 0) {
      $ticket_qty = (int) ($ticket_tier_rollup['total_sold'] ?? 0);
    }

    $extras_revenue = '—';
    $extras_items = 0;
    $orders_with_extras = 0;
    $top_extras_category = '—';

    if ($this->extrasSalesSummaryBuilder) {
      try {
        $extras_panel = $this->extrasSalesSummaryBuilder->buildExtrasSalesPanel($event);
        $summary = is_array($extras_panel['summary'] ?? NULL) ? $extras_panel['summary'] : [];
        $extras_revenue = (string) ($summary['total_revenue'] ?? '—');
        $extras_items = (int) ($summary['total_items_sold'] ?? 0);
        $orders_with_extras = (int) ($summary['orders_with_extras'] ?? 0);
        $top_extras_category = (string) ($summary['top_category'] ?? '—');
      }
      catch (\Throwable $e) {
        $this->loggerFactory->get('myeventlane_vendor')->warning('Analytics extras summary failed for event @nid: @message', [
          '@nid' => (string) $event->id(),
          '@message' => $e->getMessage(),
        ]);
      }
    }

    $ticket_panel_rows = 0;
    if ($this->commerceSalesSummaryBuilder) {
      try {
        $ticket_panel = $this->commerceSalesSummaryBuilder->buildTicketSalesPanel($event);
        $ticket_panel_rows = count(is_array($ticket_panel['rows'] ?? NULL) ? $ticket_panel['rows'] : []);
      }
      catch (\Throwable) {
        // Keep overview-derived ticket metrics.
      }
    }

    return [
      'ticket_revenue' => $ticket_revenue,
      'ticket_quantity_sold' => $ticket_qty,
      'ticket_types_configured' => $ticket_panel_rows > 0 ? $ticket_panel_rows : (int) ($ticket_tier_rollup['tier_row_count'] ?? 0),
      'extras_revenue' => $extras_revenue,
      'extras_items_sold' => $extras_items,
      'orders_with_extras' => $orders_with_extras,
      'top_extras_category' => $top_extras_category,
      'booking_activity_note' => (string) ($ticket_tier_rollup['conversion_note'] ?? ''),
    ];
  }

  /**
   * Export route URL only when access checks pass for the current user.
   */
  private function exportUrlIfAccessible(NodeInterface $event, string $routeName): ?string {
    $parameters = ['node' => $event->id()];
    try {
      if (!$this->accessManager->checkNamedRoute($routeName, $parameters, $this->currentUser, TRUE)->isAllowed()) {
        return NULL;
      }
      return Url::fromRoute($routeName, $parameters)->toString();
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_vendor')->warning('Event analytics export URL build failed for route @route: @message', [
        '@route' => $routeName,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

}
