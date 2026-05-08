<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Service;

use Drupal\Component\Datetime\TimeInterface;
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
use Drupal\myeventlane_vendor\Service\MetricsAggregator;
use Drupal\myeventlane_vendor\Service\RsvpStatsService;
use Drupal\myeventlane_vendor\Service\TicketSalesService;
use Drupal\myeventlane_core\MelReadinessHelper;
use Drupal\myeventlane_vendor\Service\UserVendorMembershipQuery;
use Drupal\node\NodeInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Vendor-wide analytics view model for `/vendor/analytics`.
 *
 * Orchestrates existing vendor metrics services only (same semantics as TASK 3
 * dashboard: MetricsAggregator → TicketSalesService / RsvpStatsService). Does
 * not duplicate commerce/order queries or Phase 7 analytics SQL.
 */
final class VendorAnalyticsViewModelBuilder {

  use StringTranslationTrait;

  private const MAX_EVENTS = 100;

  public function __construct(
    private readonly UserVendorMembershipQuery $userVendorMembershipQuery,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MetricsAggregator $metricsAggregator,
    private readonly TicketSalesService $ticketSalesService,
    private readonly RsvpStatsService $rsvpStatsService,
    private readonly EventStateResolver $eventStateResolver,
    private readonly RouteProviderInterface $routeProvider,
    private readonly AccessManagerInterface $accessManager,
    private readonly TimeInterface $time,
    private readonly DateFormatterInterface $dateFormatter,
    TranslationInterface $string_translation,
    private readonly MelReadinessHelper $readinessHelper,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Builds the vendor analytics dashboard model.
   *
   * @param array<string, mixed> $filters
   *   Reserved for future use (e.g. date range keys). Vendor KPI services used
   *   here are not date-window aware; filters are ignored until those services
   *   support ranges.
   *
   * @return array<string, mixed>
   *   TASK 9 contract: title, subtitle, date_range, kpis, events, insights,
   *   empty_state.
   */
  public function build(AccountInterface $account, array $filters = []): array {
    unset($filters);
    $uid = (int) $account->id();
    if ($uid <= 0) {
      return $this->emptyGuestModel();
    }

    try {
      $kpis = $this->buildKpis($uid, $account);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_analytics')->warning('Vendor analytics KPI build failed for uid @uid: @message', [
        '@uid' => (string) $uid,
        '@message' => $e->getMessage(),
      ]);
      $kpis = $this->fallbackKpis();
    }

