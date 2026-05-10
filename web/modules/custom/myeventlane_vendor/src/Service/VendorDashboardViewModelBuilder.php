<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\EventStateResolver;
use Drupal\myeventlane_core\Utility\UpcomingEventEntityQueryHelper;
use Drupal\myeventlane_surface\MelDataPresentationManager;
use Drupal\myeventlane_core\MelReadinessHelper;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Builds a vendor dashboard view model for TASK 3+ console UI.
 *
 * Orchestrates existing vendor metrics and membership services only.
 */
final class VendorDashboardViewModelBuilder {

  use StringTranslationTrait;

  private const MAX_EVENTS = 6;

  public function __construct(
    private readonly CurrentVendorResolverInterface $currentVendorResolver,
    private readonly UserVendorMembershipQuery $userVendorMembershipQuery,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MetricsAggregator $metricsAggregator,
    private readonly TicketSalesService $ticketSalesService,
    private readonly RsvpStatsService $rsvpStatsService,
    private readonly EventStateResolver $eventStateResolver,
    private readonly VendorEventPresentationAlertsBuilder $presentationAlerts,
    private readonly VendorActionQueueBuilder $actionQueueBuilder,
    private readonly RouteProviderInterface $routeProvider,
    private readonly AccessManagerInterface $accessManager,
    private readonly TimeInterface $time,
    private readonly DateFormatterInterface $dateFormatter,
    TranslationInterface $string_translation,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly VendorEventRemovalService $vendorEventRemovalService,
    private readonly MelReadinessHelper $readinessHelper,
    private readonly MelDataPresentationManager $dataPresentationManager,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Builds the dashboard view model for the given account.
   *
   * @return array<string, mixed>
   *   Shape defined by Vendor Console v2 TASK 3.
   */
  public function build(AccountInterface $account): array {
    $uid = (int) $account->id();
    if ($uid <= 0) {
      return $this->emptyModel($account);
    }

    $vendor = $this->currentVendorResolver->resolveFromUser($account);
    $vendorPayload = $this->buildVendorPayload($vendor, $account);
    $readiness = $this->buildReadiness($vendor, $account);

    $kpis = [];
    try {
      $kpis = $this->buildKpis($uid);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_vendor')->warning('Dashboard view model KPI build failed for uid @uid: @message', [
        '@uid' => (string) $uid,
        '@message' => $e->getMessage(),
      ]);
      $kpis = $this->fallbackKpisUnavailable();
    }

