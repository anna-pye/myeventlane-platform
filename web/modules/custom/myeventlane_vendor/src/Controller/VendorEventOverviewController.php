<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_event\Service\EventCtaResolver;
use Drupal\myeventlane_boost\Service\BoostHelpContent;
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
    private readonly EventCtaResolver $ctaResolver,
    private readonly BoostHelpContent $boostHelpContent,
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
    $overview['cta_type'] = $this->ctaResolver->getCtaType($event);
    $charts = $this->metricsAggregator->getEventCharts($event);
    $boostHelp = [
      'tooltips' => $this->boostHelpContent->getInlineTooltipCopy(),
      'pdf_url' => Url::fromRoute('myeventlane_boost.performance_guide_pdf')->toString(),
    ];

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

    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      'title' => $event->label() . ' — Overview',
      'tabs' => $tabs,
      'body' => [
        '#theme' => 'myeventlane_vendor_event_overview',
        '#event' => $event,
        '#overview' => $overview,
        '#charts' => $charts,
        '#boost_help' => $boostHelp,
      ],
      'meta' => [$event->bundle(), 'status' => $event->isPublished() ? 'Published' : 'Draft'],
      '#attached' => [
        'drupalSettings' => [
          'vendorCharts' => $chart_data,
        ],
      ],
    ]);
  }

}