    try {
      $events = $this->buildEventRows($uid, $account);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_analytics')->warning('Vendor analytics event rows failed for uid @uid: @message', [
        '@uid' => (string) $uid,
        '@message' => $e->getMessage(),
      ]);
      $events = [];
    }

    return [
      'title' => (string) $this->t('Analytics'),
      'subtitle' => (string) $this->t('Understand event performance across your organiser account.'),
      'date_range' => [
        'active' => 'all',
        'items' => [],
      ],
      'kpis' => $kpis,
      'events' => $events,
      'insights' => [],
      'empty_state' => $this->buildEmptyState($account, $events === []),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function emptyGuestModel(): array {
    return [
      'title' => (string) $this->t('Analytics'),
      'subtitle' => (string) $this->t('Understand event performance across your organiser account.'),
      'date_range' => [
        'active' => 'all',
        'items' => [],
      ],
      'kpis' => [],
      'events' => [],
      'insights' => [],
      'empty_state' => [
        'title' => $this->readinessHelper->vendorOrganiserPortalSignInTitle(),
        'message' => $this->readinessHelper->vendorOrganiserPortalSignInAnalyticsBody(),
        'action_label' => NULL,
        'url' => NULL,
      ],
    ];
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function buildKpis(int $uid, AccountInterface $account): array {
    $strip = $this->metricsAggregator->getVendorKpis($uid);
    $revenueValue = (string) ($strip[2]['value'] ?? '$0.00');
    $rsvpValue = (string) ($strip[1]['value'] ?? '0');
    $ticketsValue = (string) ($strip[3]['value'] ?? '0');

    $publishedManaged = $this->userVendorMembershipQuery->getManagedEventNodeIds($uid, TRUE);
    $upcoming = $this->countUpcomingPublishedEvents($publishedManaged);

    $contextManaged = (string) $this->t('Published events you manage · completed ticket orders');
    $contextNetRevenue = (string) $this->t('Net ticket revenue after refunds · completed orders only');

    return [
      [
        'key' => 'revenue',
        'label' => (string) $this->t('Revenue'),
        'value' => $revenueValue,
        'context' => $contextNetRevenue,
        'severity' => 'neutral',
      ],
      [
        'key' => 'tickets_sold',
        'label' => (string) $this->t('Tickets sold'),
        'value' => $ticketsValue,
        'context' => $contextManaged,
        'severity' => 'neutral',
      ],
      [
        'key' => 'rsvps',
        'label' => (string) $this->t('RSVPs'),
        'value' => $rsvpValue,
        'context' => (string) $this->t('Confirmed RSVPs on published events you manage'),
        'severity' => 'neutral',
      ],
      [
        'key' => 'upcoming_events',
        'label' => (string) $this->t('Upcoming events'),
        'value' => (string) $upcoming,
        'context' => (string) $this->t('Published events starting soon or in progress'),
        'severity' => 'neutral',
      ],
    ];
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function fallbackKpis(): array {
    $na = (string) $this->t('Not available yet');
    return [
      [
        'key' => 'revenue',
        'label' => (string) $this->t('Revenue'),
        'value' => $na,
        'context' => (string) $this->t('Metrics could not be loaded. Try again shortly.'),
        'severity' => 'neutral',
      ],
      [
        'key' => 'tickets_sold',
        'label' => (string) $this->t('Tickets sold'),
        'value' => $na,
        'context' => NULL,
        'severity' => 'neutral',
      ],
      [
        'key' => 'rsvps',
        'label' => (string) $this->t('RSVPs'),
        'value' => $na,
        'context' => NULL,
        'severity' => 'neutral',
      ],
      [
        'key' => 'upcoming_events',
        'label' => (string) $this->t('Upcoming events'),
        'value' => $na,
        'context' => NULL,
        'severity' => 'neutral',
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
      $this->loggerFactory->get('myeventlane_analytics')->warning('Upcoming events count failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
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

    $rows = [];
    foreach ($eventNodes as $node) {
      $rows[] = $this->buildEventRow($node, $account);
    }

    usort($rows, static function (array $a, array $b): int {
      $scoreA = (int) ($a['_sort_tickets'] ?? 0) + (int) ($a['_sort_rsvps'] ?? 0);
      $scoreB = (int) ($b['_sort_tickets'] ?? 0) + (int) ($b['_sort_rsvps'] ?? 0);
      if ($scoreA !== $scoreB) {
        return $scoreB <=> $scoreA;
      }
      return strcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
    });

    $rows = array_slice($rows, 0, self::MAX_EVENTS);
    foreach ($rows as &$row) {
      unset($row['_sort_tickets'], $row['_sort_rsvps']);
    }
    unset($row);

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
      $statusLabel = (string) $this->t('Draft');
    }
    elseif ($endTs > 0 && $endTs < $now) {
      $statusLabel = (string) $this->t('Past');
    }
    else {
      $statusLabel = (string) $this->t('Upcoming');
    }

    $dateLabel = '';
    if ($startTs > 0) {
      $dateLabel = $this->dateFormatter->format($startTs, 'medium');
    }

    $domain = $this->eventStateResolver->getEventDomainState($node);
    $hasProduct = (bool) ($domain['has_product'] ?? FALSE);
    $rsvpState = (string) ($domain['rsvp_state'] ?? 'unset');
    $rsvpCapable = $rsvpState !== 'unset';

    $eventTypeKey = 'unknown';
    if ($hasProduct && $rsvpCapable) {
      $eventTypeKey = 'both';
    }
    elseif ($hasProduct) {
      $eventTypeKey = 'paid';
    }
    elseif ($rsvpCapable) {
      $eventTypeKey = 'rsvp';
    }

    $eventTypeLabel = match ($eventTypeKey) {
      'paid' => (string) $this->t('Paid tickets'),
      'rsvp' => (string) $this->t('RSVP'),
      'both' => (string) $this->t('Tickets + RSVP'),
      default => (string) $this->t('Booking mode pending'),
    };

    $salesSummary = [];
    try {
      $salesSummary = $this->ticketSalesService->getSalesSummary($node);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_analytics')->warning('Analytics row sales summary failed for nid @nid: @message', [
        '@nid' => (string) $nid,
        '@message' => $e->getMessage(),
      ]);
    }

    $ticketsSold = (int) ($salesSummary['tickets_sold'] ?? 0);
    $revenueLabel = isset($salesSummary['gross']) ? (string) $salesSummary['gross'] : NULL;
    $ticketsLabel = $ticketsSold > 0
      ? (string) $this->t('@count sold', ['@count' => $ticketsSold])
      : NULL;

    $rsvpCount = 0;
    try {
      $rsvpCount = $this->rsvpStatsService->getEventRsvpCount($nid);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_analytics')->warning('Analytics row RSVP count failed for nid @nid: @message', [
        '@nid' => (string) $nid,
        '@message' => $e->getMessage(),
      ]);
    }

    $rsvpLabel = $rsvpCount > 0
      ? (string) $this->t('@count RSVPs', ['@count' => $rsvpCount])
      : NULL;

    $analyticsUrl = $this->urlIfAccessible($account, 'myeventlane_vendor.console.event_analytics', ['event' => $nid]);
    $workspaceUrl = $this->urlIfAccessible($account, 'myeventlane_vendor.console.event_workspace', ['event' => $nid]);

    return [
      'nid' => $nid,
      'title' => (string) $node->getTitle(),
      'status_label' => $statusLabel,
      'date_label' => $dateLabel,
      'event_type_label' => $eventTypeLabel,
      'revenue_label' => $revenueLabel,
      'tickets_label' => $ticketsLabel,
      'rsvp_label' => $rsvpLabel,
      'conversion_label' => NULL,
      'analytics_url' => $analyticsUrl,
      'workspace_url' => $workspaceUrl,
      '_sort_tickets' => $ticketsSold,
      '_sort_rsvps' => $rsvpCount,
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
   * @return array<string, string|\Drupal\Core\Url|null>
   */
  private function buildEmptyState(AccountInterface $account, bool $noEvents): array {
    if (!$noEvents) {
      return [
        'title' => '',
        'message' => '',
        'action_label' => NULL,
        'url' => NULL,
      ];
    }

    $copy = $this->readinessHelper->vendorActionNoEventsStrings();
    $actionLabel = $copy['action_label'];
    $url = NULL;

    if ($this->routeExists('myeventlane_event_studio.create')) {
      try {
        if ($this->accessManager->checkNamedRoute('myeventlane_event_studio.create', [], $account, TRUE)->isAllowed()) {
          $url = $this->safeUrlFromRoute('myeventlane_event_studio.create');
        }
      }
      catch (\Throwable) {
        $url = NULL;
      }
    }

    return [
      'title' => $copy['title'],
      'message' => $copy['message'],
      'action_label' => $actionLabel,
      'url' => $url,
    ];
  }

  /**
   * @param array<string, mixed> $parameters
   */
  private function urlIfAccessible(AccountInterface $account, string $route, array $parameters): ?Url {
    if (!$this->routeExists($route)) {
      return NULL;
    }
    try {
      if (!$this->accessManager->checkNamedRoute($route, $parameters, $account, TRUE)->isAllowed()) {
        return NULL;
      }
    }
    catch (\Throwable) {
      return NULL;
    }
    return $this->safeUrlFromRoute($route, $parameters);
  }

  /**
   * @param array<string, mixed> $parameters
   */
  private function safeUrlFromRoute(string $route, array $parameters = [], array $options = []): ?Url {
    if (!$this->routeExists($route)) {
      return NULL;
    }
    try {
      return Url::fromRoute($route, $parameters, $options);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_analytics')->warning('Failed to build URL for route @route: @message', [
        '@route' => $route,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
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

}
