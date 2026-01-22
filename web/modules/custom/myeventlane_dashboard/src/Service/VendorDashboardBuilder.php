<?php

declare(strict_types=1);

namespace Drupal\myeventlane_dashboard\Service;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Drupal\commerce_store\Entity\StoreInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds complete vendor dashboard data structure.
 *
 * Composes VendorContextService, VendorMetricsService, VendorEventsService,
 * and VendorStripeService into a single dashboard-ready array.
 */
final class VendorDashboardBuilder {

  public function __construct(
    private readonly VendorContextServiceInterface $context,
    private readonly VendorMetricsServiceInterface $metrics,
    private readonly VendorEventsServiceInterface $events,
    private readonly VendorStripeServiceInterface $stripe,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly AccountProxyInterface $currentUser,
    private readonly RequestStack $requestStack,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Builds complete dashboard data for a store and date range.
   *
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The store.
   * @param array $range
   *   Date range with keys: start (timestamp), end (timestamp), label (string), key (string).
   *
   * @return array
   *   Complete dashboard data structure.
   */
  public function build(StoreInterface $store, array $range): array {
    $metrics = $this->metrics->getMetrics($store, $range);
    $events = $this->events->getDashboardEvents($store, $range);
    $stripe_status = $this->stripe->getConnectionStatus($store);
    $stripe_balance = $this->stripe->getAvailableBalanceFormatted($store);

    // Merge cache tags from all services.
    $cache_tags = array_merge(
      $metrics['cache_tags'] ?? [],
      $events['cache_tags'] ?? [],
      $stripe_status['cache_tags'] ?? []
    );

    $payload = [
      'metrics' => $metrics['items'] ?? [],
      'events' => $events['items'] ?? [],
      'events_all_url' => $events['all_url'] ?? '',
      'stripe' => [
        'status' => $stripe_status,
        'available_balance' => $stripe_balance,
      ],
      'vendor_name' => $this->context->getVendorDisplayName($store),
      'cache_tags' => array_values(array_unique($cache_tags)),
    ];

    // Admin-only debug mode: requires ALL three conditions.
    $request = $this->requestStack->getCurrentRequest();
    $debug_query_param = $request && (string) $request->query->get('debug') === '1';
    $debug_settings_flag = Settings::get('myeventlane_vendor_dashboard_debug', FALSE);
    $is_admin = $this->currentUser->id() === 1 || $this->currentUser->hasPermission('administer commerce_store');

    $debug_enabled = $debug_query_param && $debug_settings_flag && $is_admin;

    if ($debug_enabled) {
      $metrics_debug = $this->metrics->getDebugTotals($store, $range);
      $payload['debug'] = [
        'store_id' => (int) $store->id(),
        'store_label' => (string) $store->label(),
        'range_key' => (string) ($range['key'] ?? ''),
        'range_start' => (int) ($range['start'] ?? 0),
        'range_end' => (int) ($range['end'] ?? 0),
        'gross_cents' => (int) $metrics_debug['gross_cents'],
        'refund_cents' => (int) $metrics_debug['refund_cents'],
        'net_cents' => (int) $metrics_debug['net_cents'],
        'tickets_sold' => (int) $metrics_debug['tickets_sold'],
        'confirmed_rsvps' => (int) $metrics_debug['confirmed_rsvps'],
        'events_loaded' => (int) count($events['items'] ?? []),
      ];

      // Log debug information (single line).
      $logger = $this->loggerFactory->get('myeventlane_vendor_dashboard');
      $logger->info('Dashboard debug: store_id=@sid, range=@key (@start → @end), gross=@gross_cents, refund=@refund_cents, net=@net_cents, tickets=@tickets, rsvps=@rsvps, events=@events', [
        '@sid' => (string) $payload['debug']['store_id'],
        '@key' => $payload['debug']['range_key'],
        '@start' => (string) $payload['debug']['range_start'],
        '@end' => (string) $payload['debug']['range_end'],
        '@gross_cents' => (string) $payload['debug']['gross_cents'],
        '@refund_cents' => (string) $payload['debug']['refund_cents'],
        '@net_cents' => (string) $payload['debug']['net_cents'],
        '@tickets' => (string) $payload['debug']['tickets_sold'],
        '@rsvps' => (string) $payload['debug']['confirmed_rsvps'],
        '@events' => (string) $payload['debug']['events_loaded'],
      ]);
    }
    else {
      // Do not add debug data if any condition fails.
      $payload['debug'] = NULL;
    }

    return $payload;
  }

}
