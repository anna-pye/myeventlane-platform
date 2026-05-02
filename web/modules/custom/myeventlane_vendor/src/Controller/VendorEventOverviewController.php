<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_core\Service\EventStateResolver;
use Drupal\myeventlane_event\Service\BookingFlowResolver;
use Drupal\myeventlane_boost\Service\BoostHelpContent;
use Drupal\myeventlane_pro\Service\ProActiveResolver;
use Drupal\myeventlane_donations\Service\VendorMelPctContributionService;
use Drupal\myeventlane_refunds\Service\RefundProcessor;
use Drupal\myeventlane_vendor\Service\MetricsAggregator;
use Drupal\myeventlane_vendor\Service\VendorEventTabsService;

/**
 * Event overview controller.
 */
final class VendorEventOverviewController extends VendorConsoleBaseController {

  /**
   * Constructs the controller.
   */
  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    private readonly MetricsAggregator $metricsAggregator,
    private readonly VendorEventTabsService $eventTabsService,
    private readonly BookingFlowResolver $bookingFlowResolver,
    private readonly EventStateResolver $eventDomainStateResolver,
    private readonly BoostHelpContent $boostHelpContent,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly ?ProActiveResolver $proActiveResolver = NULL,
    private readonly ?VendorMelPctContributionService $vendorMelPctContribution = NULL,
    private readonly ?RefundProcessor $refundProcessor = NULL,
  ) {
    parent::__construct($domain_detector, $current_user, $messenger);
  }

  /**
   * Displays the overview tab for an event.
   */
  public function overview(NodeInterface $event): array {
    $this->assertEventOwnership($event);
    $tabs = $this->eventTabsService->getTabs($event, 'overview');
    $overview = $this->metricsAggregator->getEventOverview($event);
    if ($event->hasField('field_mel_sup_mode')) {
      $mode = (string) ($event->get('field_mel_sup_mode')->value ?? 'none');
      $checkout = (string) ($event->get('field_mel_sup_chk')->value ?? 'none');
      $overview['mel_platform_support'] = [
        'mode' => $mode,
        'checkout' => $checkout,
        'amount' => !$event->get('field_mel_sup_amt')->isEmpty()
          ? (string) $event->get('field_mel_sup_amt')->value
          : '',
        'percent' => !$event->get('field_mel_sup_pct')->isEmpty()
          ? (string) $event->get('field_mel_sup_pct')->value
          : '',
      ];
      if ($this->vendorMelPctContribution && $mode === 'percent') {
        $overview['mel_pct_contribution'] = $this->vendorMelPctContribution->getEventVendorSummary($event);
      }
    }
    $vendor = $this->getCurrentVendorOrNull();
    $vendorStore = ($vendor && $vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty())
      ? $vendor->get('field_vendor_store')->entity
      : NULL;
    $overview['is_pro'] = $vendorStore instanceof \Drupal\commerce_store\Entity\StoreInterface && $this->proActiveResolver
      ? $this->proActiveResolver->isStoreProActive($vendorStore)
      : FALSE;
    $bookingMode = $this->bookingFlowResolver->getBookingMode($event);
    $displayPricing = $this->bookingFlowResolver->getDisplayPricing($event);
    $overview['cta_type'] = $bookingMode === BookingFlowResolver::MODE_UNAVAILABLE ? 'none' : $bookingMode;
    $overview['display_pricing'] = is_array($displayPricing)
      ? (string) ($displayPricing['label'] ?? '')
      : '';
    if ($overview['is_pro']) {
      $sales = is_array($overview['sales'] ?? NULL) ? $overview['sales'] : [];
      $refundRatePercent = (float) ($sales['refund_rate_percent'] ?? 0.0);
      $analyticsUrl = $this->safeRouteUrl('myeventlane_vendor.console.event_analytics', ['event' => $event->id()]);
      $overview['pro_refund_analytics'] = [
        'refund_rate_percent' => number_format($refundRatePercent, 1) . '%',
        'breakdown' => [
          ['type' => (string) $this->t('Ticket refunds'), 'amount' => (string) ($sales['refunded'] ?? '$0.00')],
          ['type' => (string) $this->t('Donation refunds'), 'amount' => (string) ($sales['donation_refunded'] ?? '$0.00')],
        ],
        'export_csv_url' => $analyticsUrl ?? '#',
        'anomaly_message' => (string) $this->t('No anomalies detected'),
        'empty_insights_message' => (string) $this->t('Financial insights will appear as bookings and payouts build up.'),
      ];
    }
    $charts = $this->metricsAggregator->getEventCharts($event);
    $boostHelp = [
      'tooltips' => $this->boostHelpContent->getInlineTooltipCopy(),
      'pdf_url' => Url::fromRoute('myeventlane_boost.performance_guide_pdf')->toString(),
    ];
    $refundAnalytics = NULL;
    if ($this->refundProcessor) {
      $eventId = (int) $event->id();
      $refundAnalytics = [
        '#theme' => 'mel_refund_analytics',
        '#data' => $this->refundProcessor->getEventRefundStats($eventId) + [
          'insights' => $this->refundProcessor->getRefundInsights($eventId),
        ],
        '#attached' => [
          'library' => ['myeventlane_refunds/mel_refund_ui'],
        ],
      ];
    }

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

    $boost_chart = $overview['boost_metrics']['chart_data'] ?? NULL;
    if ($boost_chart) {
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

    $meta = $this->buildConsoleMetaLine($event);
    $header_actions = $this->buildHeaderActions($event);
    $mission = $this->buildMissionControlVariables($event, $overview);
    $pro_upgrade_url = $this->safeRouteUrl('myeventlane_pro.overview');

    $body = [
      '#theme' => 'myeventlane_vendor_event_overview',
      '#event' => $event,
      '#overview' => $overview,
      '#charts' => $charts,
      '#boost_help' => $boostHelp,
      '#quick_actions' => $mission['quick_actions'],
      '#performance_stats' => $mission['performance_stats'],
      '#performance_empty' => $mission['performance_empty'],
      '#audience_rows' => $mission['audience_rows'],
      '#audience_empty_copy' => $mission['audience_empty_copy'],
      '#boost_card' => $mission['boost_card'],
      '#support_card' => $mission['support_card'],
      '#show_support_card' => $mission['show_support_card'],
      '#pro_upgrade_url' => $pro_upgrade_url,
      '#refund_analytics' => $refundAnalytics,
      '#event_domain_state' => $mission['event_domain_state'],
      '#cache' => [
        'tags' => $event->getCacheTags(),
        'contexts' => $event->getCacheContexts(),
      ],
    ];

    return $this->buildVendorPage('mel_event_workspace', [
      'event' => $event,
      'tabs' => $tabs,
      'meta' => $meta,
      'header_stats' => $mission['header_stats'],
      'header_meta_line' => $mission['header_meta_line'],
      'actions' => $header_actions,
      'sidebar' => NULL,
      'content' => $body,
      '#attached' => [
        'drupalSettings' => [
          'vendorCharts' => $chart_data,
        ],
        'library' => [
          'myeventlane_vendor_theme/event_overview',
        ],
      ],
    ]);
  }

  /**
   * Builds the meta line for the console header (type • status • date).
   */
  private function buildConsoleMetaLine(NodeInterface $event): string {
    $type_label = (string) $this->t('RSVP');
    if ($event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()) {
      $val = (string) $event->get('field_event_type')->value;
      $type_label = match ($val) {
        'paid' => (string) $this->t('Ticketed'),
        'both' => (string) $this->t('RSVP + Paid'),
        'external' => (string) $this->t('External'),
        default => (string) $this->t('RSVP'),
      };
    }

    $status_label = $event->isPublished() ? (string) $this->t('Published') : (string) $this->t('Draft');
    if ($event->hasField('moderation_state') && !$event->get('moderation_state')->isEmpty()
      && (string) $event->get('moderation_state')->value === 'review') {
      $status_label = (string) $this->t('In review');
    }

    $date_part = '';
    if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
      $start_item = $event->get('field_event_start')->first();
      if ($start_item && isset($start_item->date)) {
        $date_part = $this->dateFormatter->format($start_item->date->getTimestamp(), 'custom', 'j M Y');
      }
    }
    if ($date_part === '' && $event->getCreatedTime()) {
      $date_part = $this->dateFormatter->format($event->getCreatedTime(), 'custom', 'j M Y');
    }

    $displayPricing = $this->bookingFlowResolver->getDisplayPricing($event);
    $pricing_label = is_array($displayPricing)
      ? trim((string) ($displayPricing['label'] ?? ''))
      : '';

    $parts = [$type_label];
    if ($pricing_label !== '') {
      $parts[] = $pricing_label;
    }
    $parts[] = $status_label;
    if ($date_part !== '') {
      $parts[] = $date_part;
    }
    return implode(' • ', $parts);
  }

  /**
   * Builds primary header actions (edit, view, booking, scan when ticketed).
   *
   * @return array<int, array<string, mixed>>
   */
  private function buildHeaderActions(NodeInterface $event): array {
    $actions = [
      [
        'label' => (string) $this->t('Open Event Studio'),
        'url' => Url::fromRoute('myeventlane_event_studio.edit', ['node' => $event->id()])->toString(),
        'class' => 'mel-btn--primary',
      ],
      [
        'label' => (string) $this->t('View page'),
        'url' => Url::fromRoute('entity.node.canonical', ['node' => $event->id()])->toString(),
        'class' => 'mel-btn--secondary',
        'external' => TRUE,
      ],
    ];
    $cta = $this->bookingFlowResolver->getPrimaryCta($event);
    if (
      in_array((string) ($cta['type'] ?? 'disabled'), ['internal', 'external'], TRUE)
      && !empty($cta['url'])
    ) {
      $actions[] = [
        'label' => (string) $this->t('Booking page'),
        'url' => $cta['url'],
        'class' => 'mel-btn--secondary',
        'external' => TRUE,
      ];
    }
    if ($this->eventTabsService->isTicketsEnabled($event)) {
      $scan = $this->safeRouteUrl('myeventlane_tickets.ticket_scan', ['event' => $event->id()]);
      if ($scan) {
        $actions[] = [
          'label' => (string) $this->t('Scan tickets'),
          'url' => $scan,
          'class' => 'mel-btn--secondary',
        ];
      }
    }
    return $actions;
  }

  /**
   * Builds display-ready mission control variables for the overview template.
   *
   * @param array<string, mixed> $overview
   *   Raw overview from MetricsAggregator (plus enrichments).
   *
   * @return array<string, mixed>
   */
  private function buildMissionControlVariables(NodeInterface $event, array $overview): array {
    $sales = is_array($overview['sales'] ?? NULL) ? $overview['sales'] : [];
    $attendees = is_array($overview['attendees'] ?? NULL) ? $overview['attendees'] : [];
    $rsvps = is_array($overview['rsvps'] ?? NULL) ? $overview['rsvps'] : [];
    $boost = is_array($overview['boost'] ?? NULL) ? $overview['boost'] : [];
    $boost_metrics = is_array($overview['boost_metrics'] ?? NULL) ? $overview['boost_metrics'] : [];
    $audience = is_array($overview['audience'] ?? NULL) ? $overview['audience'] : [];

    $is_tickets = $this->eventTabsService->isTicketsEnabled($event);
    $is_rsvp = $this->eventTabsService->isRsvpEnabled($event);
    $cta_type = (string) ($overview['cta_type'] ?? 'none');

    $status_label = (string) $this->t('Draft');
    if ($event->isPublished()) {
      $status_label = (string) $this->t('Published');
    }
    if ($event->hasField('moderation_state') && !$event->get('moderation_state')->isEmpty()
      && (string) $event->get('moderation_state')->value === 'review') {
      $status_label = (string) $this->t('In review');
    }

    $date_part = '';
    if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
      $start_item = $event->get('field_event_start')->first();
      if ($start_item && isset($start_item->date)) {
        $date_part = $this->dateFormatter->format($start_item->date->getTimestamp(), 'custom', 'j M Y');
      }
    }
    if ($date_part === '' && $event->getCreatedTime()) {
      $date_part = $this->dateFormatter->format($event->getCreatedTime(), 'custom', 'j M Y');
    }
    $header_meta_line = $date_part !== ''
      ? $status_label . ' • ' . $date_part
      : $status_label;

    $check_rate = (float) ($attendees['check_in_rate'] ?? 0.0);
    $check_pct = number_format($check_rate * 100, 0) . '%';
    $checked_in = (int) ($attendees['checked_in'] ?? 0);
    $check_meta = (string) $this->t('@count checked in so far', ['@count' => $checked_in]);
    if ($checked_in === 0) {
      $check_meta = (string) $this->t('No check-ins yet. When guests arrive, activity will show here.');
    }

    $gross_display = (string) ($sales['gross'] ?? '$0.00');
    $tickets_sold = (int) ($sales['tickets_sold'] ?? 0);
    $rsvp_confirmed = (int) ($rsvps['confirmed'] ?? 0);

    $kpi_sales_label = (string) $this->t('Gross sales');
    $kpi_sales_value = $gross_display;
    $kpi_sales_meta = $is_tickets
      ? (string) $this->t('Ticket revenue before refunds')
      : (string) $this->t('Paid ticket revenue (if applicable)');

    if ($cta_type === BookingFlowResolver::MODE_RSVP && !$is_tickets) {
      $kpi_sales_label = (string) $this->t('RSVP activity');
      $kpi_sales_value = (string) $rsvp_confirmed;
      $kpi_sales_meta = (string) $this->t('Confirmed RSVPs');
    }

    $kpi_volume_label = (string) $this->t('Tickets sold');
    $kpi_volume_value = (string) $tickets_sold;
    $kpi_volume_meta = (string) $this->t('Across all ticket types');
    if ($cta_type === BookingFlowResolver::MODE_RSVP || ($is_rsvp && !$is_tickets)) {
      $kpi_volume_label = (string) $this->t('RSVPs');
      $kpi_volume_value = (string) $rsvp_confirmed;
      $kpi_volume_meta = (string) $this->t('Confirmed guest responses');
    }
    elseif ($is_tickets && $is_rsvp) {
      $kpi_volume_meta = (string) $this->t('Tickets sold; RSVPs tracked separately');
    }

    $kpi_check_label = (string) $this->t('Check-in progress');
    $kpi_check_value = $check_pct;
    $kpi_check_meta = $check_meta;

    $boost_active = !empty($boost['active']);

    $header_stats = [
      [
        'label' => $kpi_sales_label,
        'value' => $kpi_sales_value,
        'meta' => $kpi_sales_meta,
      ],
      [
        'label' => $kpi_volume_label,
        'value' => $kpi_volume_value,
        'meta' => $kpi_volume_meta,
      ],
      [
        'label' => $kpi_check_label,
        'value' => $kpi_check_value,
        'meta' => $kpi_check_meta,
      ],
    ];

    $event_id = $event->id();
    $quick_actions = [];
    if ($is_tickets) {
      $quick_actions[] = [
        'title' => (string) $this->t('Scan tickets'),
        'description' => (string) $this->t('Open door mode for fast check-in at the event'),
        'url' => Url::fromRoute('myeventlane_tickets.ticket_scan', ['event' => $event_id])->toString(),
        'icon' => '⌁',
      ];
      $quick_actions[] = [
        'title' => (string) $this->t('Check-in analytics'),
        'description' => (string) $this->t('See how many people have arrived'),
        'url' => Url::fromRoute('myeventlane_tickets.ticket_checkin_analytics', ['event' => $event_id])->toString(),
        'icon' => '◉',
      ];
      $quick_actions[] = [
        'title' => (string) $this->t('Manual check-in'),
        'description' => (string) $this->t('Search or check in guests without scanning'),
        'url' => Url::fromRoute('myeventlane_tickets.ticket_checkin', ['event' => $event_id])->toString(),
        'icon' => '◎',
      ];
      $quick_actions[] = [
        'title' => (string) $this->t('Ticket workspace'),
        'description' => (string) $this->t('Manage ticket types, pricing and availability'),
        'url' => Url::fromRoute('myeventlane_vendor.console.event_tickets', ['event' => $event_id])->toString(),
        'icon' => '▣',
      ];
    }
    $quick_actions[] = [
      'title' => (string) $this->t('View attendees'),
      'description' => (string) $this->t('Browse RSVPs, tickets and guest details'),
      'url' => Url::fromRoute('myeventlane_event_attendees.vendor_list', ['node' => $event_id])->toString(),
      'icon' => '→',
    ];
    $analytics_url = $this->safeRouteUrl('myeventlane_vendor.console.event_analytics', ['event' => $event_id]);
    if ($analytics_url) {
      $quick_actions[] = [
        'title' => (string) $this->t('View analytics'),
        'description' => (string) $this->t('See sales, RSVPs and performance trends'),
        'url' => $analytics_url,
        'icon' => '↗',
      ];
    }
    if ($is_tickets) {
      $quick_actions[] = [
        'title' => (string) $this->t('Boost this event'),
        'description' => (string) $this->t('Promote your event to reach more people'),
        'url' => Url::fromRoute('myeventlane_boost.boost_page', ['node' => $event_id])->toString(),
        'icon' => '✦',
      ];
    }
    $quick_actions[] = [
      'title' => (string) $this->t('Settings'),
      'description' => (string) $this->t('Update event details, tickets and visibility'),
      'url' => Url::fromRoute('myeventlane_vendor.console.event_settings', ['event' => $event_id])->toString(),
      'icon' => '⚙',
    ];

    $conv = (float) ($sales['conversion'] ?? 0);
    $conv_display = number_format($conv * 100, 1) . '%';
    $conv_meta = $conv > 0.00001
      ? NULL
      : (string) $this->t('Will improve once traffic and availability are tracked.');

    $refunded_raw = (float) ($sales['refunded_raw'] ?? 0);
    $refund_meta = $refunded_raw <= 0.00001
      ? (string) $this->t('No refunds yet')
      : NULL;

    $orders_count = (int) ($sales['orders_count'] ?? 0);

    $performance_stats = [
      [
        'label' => (string) $this->t('Gross'),
        'value' => (string) ($sales['gross'] ?? '$0.00'),
        'meta' => NULL,
      ],
      [
        'label' => (string) $this->t('Net'),
        'value' => (string) ($sales['net'] ?? '$0.00'),
        'meta' => NULL,
      ],
      [
        'label' => (string) $this->t('Refunded'),
        'value' => (string) ($sales['refunded'] ?? '$0.00'),
        'meta' => $refund_meta,
      ],
      [
        'label' => (string) $this->t('Conversion'),
        'value' => $conv_display,
        'meta' => $conv_meta,
      ],
      [
        'label' => (string) $this->t('Tickets sold'),
        'value' => (string) $tickets_sold,
        'meta' => NULL,
      ],
    ];
    if ($is_tickets) {
      $performance_stats[] = [
        'label' => (string) $this->t('Orders'),
        'value' => (string) $orders_count,
        'meta' => NULL,
      ];
    }
    if ($is_rsvp) {
      $performance_stats[] = [
        'label' => (string) $this->t('RSVP confirmations'),
        'value' => (string) $rsvp_confirmed,
        'meta' => NULL,
      ];
    }

    $gross_raw = (float) ($sales['gross_raw'] ?? 0);
    $performance_empty = $gross_raw <= 0 && $tickets_sold === 0 && $rsvp_confirmed === 0;

    $audience_rows = [];
    foreach ($audience as $segment) {
      if (is_array($segment) && isset($segment['label'], $segment['value'])) {
        $audience_rows[] = [
          'label' => (string) $segment['label'],
          'value' => (string) $segment['value'],
        ];
      }
    }
    $audience_empty_copy = [
      'title' => (string) $this->t('No audience data yet'),
      'body' => (string) $this->t('As people follow categories or engage with your event, audience insights will appear here.'),
    ];

    $has_boost_metrics = !empty($boost_metrics) && (($boost_metrics['impressions'] ?? 0) > 0 || ($boost_metrics['clicks'] ?? 0) > 0 || ($boost_metrics['spend'] ?? '$0.00') !== '$0.00');
    $boost_url = Url::fromRoute('myeventlane_boost.boost_page', ['node' => $event_id])->toString();
    $boost_card = [
      'has_metrics' => $has_boost_metrics,
      'headline' => $has_boost_metrics ? (string) $this->t('Boost performance') : (string) $this->t('No boost activity yet'),
      'summary_line' => $has_boost_metrics
        ? (string) $this->t('Spend @spend · @imp impressions · @clk clicks', [
          '@spend' => (string) ($boost_metrics['spend'] ?? '$0.00'),
          '@imp' => number_format((int) ($boost_metrics['impressions'] ?? 0), 0),
          '@clk' => number_format((int) ($boost_metrics['clicks'] ?? 0), 0),
        ])
        : (string) $this->t('Run a boost campaign to promote this event to more people.'),
      'cta_url' => $boost_url,
      'cta_label' => (string) $this->t('Boost this event'),
      'status_chip' => $boost_active ? (string) $this->t('Active') : NULL,
    ];

    $support = is_array($overview['mel_platform_support'] ?? NULL) ? $overview['mel_platform_support'] : NULL;
    $show_support_card = $support !== NULL;
    $support_card = [
      'variant' => 'none',
      'paragraphs' => [],
      'cta' => NULL,
    ];
    if ($show_support_card) {
      $mode = (string) ($support['mode'] ?? 'none');
      $support_card['variant'] = $mode;
      $pct = is_array($overview['mel_pct_contribution'] ?? NULL) ? $overview['mel_pct_contribution'] : NULL;
      if ($mode === 'none') {
        $support_card['paragraphs'] = [
          (string) $this->t('You have not added an optional MyEventLane support pledge for this event. That is the normal default.'),
          (string) $this->t('You can add a one-time or percentage pledge when you configure tickets in the event editor.'),
        ];
        $support_card['cta'] = [
          'url' => Url::fromRoute('myeventlane_event_studio.edit', ['node' => $event_id])->toString(),
          'label' => (string) $this->t('Open event wizard'),
        ];
      }
      elseif ($mode === 'onetime') {
        $amt = (string) ($support['amount'] ?? '—');
        $support_card['paragraphs'] = [
          (string) $this->t('One-time contribution: @amt AUD', ['@amt' => $amt]),
          (string) $this->t('Checkout status: @s', ['@s' => (string) ($support['checkout'] ?? 'none')]),
        ];
      }
      else {
        $support_card['paragraphs'] = [
          (string) $this->t('Pledge a percentage of ticket revenue'),
          (string) $this->t('Contribution rate: @pct%', ['@pct' => (string) ($support['percent'] ?? '—')]),
        ];
        if ($pct) {
          $support_card['paragraphs'][] = (string) $this->t('Accrued so far: @amt', ['@amt' => (string) ($pct['accrued_formatted'] ?? '—')]);
          if (!empty($pct['detail'])) {
            $support_card['paragraphs'][] = (string) $pct['detail'];
          }
        }
      }
    }

    return [
      'header_stats' => $header_stats,
      'header_meta_line' => $header_meta_line,
      'quick_actions' => $quick_actions,
      'performance_stats' => $performance_stats,
      'performance_empty' => $performance_empty,
      'audience_rows' => $audience_rows,
      'audience_empty_copy' => $audience_empty_copy,
      'boost_card' => $boost_card,
      'support_card' => $support_card,
      'show_support_card' => $show_support_card,
      'event_domain_state' => $this->eventDomainStateResolver->getEventDomainState($event),
    ];
  }

  /**
   * Returns a URL string if the route exists, otherwise NULL.
   */
  private function safeRouteUrl(string $route, array $params = []): ?string {
    try {
      return Url::fromRoute($route, $params)->toString();
    }
    catch (\Throwable) {
      return NULL;
    }
  }

}
