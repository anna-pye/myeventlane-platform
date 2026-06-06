<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\commerce_order\Entity\OrderItemInterface;
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
use Drupal\myeventlane_boost\Service\BoostDecisionSupportService;
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
    private readonly ?BoostDecisionSupportService $boostDecisionSupport = NULL,
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

    $sales_series = $charts['sales'] ?? [];
    $chart_data = [
      'event-sales' => [
        'type' => 'line',
        'labels' => array_column($sales_series, 'date'),
        'datasets' => [
          [
            'label' => 'Revenue',
            'data' => array_column($sales_series, 'amount'),
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

    $ticket_mix = $this->buildTicketTierMix($ticketRows);
    $ticket_mix_labels = $ticket_mix['labels'];
    $ticket_mix_sold = $ticket_mix['sold'];

    $ticket_daily_units = $this->buildTicketOnlyDailyUnitsSeries($event, $ticketRows);
    if (array_sum(array_column($ticket_daily_units, 'tickets')) > 0) {
      $chart_data['event-ticket-units-trend'] = [
        'type' => 'line',
        'labels' => array_column($ticket_daily_units, 'date'),
        'datasets' => [
          [
            'label' => (string) $this->t('Tickets sold'),
            'data' => array_column($ticket_daily_units, 'tickets'),
            'borderColor' => '#6C7EF2',
            'backgroundColor' => 'rgba(108, 126, 242, 0.12)',
          ],
        ],
      ];
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

    $is_tickets_enabled = $this->eventTabsService->isTicketsEnabled($event);
    $is_rsvp_enabled = $this->eventTabsService->isRsvpEnabled($event);
    $commerce_analytics = $this->buildCommerceAnalytics($event, $overview, $ticket_tier_rollup);

    foreach ($commerce_analytics['extras_mix'] ?? [] as $slice) {
      if (!is_array($slice)) {
        continue;
      }
      $amount = (float) ($slice['amount'] ?? 0);
      $label = trim((string) ($slice['label'] ?? ''));
      if ($amount <= 0 || $label === '') {
        continue;
      }
      if (!isset($chart_data['event-extras-mix'])) {
        $chart_data['event-extras-mix'] = [
          'type' => 'doughnut',
          'labels' => [],
          'datasets' => [
            [
              'label' => (string) $this->t('Extras revenue'),
              'data' => [],
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
      $chart_data['event-extras-mix']['labels'][] = $label;
      $chart_data['event-extras-mix']['datasets'][0]['data'][] = $amount;
    }

    $revenue_breakdown_chart = $this->buildRevenueBreakdownChart($overview, $commerce_analytics, $is_tickets_enabled);
    if ($revenue_breakdown_chart !== NULL) {
      $chart_data['event-revenue-breakdown'] = $revenue_breakdown_chart;
    }

    $boost_metrics = is_array($overview['boost_metrics'] ?? NULL) ? $overview['boost_metrics'] : [];
    $boost = is_array($overview['boost'] ?? NULL) ? $overview['boost'] : [];
    $boost_chart = $boost_metrics['chart_data'] ?? NULL;
    if (is_array($boost_chart)) {
      $chart_data['boost-impressions-clicks'] = [
        'type' => 'line',
        'labels' => $boost_chart['impressions_vs_clicks']['labels'] ?? [],
        'datasets' => [
          [
            'label' => 'Impressions',
            'data' => $boost_chart['impressions_vs_clicks']['impressions'] ?? [],
            'borderColor' => '#6366f1',
            'backgroundColor' => 'rgba(99, 102, 241, 0.12)',
          ],
          [
            'label' => 'Clicks',
            'data' => $boost_chart['impressions_vs_clicks']['clicks'] ?? [],
            'borderColor' => '#f59e0b',
            'backgroundColor' => 'rgba(245, 158, 11, 0.12)',
          ],
        ],
      ];
      $chart_data['boost-ctr-placement'] = [
        'type' => 'bar',
        'labels' => $boost_chart['ctr_by_placement']['labels'] ?? [],
        'datasets' => [
          [
            'label' => 'CTR %',
            'data' => $boost_chart['ctr_by_placement']['data'] ?? [],
            'backgroundColor' => 'rgba(16, 185, 129, 0.6)',
          ],
        ],
      ];
    }

    $capacity_total = $ticket_tier_rollup['total_remaining'] !== NULL
      ? ((int) ($ticket_tier_rollup['total_sold'] ?? 0) + (int) $ticket_tier_rollup['total_remaining'])
      : 0;
    $capacity_pct = $capacity_total > 0
      ? round(((int) ($ticket_tier_rollup['total_sold'] ?? 0) / $capacity_total) * 100, 1)
      : NULL;

    $donations = is_array($overview['donations'] ?? NULL) ? $overview['donations'] : [];
    $all_sales_section = $this->buildAllSalesSection(
      $overview,
      $commerce_analytics,
      $is_tickets_enabled,
      $is_rsvp_enabled,
      $donations,
    );
    $ticket_tier_breakdown = $this->buildTicketTierBreakdownDisplay($ticketRows);
    $ticket_sales_section = $this->buildTicketSalesSection(
      $overview,
      $commerce_analytics,
      $is_tickets_enabled,
      $ticket_tier_rollup,
      $ticketRows,
    );
    $merchandise_section = $this->buildMerchandiseSection($commerce_analytics);
    $rsvp_health_section = $this->buildRsvpHealthSection($overview, $is_rsvp_enabled, $all_sales_section['show']);

    $boost_performance_section = $this->buildBoostPerformanceSection(
      $boost_metrics,
      $boost,
      $is_tickets_enabled,
      $is_rsvp_enabled,
    );

    $placement_performance_section = $this->buildPlacementPerformanceSection($boost_metrics);

    $boost_decision_support = $this->buildBoostDecisionSupportSection(
      $boost_metrics,
      $boost,
      $is_tickets_enabled,
      $is_rsvp_enabled,
      $placement_performance_section,
      $boost_page_url,
    );

    if ($placement_performance_section['show'] && $placement_performance_section['cards'] !== []) {
      $chart_data['boost-placement-impressions'] = [
        'type' => 'bar',
        'labels' => array_column($placement_performance_section['cards'], 'label'),
        'datasets' => [
          [
            'label' => (string) $this->t('Impressions'),
            'data' => array_column($placement_performance_section['cards'], 'impressions'),
            'backgroundColor' => 'rgba(99, 102, 241, 0.6)',
          ],
        ],
        'options' => [
          'indexAxis' => 'y',
          'plugins' => [
            'legend' => [
              'display' => FALSE,
            ],
          ],
        ],
      ];
    }

    $recommendations = $this->buildRecommendations(
      $overview,
      $commerce_analytics,
      $is_tickets_enabled,
      $is_rsvp_enabled,
      $boost,
      $boost_page_url,
      $capacity_pct,
      $public_event_url,
      $ticketRows,
      $boost_decision_support,
    );

    $has_boost_metrics = $boost_performance_section['show_metrics'];

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
        '#ticket_tier_breakdown' => $ticket_tier_breakdown,
        '#boost_page_url' => $boost_page_url,
        '#public_event_url' => $public_event_url,
        '#workspace_back_url' => $workspace_back_url,
        '#edit_event_url' => $edit_event_url,
        '#export_pdf_url' => $export_pdf_url,
        '#export_excel_url' => $export_excel_url,
        '#commerce_analytics' => $commerce_analytics,
        '#all_sales_section' => $all_sales_section,
        '#ticket_sales_section' => $ticket_sales_section,
        '#merchandise_section' => $merchandise_section,
        '#rsvp_health_section' => $rsvp_health_section,
        '#recommendations' => $recommendations,
        '#boost_performance_section' => $boost_performance_section,
        '#boost_decision_support' => $boost_decision_support,
        '#placement_performance_section' => $placement_performance_section,
        '#has_all_sales_trend_chart' => isset($chart_data['event-sales']) && array_sum(array_column($sales_series, 'amount')) > 0,
        '#has_ticket_mix_chart' => isset($chart_data['event-ticket-mix']),
        '#has_ticket_trend_chart' => isset($chart_data['event-ticket-units-trend']),
        '#has_extras_mix_chart' => isset($chart_data['event-extras-mix']),
        '#has_revenue_breakdown_chart' => isset($chart_data['event-revenue-breakdown']),
        '#has_boost_metrics' => $has_boost_metrics,
        '#has_boost_trend_chart' => isset($chart_data['boost-impressions-clicks']),
        '#has_placement_impressions_chart' => isset($chart_data['boost-placement-impressions']),
        '#is_tickets_enabled' => $is_tickets_enabled,
        '#is_rsvp_enabled' => $is_rsvp_enabled,
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
    $ticket_revenue = $this->formatTicketRevenueFromTierRollup($ticket_tier_rollup);
    $ticket_qty = (int) ($sales['tickets_sold'] ?? 0);
    if ($ticket_qty === 0) {
      $ticket_qty = (int) ($ticket_tier_rollup['total_sold'] ?? 0);
    }

    $extras_revenue = '—';
    $extras_items = 0;
    $orders_with_extras = 0;
    $top_extras_category = '—';
    $extras_mix = [];
    $merchandise_revenue_display = '—';
    $addon_revenue_display = '—';
    $extras_catalog_exists = FALSE;
    $extras_product_rows = [];

    if ($this->extrasSalesSummaryBuilder) {
      try {
        $extras_panel = $this->extrasSalesSummaryBuilder->buildExtrasSalesPanel($event);
        $summary = is_array($extras_panel['summary'] ?? NULL) ? $extras_panel['summary'] : [];
        $rows = is_array($extras_panel['rows'] ?? NULL) ? $extras_panel['rows'] : [];
        $extras_product_rows = is_array($extras_panel['product_rows'] ?? NULL) ? $extras_panel['product_rows'] : [];
        $extras_revenue = (string) ($summary['total_revenue'] ?? '—');
        $extras_items = (int) ($summary['total_items_sold'] ?? 0);
        $orders_with_extras = (int) ($summary['orders_with_extras'] ?? 0);
        $top_extras_category = (string) ($summary['top_category'] ?? '—');

        $merchandise_amount = 0.0;
        $addon_amount = 0.0;

        foreach ($rows as $row) {
          if (!is_array($row)) {
            continue;
          }
          if (!empty($row['has_catalog'])) {
            $extras_catalog_exists = TRUE;
          }

          $row_key = (string) ($row['key'] ?? '');
          $row_revenue = (string) ($row['revenue'] ?? '—');
          $row_amount = $this->parseFormattedAmount($row_revenue);
          if ($row_amount <= 0) {
            continue;
          }

          $extras_mix[] = [
            'label' => (string) ($row['label'] ?? $row_key),
            'amount' => $row_amount,
          ];

          if ($row_key === 'merchandise') {
            $merchandise_amount = $row_amount;
            $merchandise_revenue_display = $row_revenue;
          }
          else {
            $addon_amount += $row_amount;
          }
        }

        if ($addon_amount > 0) {
          $addon_revenue_display = $this->formatAmountLikeReference($extras_revenue, $addon_amount);
        }
        elseif ($merchandise_amount <= 0 && $this->formattedAmountIsPositive($extras_revenue)) {
          $addon_revenue_display = $extras_revenue;
        }
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

    $show_extras_section = $extras_items > 0
      || $orders_with_extras > 0
      || $this->formattedAmountIsPositive($extras_revenue)
      || $extras_catalog_exists;

    return [
      'ticket_revenue' => $ticket_revenue,
      'ticket_quantity_sold' => $ticket_qty,
      'ticket_types_configured' => $ticket_panel_rows > 0 ? $ticket_panel_rows : (int) ($ticket_tier_rollup['tier_row_count'] ?? 0),
      'extras_revenue' => $extras_revenue,
      'extras_items_sold' => $extras_items,
      'orders_with_extras' => $orders_with_extras,
      'top_extras_category' => $top_extras_category,
      'merchandise_revenue_display' => $merchandise_revenue_display,
      'addon_revenue_display' => $addon_revenue_display,
      'show_extras_section' => $show_extras_section,
      'extras_mix' => $extras_mix,
      'extras_product_rows' => array_values(array_filter(
        $extras_product_rows,
        static fn ($row): bool => is_array($row) && (int) ($row['items_sold'] ?? 0) > 0,
      )),
      'booking_activity_note' => (string) ($ticket_tier_rollup['conversion_note'] ?? ''),
    ];
  }

  /**
   * CEO view — overall event sales health (never mixes ticket and merch KPIs).
   *
   * @param array<string, mixed> $overview
   * @param array<string, mixed> $commerce
   * @param array<string, mixed> $donations
   *
   * @return array{show: bool, kpis: list<array{label: string, value: string}>}
   */
  private function buildAllSalesSection(
    array $overview,
    array $commerce,
    bool $isTickets,
    bool $isRsvp,
    array $donations,
  ): array {
    $sales = is_array($overview['sales'] ?? NULL) ? $overview['sales'] : [];
    $rsvps = is_array($overview['rsvps'] ?? NULL) ? $overview['rsvps'] : [];
    $kpis = [];

    $gross = (string) ($sales['gross'] ?? '$0.00');
    if ($this->formattedAmountIsPositive($gross)) {
      $kpis[] = [
        'label' => (string) $this->t('Total revenue'),
        'value' => $gross,
      ];
    }

    $orders_count = (int) ($sales['orders_count'] ?? 0);
    if ($orders_count > 0) {
      $kpis[] = [
        'label' => (string) $this->t('Total orders'),
        'value' => (string) $orders_count,
      ];
    }

    $items_sold = $this->computeTotalItemsSold($sales);
    if ($items_sold > 0) {
      $kpis[] = [
        'label' => (string) $this->t('Total items sold'),
        'value' => (string) $items_sold,
      ];
    }

    if ($isRsvp) {
      $confirmed = (int) ($rsvps['confirmed'] ?? 0);
      if ($confirmed > 0) {
        $kpis[] = [
          'label' => (string) $this->t('RSVPs'),
          'value' => (string) $confirmed,
        ];
      }
    }

    $show = $kpis !== []
      || (int) ($donations['count'] ?? 0) > 0
      || ($isTickets && (int) ($commerce['ticket_quantity_sold'] ?? 0) > 0);

    return [
      'show' => $show,
      'kpis' => $kpis,
    ];
  }

  /**
   * Ticket performance view — ticket revenue only, never mixed with merch.
   *
   * @param array<string, mixed> $overview
   * @param array<string, mixed> $commerce
   *
   * @return array{show: bool, kpis: list<array{label: string, value: string}>, best_ticket_type: string|null}
   */
  private function buildTicketSalesSection(
    array $overview,
    array $commerce,
    bool $isTickets,
    array $ticket_tier_rollup,
    array $ticketRows,
  ): array {
    if (!$isTickets) {
      return [
        'show' => FALSE,
        'kpis' => [],
        'best_ticket_type' => NULL,
      ];
    }

    $ticket_revenue = $this->computeTicketOnlyRevenue($commerce);
    $tickets_sold = (int) ($ticket_tier_rollup['total_sold'] ?? 0);
    $best_ticket_type = $this->findBestSellingTicketFromTiers($ticketRows);
    $kpis = [];

    if ($this->formattedAmountIsPositive($ticket_revenue)) {
      $kpis[] = [
        'label' => (string) $this->t('Ticket revenue'),
        'value' => $ticket_revenue,
      ];
    }

    if ($tickets_sold > 0) {
      $kpis[] = [
        'label' => (string) $this->t('Tickets sold'),
        'value' => (string) $tickets_sold,
      ];
    }

    if ($best_ticket_type !== NULL) {
      $kpis[] = [
        'label' => (string) $this->t('Best performing ticket'),
        'value' => $best_ticket_type,
      ];
    }

    return [
      'show' => $kpis !== [],
      'kpis' => $kpis,
      'best_ticket_type' => $best_ticket_type,
    ];
  }

  /**
   * Ancillary revenue view — merchandise and add-ons only.
   *
   * @param array<string, mixed> $commerce
   *
   * @return array{show: bool, kpis: list<array{label: string, value: string}>, product_rows: list<array{label: string, category: string, items_sold: int, revenue: string}>}
   */
  private function buildMerchandiseSection(array $commerce): array {
    $has_merch_revenue = $this->formattedAmountIsPositive((string) ($commerce['merchandise_revenue_display'] ?? '—'));
    $has_addon_revenue = $this->formattedAmountIsPositive((string) ($commerce['addon_revenue_display'] ?? '—'));
    $has_extras_revenue = $this->formattedAmountIsPositive((string) ($commerce['extras_revenue'] ?? '—'));
    $extras_items = (int) ($commerce['extras_items_sold'] ?? 0);
    $orders_with_extras = (int) ($commerce['orders_with_extras'] ?? 0);
    $top_extra = (string) ($commerce['top_extras_category'] ?? '—');
    $product_rows = is_array($commerce['extras_product_rows'] ?? NULL) ? $commerce['extras_product_rows'] : [];

    $show = $extras_items > 0
      || $orders_with_extras > 0
      || $has_merch_revenue
      || $has_addon_revenue
      || $has_extras_revenue
      || $product_rows !== [];

    if (!$show) {
      return [
        'show' => FALSE,
        'kpis' => [],
        'product_rows' => [],
      ];
    }

    $kpis = [];
    if ($has_merch_revenue) {
      $kpis[] = [
        'label' => (string) $this->t('Merchandise revenue'),
        'value' => (string) $commerce['merchandise_revenue_display'],
      ];
    }
    if ($has_addon_revenue) {
      $kpis[] = [
        'label' => (string) $this->t('Add-on revenue'),
        'value' => (string) $commerce['addon_revenue_display'],
      ];
    }
    if ($orders_with_extras > 0) {
      $kpis[] = [
        'label' => (string) $this->t('Orders with extras'),
        'value' => (string) $orders_with_extras,
      ];
    }
    if ($top_extra !== '—' && $top_extra !== '') {
      $kpis[] = [
        'label' => (string) $this->t('Best performing extra'),
        'value' => $top_extra,
      ];
    }

    return [
      'show' => $kpis !== [] || $product_rows !== [],
      'kpis' => $kpis,
      'product_rows' => $product_rows,
    ];
  }

  /**
   * RSVP-only event health when there is no paid sales section.
   *
   * @param array<string, mixed> $overview
   *
   * @return array{show: bool, kpis: list<array{label: string, value: string}>}
   */
  private function buildRsvpHealthSection(array $overview, bool $isRsvp, bool $allSalesVisible): array {
    if (!$isRsvp || $allSalesVisible) {
      return [
        'show' => FALSE,
        'kpis' => [],
      ];
    }

    $rsvps = is_array($overview['rsvps'] ?? NULL) ? $overview['rsvps'] : [];
    $attendees = is_array($overview['attendees'] ?? NULL) ? $overview['attendees'] : [];
    $kpis = [];

    $confirmed = (int) ($rsvps['confirmed'] ?? 0);
    if ($confirmed > 0) {
      $kpis[] = [
        'label' => (string) $this->t('RSVPs'),
        'value' => (string) $confirmed,
      ];
    }

    $waitlisted = (int) ($rsvps['waitlisted'] ?? 0);
    if ($waitlisted > 0) {
      $kpis[] = [
        'label' => (string) $this->t('Waitlist'),
        'value' => (string) $waitlisted,
      ];
    }

    $checkins = (int) ($rsvps['checkins'] ?? $attendees['checked_in'] ?? 0);
    if ($checkins > 0) {
      $kpis[] = [
        'label' => (string) $this->t('Check-ins'),
        'value' => (string) $checkins,
      ];
    }

    return [
      'show' => $kpis !== [],
      'kpis' => $kpis,
    ];
  }

  /**
   * Ticket-only revenue from tier rollup (excludes merchandise and add-ons).
   *
   * @param array<string, mixed> $commerce
   */
  private function computeTicketOnlyRevenue(array $commerce): string {
    $ticket_revenue = (string) ($commerce['ticket_revenue'] ?? '$0.00');
    return $this->formattedAmountIsPositive($ticket_revenue) ? $ticket_revenue : '$0.00';
  }

  /**
   * Total sold units across all vendor-revenue-eligible order lines.
   *
   * @param array<string, mixed> $sales
   */
  private function computeTotalItemsSold(array $sales): int {
    // tickets_sold already counts tickets, merchandise, and add-ons (see TicketSalesService).
    return (int) ($sales['tickets_sold'] ?? 0);
  }

  /**
   * Ticket-type mix from mel_ticket tiers only (excludes merch and add-ons).
   *
   * @param list<TicketTypeInterface> $ticketRows
   *
   * @return array{labels: list<string>, sold: list<int>}
   */
  private function buildTicketTierMix(array $ticketRows): array {
    $labels = [];
    $sold = [];

    foreach ($ticketRows as $tier) {
      if (!$tier instanceof TicketTypeInterface) {
        continue;
      }
      $metrics = $this->ticketTierAnalytics->buildTierMetrics($tier);
      $count = (int) ($metrics['sold'] ?? 0);
      if ($count <= 0) {
        continue;
      }
      $label = trim((string) $tier->label());
      if ($label === '') {
        continue;
      }
      $labels[] = $label;
      $sold[] = $count;
    }

    return [
      'labels' => $labels,
      'sold' => $sold,
    ];
  }

  /**
   * Ticket tier rows for the collapsed breakdown (ticket-only, not Commerce extras).
   *
   * @param list<TicketTypeInterface> $ticketRows
   *
   * @return list<array{label: string, sold: int, available: int|string, revenue: string}>
   */
  private function buildTicketTierBreakdownDisplay(array $ticketRows): array {
    $rows = [];

    foreach ($ticketRows as $tier) {
      if (!$tier instanceof TicketTypeInterface) {
        continue;
      }
      $metrics = $this->ticketTierAnalytics->buildTierMetrics($tier);
      $sold = (int) ($metrics['sold'] ?? 0);
      if ($sold <= 0) {
        continue;
      }
      $label = trim((string) $tier->label());
      if ($label === '') {
        continue;
      }

      $revenue = '—';
      if (is_array($metrics['revenue'] ?? NULL)) {
        $revenue = ($metrics['revenue']['currency_code'] ?? '')
          . ' '
          . number_format((float) ($metrics['revenue']['number'] ?? 0), 2);
        $revenue = trim($revenue);
      }

      $remaining = $metrics['remaining'] ?? NULL;
      $available = $remaining === NULL ? 'Unlimited' : $remaining;

      $rows[] = [
        'label' => $label,
        'sold' => $sold,
        'available' => $available,
        'revenue' => $revenue !== '' ? $revenue : '—',
      ];
    }

    return $rows;
  }

  /**
   * Daily ticket units sold (ticket-tier variations only).
   *
   * @param list<TicketTypeInterface> $ticketRows
   *
   * @return list<array{date: string, tickets: int}>
   */
  private function buildTicketOnlyDailyUnitsSeries(NodeInterface $event, array $ticketRows): array {
    $variationIds = $this->collectTicketVariationIds($ticketRows);
    if ($variationIds === []) {
      return [];
    }

    $days = [];
    for ($i = 13; $i >= 0; $i--) {
      $date = date('Y-m-d', strtotime("-{$i} days"));
      $days[$date] = 0;
    }

    try {
      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
      $orderItems = $orderItemStorage->loadByProperties([
        'field_target_event' => (int) $event->id(),
      ]);
      $orderStorage = $this->entityTypeManager->getStorage('commerce_order');

      foreach ($orderItems as $item) {
        if (!$item instanceof OrderItemInterface) {
          continue;
        }

        $purchased = $item->getPurchasedEntity();
        if (!$purchased || !in_array((int) $purchased->id(), $variationIds, TRUE)) {
          continue;
        }

        if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
          continue;
        }

        $order_id = $item->get('order_id')->target_id;
        if (!$order_id) {
          continue;
        }

        $order = $orderStorage->load($order_id);
        if (!$order || $order->getState()->getId() !== 'completed') {
          continue;
        }

        $completedTime = $order->getCompletedTime() ?? $order->getChangedTime();
        $date = date('Y-m-d', (int) $completedTime);
        if (!isset($days[$date])) {
          continue;
        }

        $days[$date] += (int) $item->getQuantity();
      }
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_vendor')->warning('Analytics ticket daily series failed for event @nid: @message', [
        '@nid' => (string) $event->id(),
        '@message' => $e->getMessage(),
      ]);
    }

    $series = [];
    foreach ($days as $date => $tickets) {
      $series[] = [
        'date' => date('M j', strtotime($date)),
        'tickets' => $tickets,
      ];
    }

    return $series;
  }

  /**
   * Commerce variation IDs mapped to ticket tiers for this event.
   *
   * @param list<TicketTypeInterface> $ticketRows
   *
   * @return list<int>
   */
  private function collectTicketVariationIds(array $ticketRows): array {
    $ids = [];
    foreach ($ticketRows as $tier) {
      if (!$tier instanceof TicketTypeInterface) {
        continue;
      }
      if ($tier->get('commerce_variation')->isEmpty()) {
        continue;
      }
      $variationId = (int) $tier->get('commerce_variation')->target_id;
      if ($variationId > 0) {
        $ids[$variationId] = $variationId;
      }
    }
    return array_values($ids);
  }

  /**
   * Finds the ticket tier label with the highest sold count.
   *
   * @param list<TicketTypeInterface> $ticketRows
   */
  private function findBestSellingTicketFromTiers(array $ticketRows): ?string {
    $best_label = NULL;
    $best_sold = 0;

    foreach ($ticketRows as $tier) {
      if (!$tier instanceof TicketTypeInterface) {
        continue;
      }
      $metrics = $this->ticketTierAnalytics->buildTierMetrics($tier);
      $sold = (int) ($metrics['sold'] ?? 0);
      if ($sold <= $best_sold) {
        continue;
      }
      $label = trim((string) $tier->label());
      if ($label === '') {
        continue;
      }
      $best_sold = $sold;
      $best_label = $label;
    }

    return $best_label;
  }

  /**
   * Total tickets sold across configured tiers.
   *
   * @param list<TicketTypeInterface> $ticketRows
   */
  private function countTicketsSoldFromTiers(array $ticketRows): int {
    $total = 0;
    foreach ($ticketRows as $tier) {
      if (!$tier instanceof TicketTypeInterface) {
        continue;
      }
      $metrics = $this->ticketTierAnalytics->buildTierMetrics($tier);
      $total += (int) ($metrics['sold'] ?? 0);
    }
    return $total;
  }

  /**
   * Event-type-aware Boost Performance surface (Reach + Conversion KPI groups).
   *
   * @param array<string, mixed> $boost_metrics
   * @param array<string, mixed> $boost
   *
   * @return array{
   *   event_type: string,
   *   show_metrics: bool,
   *   show_cta: bool,
   *   intro: string,
   *   reach: array{show: bool, kpis: list<array{label: string, value: string}>},
   *   conversion: array{
   *     show: bool,
   *     paid: array{show: bool, kpis: list<array{label: string, value: string}>},
   *     rsvp: array{show: bool, kpis: list<array{label: string, value: string}>},
   *   },
   *   outcomes: array{
   *     show: bool,
   *     paid: array{show: bool, kpis: list<array{label: string, value: string}>},
   *     rsvp: array{show: bool, kpis: list<array{label: string, value: string}>},
   *   },
   *   footnote: string|null,
   * }
   */
  private function buildBoostPerformanceSection(
    array $boost_metrics,
    array $boost,
    bool $isTickets,
    bool $isRsvp,
  ): array {
    $event_type = 'paid';
    if ($isTickets && $isRsvp) {
      $event_type = 'hybrid';
    }
    elseif ($isRsvp) {
      $event_type = 'rsvp';
    }

    $boost_active = !empty($boost['active']);
    $impressions = (int) ($boost_metrics['impressions'] ?? 0);
    $clicks = (int) ($boost_metrics['clicks'] ?? 0);
    $ctr = (float) ($boost_metrics['ctr'] ?? 0.0);
    $ctr_pct = number_format($ctr * 100, 2) . '%';

    $reach_kpis = [];
    if ($boost_active || $impressions > 0) {
      $reach_kpis[] = [
        'label' => (string) $this->t('Impressions'),
        'value' => (string) $impressions,
      ];
    }
    if ($boost_active || $clicks > 0) {
      $reach_kpis[] = [
        'label' => (string) $this->t('Clicks'),
        'value' => (string) $clicks,
      ];
    }
    if ($boost_active || $impressions > 0 || $clicks > 0) {
      $reach_kpis[] = [
        'label' => (string) $this->t('CTR'),
        'value' => $ctr_pct,
      ];
    }

    $paid_kpis = [];
    if ($isTickets) {
      $revenue_during_boost = (float) ($boost_metrics['revenue_during_boost'] ?? 0.0);
      $orders_during_boost = (int) ($boost_metrics['orders_during_boost'] ?? 0);
      $tickets_during_boost = (int) ($boost_metrics['tickets_during_boost'] ?? 0);

      if ($revenue_during_boost > 0.0) {
        $paid_kpis[] = [
          'label' => (string) $this->t('Revenue during Boost'),
          'value' => (string) ($boost_metrics['sales_during_boost'] ?? '$0.00'),
        ];
      }
      if ($orders_during_boost > 0) {
        $paid_kpis[] = [
          'label' => (string) $this->t('Orders during Boost'),
          'value' => (string) $orders_during_boost,
        ];
      }
      if ($tickets_during_boost > 0) {
        $paid_kpis[] = [
          'label' => (string) $this->t('Tickets during Boost'),
          'value' => (string) $tickets_during_boost,
        ];
      }
    }

    $rsvp_kpis = [];
    if ($isRsvp) {
      $rsvps_during_boost = (int) ($boost_metrics['rsvps_during_boost'] ?? 0);
      $donation_revenue = (float) ($boost_metrics['donation_revenue_during_boost'] ?? 0.0);
      $average_donation = (float) ($boost_metrics['average_donation_during_boost'] ?? 0.0);

      if ($rsvps_during_boost > 0) {
        $rsvp_kpis[] = [
          'label' => (string) $this->t('RSVPs during Boost'),
          'value' => (string) $rsvps_during_boost,
        ];
      }
      if ($donation_revenue > 0.0) {
        $rsvp_kpis[] = [
          'label' => (string) $this->t('Donation revenue during Boost'),
          'value' => (string) ($boost_metrics['donation_revenue_during_boost_formatted'] ?? '$0.00'),
        ];
      }
      if ($average_donation > 0.0) {
        $rsvp_kpis[] = [
          'label' => (string) $this->t('Average donation during Boost'),
          'value' => (string) ($boost_metrics['average_donation_during_boost_formatted'] ?? '$0.00'),
        ];
      }
    }

    $outcomes = $this->buildBoostOutcomeMetrics($boost_metrics, $isTickets, $isRsvp);

    $show_metrics = $reach_kpis !== [] || $paid_kpis !== [] || $rsvp_kpis !== [] || $outcomes['show'];
    $conversion_show = $paid_kpis !== [] || $rsvp_kpis !== [];
    $outcomes_show = $outcomes['show'];

    $intro = (string) $this->t('Boost puts your event in front of more people.');
    if ($boost_active) {
      $intro = match ($event_type) {
        'rsvp' => (string) $this->t('Was Boost worth it? See how many people discovered your event and RSVP’d while it was boosted.'),
        'hybrid' => (string) $this->t('Was Boost worth it? See reach and conversions from tickets and RSVPs during your Boost.'),
        default => (string) $this->t('Was Boost worth it? See how many people discovered your event and bought tickets while it was boosted.'),
      };
    }

    $footnote = NULL;
    if ($conversion_show || $outcomes_show) {
      $footnote = (string) $this->t('Metrics reflect activity during your Boost windows — timing overlap, not guaranteed cause.');
    }

    return [
      'event_type' => $event_type,
      'show_metrics' => $show_metrics,
      'show_cta' => !$boost_active && !$show_metrics,
      'intro' => $intro,
      'reach' => [
        'show' => $reach_kpis !== [],
        'kpis' => $reach_kpis,
      ],
      'conversion' => [
        'show' => $conversion_show,
        'paid' => [
          'show' => $paid_kpis !== [],
          'kpis' => $paid_kpis,
        ],
        'rsvp' => [
          'show' => $rsvp_kpis !== [],
          'kpis' => $rsvp_kpis,
        ],
      ],
      'outcomes' => $outcomes,
      'footnote' => $footnote,
    ];
  }

  /**
   * Phase 4 — ROI and conversion-rate metrics from existing Boost aggregates.
   *
   * @param array<string, mixed> $boost_metrics
   *
   * @return array{
   *   show: bool,
   *   paid: array{show: bool, kpis: list<array{label: string, value: string}>},
   *   rsvp: array{show: bool, kpis: list<array{label: string, value: string}>},
   * }
   */
  private function buildBoostOutcomeMetrics(
    array $boost_metrics,
    bool $isTickets,
    bool $isRsvp,
  ): array {
    $clicks = (int) ($boost_metrics['clicks'] ?? 0);
    $boost_spend = $this->parseFormattedAmount((string) ($boost_metrics['spend'] ?? '$0.00'));

    $paid_kpis = [];
    if ($isTickets) {
      $orders_during_boost = (int) ($boost_metrics['orders_during_boost'] ?? 0);
      $revenue_during_boost = (float) ($boost_metrics['revenue_during_boost'] ?? 0.0);
      $sales_reference = (string) ($boost_metrics['sales_during_boost'] ?? '$0.00');

      if ($clicks > 0 && $orders_during_boost > 0) {
        $paid_kpis[] = [
          'label' => (string) $this->t('Orders from Boost clicks'),
          'value' => $this->formatBoostConversionRate($orders_during_boost, $clicks),
        ];
      }
      if ($clicks > 0 && $orders_during_boost > 0 && $revenue_during_boost > 0.0) {
        $paid_kpis[] = [
          'label' => (string) $this->t('Revenue per Boost click'),
          'value' => $this->formatAmountLikeReference($sales_reference, $revenue_during_boost / $clicks),
        ];
      }
      if ($boost_spend > 0.0 && $revenue_during_boost > 0.0) {
        $paid_kpis[] = [
          'label' => (string) $this->t('Revenue compared with Boost spend'),
          'value' => $this->formatBoostReturnRatio($revenue_during_boost, $boost_spend),
        ];
      }
    }

    $rsvp_kpis = [];
    if ($isRsvp) {
      $rsvps_during_boost = (int) ($boost_metrics['rsvps_during_boost'] ?? 0);
      $donation_revenue = (float) ($boost_metrics['donation_revenue_during_boost'] ?? 0.0);
      $donation_reference = (string) ($boost_metrics['donation_revenue_during_boost_formatted'] ?? '$0.00');

      if ($clicks > 0 && $rsvps_during_boost > 0) {
        $rsvp_kpis[] = [
          'label' => (string) $this->t('RSVPs from Boost clicks'),
          'value' => $this->formatBoostConversionRate($rsvps_during_boost, $clicks),
        ];
      }
      if ($rsvps_during_boost > 0 && $donation_revenue > 0.0) {
        $rsvp_kpis[] = [
          'label' => (string) $this->t('Donation per RSVP'),
          'value' => $this->formatAmountLikeReference($donation_reference, $donation_revenue / $rsvps_during_boost),
        ];
      }
      if ($boost_spend > 0.0 && $donation_revenue > 0.0) {
        $rsvp_kpis[] = [
          'label' => (string) $this->t('Donations compared with Boost spend'),
          'value' => $this->formatBoostReturnRatio($donation_revenue, $boost_spend),
        ];
      }
    }

    return [
      'show' => $paid_kpis !== [] || $rsvp_kpis !== [],
      'paid' => [
        'show' => $paid_kpis !== [],
        'kpis' => $paid_kpis,
      ],
      'rsvp' => [
        'show' => $rsvp_kpis !== [],
        'kpis' => $rsvp_kpis,
      ],
    ];
  }

  /**
   * Formats a click-to-outcome conversion rate (never divides by zero).
   */
  private function formatBoostConversionRate(int $conversions, int $clicks): string {
    if ($clicks <= 0 || $conversions <= 0) {
      return '—';
    }
    return number_format(($conversions / $clicks) * 100, 1) . '%';
  }

  /**
   * Formats outcome revenue relative to Boost spend as a calm multiplier.
   */
  private function formatBoostReturnRatio(float $outcome_revenue, float $boost_spend): string {
    if ($boost_spend <= 0.0 || $outcome_revenue <= 0.0) {
      return '—';
    }
    return number_format($outcome_revenue / $boost_spend, 1) . '×';
  }

  /**
   * Placement Performance — aggregated placement cards (sorted by reach).
   *
   * @param array<string, mixed> $boost_metrics
   *
   * @return array{
   *   show: bool,
   *   cards: list<array{
   *     key: string,
   *     label: string,
   *     impressions: int,
   *     clicks: int,
   *     ctr: float,
   *     ctr_display: string,
   *     performance_label: string,
   *     performance_badge: string,
   *   }>,
   * }
   */
  private function buildPlacementPerformanceSection(array $boost_metrics): array {
    $raw_placements = is_array($boost_metrics['placements'] ?? NULL) ? $boost_metrics['placements'] : [];
    if ($raw_placements === []) {
      return [
        'show' => FALSE,
        'cards' => [],
      ];
    }

    $aggregated = [];
    foreach ($raw_placements as $row) {
      if (!is_array($row)) {
        continue;
      }
      $key = trim((string) ($row['placement'] ?? ''));
      if ($key === '') {
        continue;
      }
      $impressions = (int) ($row['impressions'] ?? 0);
      $clicks = (int) ($row['clicks'] ?? 0);
      if ($impressions <= 0 && $clicks <= 0) {
        continue;
      }
      if (!isset($aggregated[$key])) {
        $aggregated[$key] = [
          'impressions' => 0,
          'clicks' => 0,
        ];
      }
      $aggregated[$key]['impressions'] += $impressions;
      $aggregated[$key]['clicks'] += $clicks;
    }

    if ($aggregated === []) {
      return [
        'show' => FALSE,
        'cards' => [],
      ];
    }

    $cards = [];
    foreach ($aggregated as $key => $metrics) {
      $impressions = (int) $metrics['impressions'];
      $clicks = (int) $metrics['clicks'];
      $ctr = $impressions > 0 ? ($clicks / $impressions) : 0.0;
      $indicator = $this->getPlacementPerformanceIndicator($ctr);
      $cards[] = [
        'key' => $key,
        'label' => $this->formatBoostPlacementLabel($key),
        'impressions' => $impressions,
        'clicks' => $clicks,
        'ctr' => $ctr,
        'ctr_display' => number_format($ctr * 100, 1) . '%',
        'performance_label' => $indicator['label'],
        'performance_badge' => $indicator['badge'],
      ];
    }

    usort($cards, static function (array $a, array $b): int {
      $impression_cmp = $b['impressions'] <=> $a['impressions'];
      return $impression_cmp !== 0 ? $impression_cmp : ($b['clicks'] <=> $a['clicks']);
    });

    return [
      'show' => TRUE,
      'cards' => $cards,
    ];
  }

  /**
   * Translates a Boost placement machine key to organiser-facing copy.
   */
  private function formatBoostPlacementLabel(string $placement): string {
    $known = [
      'homepage_discover' => (string) $this->t('Homepage Discover'),
      'search_results' => (string) $this->t('Search Results'),
      'category_music' => (string) $this->t('Music Category'),
      'category_lgbtq' => (string) $this->t('LGBTQ+ Category'),
      'listing_featured' => (string) $this->t('Featured Listings'),
    ];
    if (isset($known[$placement])) {
      return $known[$placement];
    }

    if (str_starts_with($placement, 'category_')) {
      $slug = substr($placement, strlen('category_'));
      return (string) $this->t('@category Category', [
        '@category' => $this->formatBoostPlacementSlug($slug),
      ]);
    }

    if (str_starts_with($placement, 'listing_')) {
      $slug = substr($placement, strlen('listing_'));
      return (string) $this->t('@listing Listings', [
        '@listing' => $this->formatBoostPlacementSlug($slug),
      ]);
    }

    if (str_starts_with($placement, 'homepage_')) {
      $slug = substr($placement, strlen('homepage_'));
      return (string) $this->t('Homepage @section', [
        '@section' => $this->formatBoostPlacementSlug($slug),
      ]);
    }

    return $this->formatBoostPlacementSlug($placement);
  }

  /**
   * Title-cases an underscore placement slug segment.
   */
  private function formatBoostPlacementSlug(string $slug): string {
    $slug = trim(str_replace('_', ' ', strtolower($slug)));
    if ($slug === '') {
      return (string) $this->t('Placement');
    }
    if ($slug === 'lgbtq') {
      return 'LGBTQ+';
    }
    return ucwords($slug);
  }

  /**
   * CTR-based performance indicator using existing badge variants.
   *
   * @return array{label: string, badge: string}
   */
  private function getPlacementPerformanceIndicator(float $ctr): array {
    if ($ctr >= 0.05) {
      return [
        'label' => (string) $this->t('Strong'),
        'badge' => 'success',
      ];
    }
    if ($ctr >= 0.02) {
      return [
        'label' => (string) $this->t('Moderate'),
        'badge' => 'warning',
      ];
    }
    return [
      'label' => (string) $this->t('Needs attention'),
      'badge' => 'danger',
    ];
  }

  /**
   * Boost decision support section (Stage 3).
   *
   * @param array<string, mixed> $boost_metrics
   * @param array<string, mixed> $boost
   * @param array{
   *   show: bool,
   *   cards: list<array<string, mixed>>,
   * } $placement_performance_section
   *
   * @return array<string, mixed>
   */
  private function buildBoostDecisionSupportSection(
    array $boost_metrics,
    array $boost,
    bool $isTickets,
    bool $isRsvp,
    array $placement_performance_section,
    ?string $boost_page_url,
  ): array {
    if (!$this->boostDecisionSupport) {
      return [
        'show' => FALSE,
        'confidence' => ['show' => FALSE, 'level' => 'low', 'label' => '', 'badge' => 'muted'],
        'health' => ['show' => FALSE, 'score' => '', 'label' => '', 'explanation' => '', 'badge' => 'muted'],
        'insights' => [],
        'recommendations' => [],
      ];
    }

    return $this->boostDecisionSupport->build(
      $boost_metrics,
      $boost,
      $isTickets,
      $isRsvp,
      $placement_performance_section,
      $boost_page_url,
    );
  }

  /**
   * Rules-based next-step recommendations (max 3).
   *
   * Boost-specific recommendations come from BoostDecisionSupportService.
   * Operational fallbacks fill remaining slots when fewer than three Boost recs exist.
   *
   * @param array<string, mixed> $overview
   * @param array<string, mixed> $commerce
   * @param array<string, mixed> $boost
   * @param array<string, mixed> $boost_decision_support
   *
   * @return list<string>
   */
  private function buildRecommendations(
    array $overview,
    array $commerce,
    bool $isTickets,
    bool $isRsvp,
    array $boost,
    ?string $boost_page_url,
    ?float $capacity_pct,
    ?string $public_event_url,
    array $ticketRows,
    array $boost_decision_support = [],
  ): array {
    $recs = is_array($boost_decision_support['recommendations'] ?? NULL)
      ? $boost_decision_support['recommendations']
      : [];

    if (count($recs) < 3 && $isTickets) {
      $best_ticket = $this->findBestSellingTicketFromTiers($ticketRows);
      if ($best_ticket !== NULL) {
        $recs[] = (string) $this->t('@ticket tickets are selling fastest.', ['@ticket' => $best_ticket]);
      }
    }

    if (count($recs) < 3 && !empty($commerce['show_extras_section']) && (int) ($commerce['extras_items_sold'] ?? 0) > 0) {
      $recs[] = (string) $this->t('Merchandise is selling well — consider adding another item.');
    }

    if (count($recs) < 3 && $isTickets && $capacity_pct !== NULL && $capacity_pct >= 80.0) {
      $remaining_pct = max(0, 100 - $capacity_pct);
      if ($capacity_pct >= 95.0 || $remaining_pct <= 5) {
        $recs[] = (string) $this->t('Tickets are nearly sold out — consider adding another tier or increasing capacity.');
      }
      else {
        $recs[] = (string) $this->t('Only @pct% of tickets remain.', ['@pct' => (string) round($remaining_pct)]);
      }
    }

    if (count($recs) < 3 && empty($boost['active']) && $boost_page_url !== NULL) {
      $recs[] = (string) $this->t('Consider boosting your event to increase visibility.');
    }

    if (count($recs) < 3 && $isTickets && $this->countTicketsSoldFromTiers($ticketRows) === 0 && $public_event_url !== NULL) {
      $recs[] = (string) $this->t('Share your public event link to start driving ticket sales.');
    }

    return array_slice(array_values(array_unique($recs)), 0, 3);
  }

  /**
   * Builds doughnut chart config for consolidated revenue breakdown.
   *
   * @param array<string, mixed> $overview
   * @param array<string, mixed> $commerce
   *
   * @return array<string, mixed>|null
   */
  private function buildRevenueBreakdownChart(array $overview, array $commerce, bool $isTickets): ?array {
    $labels = [];
    $data = [];
    $donations = is_array($overview['donations'] ?? NULL) ? $overview['donations'] : [];

    if ($isTickets) {
      $ticket_amount = $this->parseFormattedAmount((string) ($commerce['ticket_revenue'] ?? '—'));
      if ($ticket_amount > 0) {
        $labels[] = (string) $this->t('Tickets');
        $data[] = $ticket_amount;
      }
    }

    $merch_amount = $this->parseFormattedAmount((string) ($commerce['merchandise_revenue_display'] ?? '—'));
    if ($merch_amount > 0) {
      $labels[] = (string) $this->t('Merchandise');
      $data[] = $merch_amount;
    }

    $addon_amount = $this->parseFormattedAmount((string) ($commerce['addon_revenue_display'] ?? '—'));
    if ($addon_amount > 0) {
      $labels[] = (string) $this->t('Add-ons');
      $data[] = $addon_amount;
    }

    $donation_total = (float) ($donations['total'] ?? 0);
    if ($donation_total > 0) {
      $labels[] = (string) $this->t('Donations');
      $data[] = $donation_total;
    }

    if ($labels === []) {
      return NULL;
    }

    return [
      'type' => 'doughnut',
      'labels' => $labels,
      'datasets' => [
        [
          'label' => (string) $this->t('Revenue'),
          'data' => $data,
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

  /**
   * Formats ticket-tier rollup revenue for analytics KPIs and charts.
   *
   * Uses completed ticket-backed order item totals (TicketVariationSoldService),
   * not overview gross minus extras (which mixes total vs adjusted prices).
   *
   * @param array<string, mixed> $ticket_tier_rollup
   */
  private function formatTicketRevenueFromTierRollup(array $ticket_tier_rollup): string {
    $gross = $ticket_tier_rollup['gross_revenue'] ?? NULL;
    if (!is_array($gross) || !isset($gross['number'], $gross['currency_code'])) {
      return '$0.00';
    }

    $amount = (float) $gross['number'];
    if ($amount <= 0.0) {
      return '$0.00';
    }

    $currency = strtoupper((string) $gross['currency_code']);
    $formatted = number_format($amount, 2, '.', ',');
    if ($currency === 'AUD') {
      return '$' . $formatted;
    }

    return $currency . ' ' . $formatted;
  }

  /**
   * Parses a formatted currency string into a float amount.
   */
  private function parseFormattedAmount(string $formatted): float {
    if ($formatted === '—' || $formatted === '') {
      return 0.0;
    }
    $clean = preg_replace('/[^\d.]/', '', $formatted);
    if ($clean === NULL || $clean === '') {
      return 0.0;
    }
    return (float) $clean;
  }

  /**
   * Whether a formatted revenue string represents a positive amount.
   */
  private function formattedAmountIsPositive(string $formatted): bool {
    return $this->parseFormattedAmount($formatted) > 0.0;
  }

  /**
   * Formats a numeric amount to match an existing formatted revenue string.
   */
  private function formatAmountLikeReference(string $reference, float $amount): string {
    if ($amount <= 0.0) {
      return '—';
    }
    $formatted = number_format($amount, 2, '.', ',');
    if (str_starts_with($reference, '$')) {
      return '$' . $formatted;
    }
    if (preg_match('/^([A-Z]{3})\s/', $reference, $matches) === 1) {
      return $matches[1] . ' ' . $formatted;
    }
    return '$' . $formatted;
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