    $events = [];
    try {
      $events = $this->buildEventRows($uid, $account);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_vendor')->warning('Dashboard view model events build failed for uid @uid: @message', [
        '@uid' => (string) $uid,
        '@message' => $e->getMessage(),
      ]);
    }

    $analyticsSummary = $this->buildAnalyticsSummary($account);

    $model = [
      'vendor' => $vendorPayload,
      'readiness' => $readiness,
      'operational_readiness' => [
        'heading' => $this->readinessHelper->vendorDashboardOperationalSummaryHeading(),
        'intro' => $this->readinessHelper->vendorDashboardOperationalSummaryIntro(),
        'items' => $this->readinessHelper->vendorDashboardOperationalReadinessSummary($readiness, $events),
        'first_event_guidance' => $this->readinessHelper->vendorDashboardFirstEventGuidance(
          $this->hasPublishedManagedEvents($uid),
          $this->eventsIncludeTicketSales($events),
          (bool) ($readiness['stripe_ready'] ?? FALSE),
        ),
      ],
      'kpis' => $this->dataPresentationManager->decorateVendorDashboardMetricStrip($kpis),
      'action_queue' => [],
      'events' => $events,
      'analytics_summary' => $analyticsSummary,
      'empty_state' => $this->buildEmptyState($account, $events === []),
    ];

    try {
      $model['action_queue'] = $this->actionQueueBuilder->build($model, $account);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_vendor')->error('Dashboard action queue wiring failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      $model['action_queue'] = [];
    }

    $model['hero_shell_hint'] = $this->heroShellHint($model);

    return $model;
  }

  /**
   * @return array<string, mixed>
   */
  private function emptyModel(AccountInterface $account): array {
    return [
      'vendor' => [
        'id' => NULL,
        'label' => '',
        'profile_url' => NULL,
        'settings_url' => NULL,
      ],
      'readiness' => [
        'profile_complete' => FALSE,
        'stripe_ready' => FALSE,
        'has_public_profile' => FALSE,
        'score' => 0,
        'items' => [],
        'row_complete_label' => $this->readinessHelper->vendorReadinessRowCompleteLabel(),
        'row_incomplete_label' => $this->readinessHelper->vendorReadinessRowIncompleteLabel(),
      ],
      'operational_readiness' => [
        'heading' => $this->readinessHelper->vendorDashboardOperationalSummaryHeading(),
        'intro' => $this->readinessHelper->vendorDashboardOperationalSummaryIntro(),
        'items' => $this->readinessHelper->vendorDashboardOperationalReadinessSummary([], []),
        'first_event_guidance' => $this->readinessHelper->vendorDashboardFirstEventGuidance(FALSE, FALSE, FALSE),
      ],
      'kpis' => $this->dataPresentationManager->decorateVendorDashboardMetricStrip([]),
      'action_queue' => [],
      'events' => [],
      'analytics_summary' => [
        'available' => FALSE,
        'items' => [],
      ],
      'hero_shell_hint' => $this->readinessHelper->vendorHeroShellLaunchHint(),
      'empty_state' => $this->buildEmptyState($account, TRUE),
    ];
  }

  /**
   * @return array<string, int|string|\Drupal\Core\Url|null>
   */
  private function buildVendorPayload(?Vendor $vendor, AccountInterface $account): array {
    $id = $vendor ? (int) $vendor->id() : NULL;
    $label = '';
    if ($vendor) {
      $label = trim((string) $vendor->label());
    }
    if ($label === '') {
      $label = (string) $account->getDisplayName();
    }

    $profileUrl = NULL;
    $settingsUrl = NULL;
    if ($vendor && $this->routeExists('entity.myeventlane_vendor.canonical')) {
      $profileUrl = $this->safeUrlFromRoute('entity.myeventlane_vendor.canonical', [
        'myeventlane_vendor' => $vendor->id(),
      ]);
    }
    if ($this->routeExists('myeventlane_vendor.console.settings')) {
      $settingsUrl = $this->safeUrlFromRoute('myeventlane_vendor.console.settings');
    }

    return [
      'id' => $id,
      'label' => $label,
      'profile_url' => $profileUrl,
      'settings_url' => $settingsUrl,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildReadiness(?Vendor $vendor, AccountInterface $account): array {
    $profileComplete = $vendor instanceof Vendor && trim((string) $vendor->label()) !== '';
    $hasPublicProfile = $vendor instanceof Vendor && $this->routeExists('entity.myeventlane_vendor.canonical');

    $stripeReady = FALSE;
    if ($vendor instanceof Vendor) {
      $stripeReady = $this->readStripeReadyFromVendorStore($vendor, $account);
    }

    $labels = $this->readinessHelper->vendorReadinessPresentationLabels();

    $items = [];

    $items[] = [
      'key' => 'profile',
      'label' => $labels['profile'],
      'complete' => $profileComplete,
      'severity' => $profileComplete ? 'success' : 'warning',
      'url' => $this->routeExists('myeventlane_vendor.console.settings')
        ? $this->safeUrlFromRoute('myeventlane_vendor.console.settings')
        : NULL,
    ];

    $items[] = [
      'key' => 'public_profile',
      'label' => $labels['public_profile'],
      'complete' => $hasPublicProfile && $vendor instanceof Vendor,
      'severity' => ($hasPublicProfile && $vendor instanceof Vendor) ? 'success' : 'neutral',
      'url' => ($vendor instanceof Vendor && $this->routeExists('entity.myeventlane_vendor.canonical'))
        ? $this->safeUrlFromRoute('entity.myeventlane_vendor.canonical', ['myeventlane_vendor' => $vendor->id()])
        : NULL,
    ];

    $stripeSeverity = $stripeReady ? 'success' : 'warning';
    $items[] = [
      'key' => 'stripe',
      'label' => $labels['stripe'],
      'complete' => $stripeReady,
      'severity' => $stripeSeverity,
      'url' => $this->routeExists('myeventlane_vendor.stripe_connect')
        ? $this->safeUrlFromRoute('myeventlane_vendor.stripe_connect', [], [
          'query' => ['destination' => '/vendor/dashboard'],
        ])
        : NULL,
    ];

    $done = 0;
    foreach ($items as $item) {
      if (!empty($item['complete'])) {
        $done++;
      }
    }
    $score = (int) round(count($items) > 0 ? ($done / count($items)) * 100 : 0);

    return [
      'profile_complete' => $profileComplete,
      'stripe_ready' => $stripeReady,
      'has_public_profile' => $hasPublicProfile && $vendor instanceof Vendor,
      'score' => $score,
      'items' => $items,
      'row_complete_label' => $this->readinessHelper->vendorReadinessRowCompleteLabel(),
      'row_incomplete_label' => $this->readinessHelper->vendorReadinessRowIncompleteLabel(),
    ];
  }

  /**
   * Read-only Stripe readiness from the vendor-linked store (no API calls).
   */
  private function readStripeReadyFromVendorStore(Vendor $vendor, AccountInterface $account): bool {
    if ($account->hasPermission('administer site configuration') || (int) $account->id() === 1) {
      return TRUE;
    }

    if (!$vendor->hasField('field_vendor_store') || $vendor->get('field_vendor_store')->isEmpty()) {
      return FALSE;
    }

    $store = $vendor->get('field_vendor_store')->entity;
    if (!$store instanceof StoreInterface) {
      return FALSE;
    }

    $charges = FALSE;
    if ($store->hasField('field_stripe_connected') && !$store->get('field_stripe_connected')->isEmpty()) {
      $charges = (bool) $store->get('field_stripe_connected')->value;
    }
    if (!$charges && $store->hasField('field_stripe_charges_enabled') && !$store->get('field_stripe_charges_enabled')->isEmpty()) {
      $charges = (bool) $store->get('field_stripe_charges_enabled')->value;
    }

    $payouts = $store->hasField('field_stripe_payouts_enabled') && !$store->get('field_stripe_payouts_enabled')->isEmpty()
      ? (bool) $store->get('field_stripe_payouts_enabled')->value
      : FALSE;

    return $charges && $payouts;
  }

  /**
   * KPIs from MetricsAggregator (TicketSalesService + RsvpStatsService inside).
   *
   * @return list<array<string, mixed>>
   */
  private function buildKpis(int $uid): array {
    $strip = $this->metricsAggregator->getVendorKpis($uid);
    // Order from getVendorKpis: Total Sales, RSVPs, Net Earnings, Tickets Sold.
    $revenueValue = (string) ($strip[2]['value'] ?? '$0.00');
    $rsvpValue = (string) ($strip[1]['value'] ?? '0');
    $ticketsValue = (string) ($strip[3]['value'] ?? '0');

    $publishedManaged = $this->userVendorMembershipQuery->getManagedEventNodeIds($uid, TRUE);
    $upcoming = $this->countUpcomingPublishedEvents($publishedManaged);

    $contextManaged = (string) $this->t('Published events you manage · completed ticket orders');
    $contextNetRevenue = (string) $this->t('Net ticket revenue after refunds · published events you manage · completed orders only');

    return [
      [
        'key' => 'revenue',
        'label' => (string) $this->t('Revenue'),
        'value' => $revenueValue,
        'context' => $contextNetRevenue,
        'trend' => NULL,
        'severity' => 'neutral',
        'url' => NULL,
      ],
      [
        'key' => 'tickets_sold',
        'label' => (string) $this->t('Tickets sold'),
        'value' => $ticketsValue,
        'context' => $contextManaged,
        'trend' => NULL,
        'severity' => 'neutral',
        'url' => NULL,
      ],
      [
        'key' => 'rsvps',
        'label' => (string) $this->t('RSVPs'),
        'value' => $rsvpValue,
        'context' => (string) $this->t('Confirmed RSVPs on published events you manage'),
        'trend' => NULL,
        'severity' => 'neutral',
        'url' => NULL,
      ],
      [
        'key' => 'upcoming_events',
        'label' => (string) $this->t('Upcoming events'),
        'value' => (string) $upcoming,
        'context' => (string) $this->t('Published events starting soon or in progress'),
        'trend' => NULL,
        'severity' => 'neutral',
        'url' => $this->routeExists('myeventlane_vendor.console.events')
          ? $this->safeUrlFromRoute('myeventlane_vendor.console.events')
          : NULL,
      ],
    ];
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function fallbackKpisUnavailable(): array {
    $na = (string) $this->t('Unavailable');
    $ctx = $this->readinessHelper->vendorKpiUnavailableContextLine();
    return [
      [
        'key' => 'revenue',
        'label' => (string) $this->t('Revenue'),
        'value' => $na,
        'context' => $ctx,
        'trend' => NULL,
        'severity' => 'neutral',
        'url' => NULL,
      ],
      [
        'key' => 'tickets_sold',
        'label' => (string) $this->t('Tickets sold'),
        'value' => $na,
        'context' => $ctx,
        'trend' => NULL,
        'severity' => 'neutral',
        'url' => NULL,
      ],
      [
        'key' => 'rsvps',
        'label' => (string) $this->t('RSVPs'),
        'value' => $na,
        'context' => $ctx,
        'trend' => NULL,
        'severity' => 'neutral',
        'url' => NULL,
      ],
      [
        'key' => 'upcoming_events',
        'label' => (string) $this->t('Upcoming events'),
        'value' => $na,
        'context' => $ctx,
        'trend' => NULL,
        'severity' => 'neutral',
        'url' => NULL,
      ],
    ];
  }

  /**
   * @param list<int> $publishedEventIds
   */
  private function countUpcomingPublishedEvents(array $publishedEventIds): int {
    if ($publishedEventIds === []) {
      return 0;
    }

    try {
      $storage = $this->entityTypeManager->getStorage('node');
      $query = $storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('nid', $publishedEventIds, 'IN')
        ->condition('status', 1);
      UpcomingEventEntityQueryHelper::addStartOrEndInFutureOrOngoing($query, (int) $this->time->getRequestTime());
      return (int) $query->count()->execute();
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_vendor')->warning('Upcoming events count failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  private function hasPublishedManagedEvents(int $uid): bool {
    try {
      return $this->userVendorMembershipQuery->getManagedEventNodeIds($uid, TRUE) !== [];
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_vendor')->warning('Published event readiness check failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * @param list<array<string, mixed>> $events
   */
  private function eventsIncludeTicketSales(array $events): bool {
    foreach ($events as $event) {
      if (!is_array($event)) {
        continue;
      }
      if (!empty($event['metric_label']) && str_contains((string) $event['metric_label'], 'ticket')) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function buildEventRows(int $uid, AccountInterface $account): array {
    $ids = $this->userVendorMembershipQuery->getManagedEventNodeIds($uid, FALSE);
    if ($ids === []) {
      return [];
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($ids);
    $eventNodes = [];
    foreach ($nodes as $node) {
      if ($node instanceof NodeInterface && $node->bundle() === 'event') {
        $eventNodes[] = $node;
      }
    }

    usort($eventNodes, static function (NodeInterface $a, NodeInterface $b): int {
      return $b->getChangedTime() <=> $a->getChangedTime();
    });

    $eventNodes = array_slice($eventNodes, 0, self::MAX_EVENTS);
    $rows = [];
    foreach ($eventNodes as $node) {
      $rows[] = $this->buildEventRow($node, $account);
    }

    return $rows;
  }

  /**
   * @return array<string, mixed>
   */
  private function buildEventRow(NodeInterface $node, AccountInterface $account): array {
    $nid = (int) $node->id();
    $published = $node->isPublished();
    $startTs = $this->getDateFieldTimestamp($node, 'field_event_start');
    $endTs = $this->getDateFieldTimestamp($node, 'field_event_end');
    $now = (int) $this->time->getRequestTime();

    if (!$published) {
      $status = 'draft';
      $statusLabel = $this->readinessHelper->vendorLifecycleDraftLabel();
    }
    elseif ($endTs > 0 && $endTs < $now) {
      $status = 'past';
      $statusLabel = $this->readinessHelper->vendorLifecyclePastLabel();
    }
    else {
      $status = 'upcoming';
      $statusLabel = $this->readinessHelper->vendorLifecycleUpcomingLabel();
    }

    $dateLabel = '';
    if ($startTs > 0) {
      $dateLabel = $this->dateFormatter->format($startTs, 'medium');
    }

    $domain = $this->eventStateResolver->getEventDomainState($node);
    $hasProduct = (bool) ($domain['has_product'] ?? FALSE);
    $rsvpState = (string) ($domain['rsvp_state'] ?? 'unset');
    $rsvpCapable = $rsvpState !== 'unset';

    $eventType = 'unknown';
    if ($hasProduct && $rsvpCapable) {
      $eventType = 'both';
    }
    elseif ($hasProduct) {
      $eventType = 'paid';
    }
    elseif ($rsvpCapable) {
      $eventType = 'rsvp';
    }

    $presentation = $this->presentationAlerts->buildChipSummary($node, $hasProduct, $eventType);

    $capacity = (int) ($domain['capacity'] ?? 0);
    $capacityLabel = $capacity > 0
      ? (string) $this->t('@count seats', ['@count' => $capacity])
      : NULL;

    $salesSummary = [];
    try {
      $salesSummary = $this->ticketSalesService->getSalesSummary($node);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_vendor')->warning('Event sales summary failed for nid @nid: @message', [
        '@nid' => (string) $nid,
        '@message' => $e->getMessage(),
      ]);
    }

    $ticketsSold = (int) ($salesSummary['tickets_sold'] ?? 0);
    $revenueLabel = (string) ($salesSummary['gross'] ?? '$0.00');

    $rsvpCount = 0;
    try {
      $rsvpCount = $this->rsvpStatsService->getEventRsvpCount($nid);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_vendor')->warning('Event RSVP count failed for nid @nid: @message', [
        '@nid' => (string) $nid,
        '@message' => $e->getMessage(),
      ]);
    }

    $metricParts = [];
    if ($ticketsSold > 0) {
      $metricParts[] = (string) $this->t('@count tickets', ['@count' => $ticketsSold]);
    }
    if ($rsvpCount > 0) {
      $metricParts[] = (string) $this->t('@count RSVPs', ['@count' => $rsvpCount]);
    }
    $metricLabel = $metricParts !== [] ? implode(' · ', $metricParts) : NULL;

    return [
      'nid' => $nid,
      'title' => (string) $node->getTitle(),
      'status' => $status,
      'status_label' => $statusLabel,
      'date_label' => $dateLabel,
      'event_type' => $eventType,
      'capacity_label' => $capacityLabel,
      'metric_label' => $metricLabel,
      'revenue_label' => $revenueLabel,
      'presentation_issues' => $presentation['items'] ?? [],
      'image' => $this->vendorEventRemovalService->buildEventThumbnailData($node),
      'removal' => $this->vendorEventRemovalService->buildRemovalUiPayload($node, $account),
      'links' => [
        'manage' => $this->safeUrlFromRoute('myeventlane_vendor.console.event_workspace', ['event' => $nid]),
        'edit' => $this->safeUrlFromRoute('myeventlane_event_studio.edit', ['node' => $nid]),
        'tickets' => $this->safeUrlFromRoute('myeventlane_vendor.console.event_tickets', ['event' => $nid]),
        'rsvps' => $this->safeUrlFromRoute('myeventlane_vendor.console.event_rsvps', ['event' => $nid]),
        'orders' => $this->safeUrlFromRoute('myeventlane_vendor.console.event_orders', ['event' => $nid]),
        'attendees' => $this->safeUrlFromRoute('myeventlane_event_attendees.vendor_list', ['node' => $nid]),
        'analytics' => $this->safeUrlFromRoute('myeventlane_vendor.console.event_analytics', ['event' => $nid]),
      ],
    ];
  }

  private function getDateFieldTimestamp(NodeInterface $node, string $field): int {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return 0;
    }
    $item = $node->get($field)->first();
    if ($item && isset($item->date) && $item->date) {
      return (int) $item->date->getTimestamp();
    }
    return 0;
  }

  /**
   * @return array{available: bool, items: list<mixed>}
   */
  private function buildAnalyticsSummary(AccountInterface $account): array {
    if (!$this->routeExists('myeventlane_analytics.dashboard')) {
      return [
        'available' => FALSE,
        'items' => [],
      ];
    }

    try {
      $allowed = $this->accessManager->checkNamedRoute(
        'myeventlane_analytics.dashboard',
        [],
        $account,
        TRUE,
      )->isAllowed();
      return [
        'available' => $allowed,
        'items' => [],
      ];
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_vendor')->warning('Analytics availability check failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [
        'available' => FALSE,
        'items' => [],
      ];
    }
  }

  /**
   * @return array<string, string|\Drupal\Core\Url|null>
   */
  private function buildEmptyState(AccountInterface $account, bool $noEvents): array {
    $copy = $this->readinessHelper->vendorDashboardEmptyStrings(!$noEvents);
    $title = $copy['empty_title'];
    $message = $copy['empty_message'];
    $actionLabel = $copy['empty_action_label'];
    $url = NULL;

    if ($noEvents && $this->routeExists('myeventlane_event_studio.create')) {
      try {
        if ($this->accessManager->checkNamedRoute('myeventlane_event_studio.create', [], $account, TRUE)->isAllowed()) {
          $url = $this->safeUrlFromRoute('myeventlane_event_studio.create');
        }
      }
      catch (\Throwable) {
        $url = NULL;
      }
    }
    elseif (!$noEvents) {
      $message = $copy['empty_message'];
      $actionLabel = $copy['empty_action_label'];
      if ($this->routeExists('myeventlane_vendor.console.events')) {
        try {
          if ($this->accessManager->checkNamedRoute('myeventlane_vendor.console.events', [], $account, TRUE)->isAllowed()) {
            $url = $this->safeUrlFromRoute('myeventlane_vendor.console.events');
          }
        }
        catch (\Throwable) {
          $url = NULL;
        }
      }
    }

    return [
      'title' => $title,
      'message' => $message,
      'action_label' => $actionLabel,
      'url' => $url,
    ];
  }

  /**
   * @param array<string, mixed> $model
   */
  private function heroShellHint(array $model): string {
    $actions = $model['action_queue'] ?? [];
    if (is_array($actions) && $actions !== []) {
      return $this->readinessHelper->vendorHeroShellNeedsAttentionHint();
    }
    $events = $model['events'] ?? [];
    if (is_array($events) && $events !== []) {
      return $this->readinessHelper->vendorHeroShellEventsComfortableHint();
    }
    return $this->readinessHelper->vendorHeroShellLaunchHint();
  }

  private function routeExists(string $name): bool {
    try {
      $this->routeProvider->getRouteByName($name);
      return TRUE;
    }
    catch (RouteNotFoundException) {
      return FALSE;
    }
  }

  /**
   * @param array<string, mixed> $options
   */
  private function safeUrlFromRoute(string $route, array $parameters = [], array $options = []): ?Url {
    if (!$this->routeExists($route)) {
      return NULL;
    }
    try {
      return Url::fromRoute($route, $parameters, $options);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_vendor')->warning('Failed to build URL for route @route: @message', [
        '@route' => $route,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

}
