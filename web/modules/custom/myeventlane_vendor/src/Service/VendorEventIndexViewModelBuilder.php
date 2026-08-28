<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_boost\Service\BoostExtensionRecommendationService;
use Drupal\myeventlane_core\Service\EventStateResolver;
use Drupal\myeventlane_core\MelReadinessHelper;
use Drupal\myeventlane_event_state\Service\EventStateResolverInterface as LifecycleStateResolverInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Builds the vendor console /vendor/events index view model (TASK 6).
 *
 * Uses membership query + existing ticket/RSVP/domain resolver services only.
 */
final class VendorEventIndexViewModelBuilder {

  use StringTranslationTrait;

  private const ROUTE_EVENTS_INDEX = 'myeventlane_vendor.console.events';

  private const EVENTS_PER_PAGE = 12;

  public function __construct(
    private readonly UserVendorMembershipQuery $userVendorMembershipQuery,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TicketSalesService $ticketSalesService,
    private readonly RsvpStatsService $rsvpStatsService,
    private readonly EventStateResolver $eventStateResolver,
    private readonly VendorEventPresentationAlertsBuilder $presentationAlerts,
    private readonly RouteProviderInterface $routeProvider,
    private readonly AccessManagerInterface $accessManager,
    private readonly TimeInterface $time,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly PagerManagerInterface $pagerManager,
    TranslationInterface $string_translation,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly VendorEventRemovalService $vendorEventRemovalService,
    private readonly MelReadinessHelper $readinessHelper,
    private readonly BoostStatusService $boostStatusService,
    private readonly ?BoostExtensionRecommendationService $boostExtensionRecommendation = NULL,
    private readonly ?LifecycleStateResolverInterface $lifecycleStateResolver = NULL,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Builds the current organiser's filtered and paginated event index.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Current organiser account.
   * @param array<string, mixed> $filters
   *   Keys: status (string), sort (string), search (string).
   *
   * @return array<string, mixed>
   *   Normalised TASK 6 index model.
   */
  public function build(AccountInterface $account, array $filters = []): array {
    $uid = (int) $account->id();
    if ($uid <= 0) {
      return $this->guestModel();
    }

    $statusParam = $this->normalizeStatus((string) ($filters['status'] ?? 'current'));
    $sortParam = $this->normalizeSort((string) ($filters['sort'] ?? 'recommended'));
    $searchParam = $this->normalizeSearch((string) ($filters['search'] ?? ''));

    $rows = [];
    try {
      $rows = $this->loadEventRows($uid, $account);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_vendor')->warning('Event index model load failed for uid @uid: @message', [
        '@uid' => (string) $uid,
        '@message' => $e->getMessage(),
      ]);
    }

    $searchedRows = $this->searchRowsInternal($rows, $searchParam);
    $summary = $this->buildSummary($searchedRows);
    $filterItems = $this->buildFilterItems(
      $account,
      $statusParam,
      $sortParam,
      $searchParam,
      $searchedRows,
    );
    $sortItems = $this->buildSortItems(
      $account,
      $statusParam,
      $sortParam,
      $searchParam,
    );

    $filteredInternal = $this->filterRowsInternal(
      $searchedRows,
      $statusParam,
    );
    $filteredInternal = $this->sortRowsInternal($filteredInternal, $sortParam);
    $resultCount = count($filteredInternal);
    $pager = $this->pagerManager->createPager(
      $resultCount,
      self::EVENTS_PER_PAGE,
    );
    $pageRows = array_slice(
      $filteredInternal,
      $pager->getCurrentPage() * self::EVENTS_PER_PAGE,
      self::EVENTS_PER_PAGE,
    );
    $filtered = $this->stripInternals($pageRows);

    $primaryUrl = $this->routeUrlIfAccessible('myeventlane_vendor.create_event_gateway', [], $account);

    return [
      'title' => (string) $this->t('Events'),
      'subtitle' => (string) $this->t('Create, manage, and track your events.'),
      'primary_action' => [
        'label' => (string) $this->t('Create event'),
        'url' => $primaryUrl,
      ],
      'filters' => [
        'active' => $statusParam,
        'items' => $filterItems,
      ],
      'sort' => [
        'active' => $sortParam,
        'items' => $sortItems,
      ],
      'search' => [
        'value' => $searchParam,
        'clear_url' => $this->safeEventsIndexUrl(
          $account,
          $statusParam,
          $sortParam,
        ),
      ],
      'summary' => $summary,
      'summary_items' => $this->buildSummaryItems(
        $account,
        $summary,
        $sortParam,
        $searchParam,
      ),
      'events' => $filtered,
      'result_count' => $resultCount,
      'result_label' => (string) $this->formatPlural(
        $resultCount,
        '1 event',
        '@count events',
      ),
      'page_size' => self::EVENTS_PER_PAGE,
      'empty_state' => $this->buildEmptyState(
        $account,
        $rows,
        $filteredInternal,
        $primaryUrl,
        $searchParam,
      ),
    ];
  }

  /**
   * Builds the unauthenticated event-index model.
   *
   * @return array<string, mixed>
   *   Guest-safe event-index model.
   */
  private function guestModel(): array {
    return [
      'title' => (string) $this->t('Events'),
      'subtitle' => (string) $this->t('Create, manage, and track your events.'),
      'primary_action' => [
        'label' => (string) $this->t('Create event'),
        'url' => NULL,
      ],
      'filters' => ['active' => 'current', 'items' => []],
      'sort' => ['active' => 'recommended', 'items' => []],
      'search' => ['value' => '', 'clear_url' => NULL],
      'summary' => [
        'total' => 0,
        'draft' => 0,
        'current' => 0,
        'needs_attention' => 0,
        'past' => 0,
      ],
      'summary_items' => [],
      'events' => [],
      'result_count' => 0,
      'result_label' => (string) $this->t('0 events'),
      'page_size' => self::EVENTS_PER_PAGE,
      'empty_state' => [
        'title' => $this->readinessHelper->vendorOrganiserPortalSignInTitle(),
        'message' => $this->readinessHelper->vendorOrganiserPortalSignInEventsBody(),
        'action_label' => (string) $this->t('Create event'),
        'url' => NULL,
      ],
    ];
  }

  /**
   * Loads all event rows managed by an organiser account.
   *
   * @return list<array<string, mixed>>
   *   Managed event rows.
   */
  private function loadEventRows(int $uid, AccountInterface $account): array {
    $ids = $this->userVendorMembershipQuery->getManagedEventNodeIds($uid, FALSE);
    if ($ids === []) {
      return [];
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($ids);
    $out = [];
    foreach ($nodes as $node) {
      if ($node instanceof NodeInterface && $node->bundle() === 'event') {
        $out[] = $this->buildEventRow($node, $account);
      }
    }

    return $out;
  }

  /**
   * Builds one event card row from canonical services.
   *
   * @return array<string, mixed>
   *   Event card view model.
   */
  private function buildEventRow(NodeInterface $node, AccountInterface $account): array {
    $nid = (int) $node->id();
    $now = (int) $this->time->getRequestTime();
    $startTs = $this->getDateFieldTimestamp($node, 'field_event_start');
    $endTs = $this->getDateFieldTimestamp($node, 'field_event_end');

    $statusParts = $this->resolveLifecycleStatus($node, $endTs, $now);
    $status = $statusParts['status'];
    $statusLabel = $statusParts['label'];
    $statusSeverity = $statusParts['severity'];

    $title = trim((string) $node->getTitle());
    $titleEmpty = $title === '';

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

    $eventTypeLabel = match ($eventType) {
      'paid' => (string) $this->t('Paid'),
      'rsvp' => (string) $this->t('RSVP'),
      'both' => (string) $this->t('RSVP + paid'),
      default => (string) $this->t('Booking not set'),
    };

    $capacity = (int) ($domain['capacity'] ?? 0);
    $capacityLabel = $capacity > 0
      ? (string) $this->t('@count seats', ['@count' => $capacity])
      : NULL;

    $salesSummary = [];
    try {
      $salesSummary = $this->ticketSalesService->getSalesSummary($node);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_vendor')->warning('Event index sales summary failed for nid @nid: @message', [
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
      $this->loggerFactory->get('myeventlane_vendor')->warning('Event index RSVP count failed for nid @nid: @message', [
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

    $missingDate = $startTs <= 0 && $endTs <= 0;
    $presentation = $this->presentationAlerts->buildChipSummary($node, $hasProduct, $eventType);
    $presentationItems = $presentation['items'] ?? [];
    $needsAttention = $status === 'draft'
      || $eventType === 'unknown'
      || $titleEmpty
      || $missingDate
      || $presentationItems !== [];

    $attentionLabel = $this->buildAttentionLabel($status, $eventType, $titleEmpty, $missingDate, $presentationItems);

    $boost = $this->boostStatusService->getVisibilityPayload($node);
    $boostExtensionRecommendation = $this->boostExtensionRecommendation?->getRecommendation($node);

    return [
      'nid' => $nid,
      'title' => $title !== '' ? $title : (string) $this->t('Untitled event'),
      'status' => $status,
      'status_label' => $statusLabel,
      'status_severity' => $statusSeverity,
      'date_label' => $dateLabel !== '' ? $dateLabel : NULL,
      'event_type' => $eventType,
      'event_type_label' => $eventTypeLabel,
      'capacity_label' => $capacityLabel,
      'metric_label' => $metricLabel,
      'revenue_label' => $revenueLabel,
      'needs_attention' => $needsAttention,
      'attention_label' => $attentionLabel,
      'presentation_issues' => $presentationItems,
      'image' => $this->vendorEventRemovalService->buildEventThumbnailData($node),
      'removal' => $this->vendorEventRemovalService->buildRemovalUiPayload($node, $account),
      'boost' => $boost,
      'boost_extension_recommendation' => $boostExtensionRecommendation,
      'links' => [
        'manage' => $this->routeUrlIfAccessible('myeventlane_vendor.console.event_workspace', ['event' => $nid], $account),
        'edit' => $this->routeUrlIfAccessible('myeventlane_event_studio.edit', ['node' => $nid], $account),
        'tickets' => $this->routeUrlIfAccessible('myeventlane_vendor.console.event_tickets', ['event' => $nid], $account),
        'rsvps' => $this->routeUrlIfAccessible('myeventlane_vendor.console.event_rsvps', ['event' => $nid], $account),
        'orders' => $this->routeUrlIfAccessible('myeventlane_vendor.console.event_orders', ['event' => $nid], $account),
        'attendees' => $this->routeUrlIfAccessible('myeventlane_event_attendees.vendor_list', ['node' => $nid], $account),
        'analytics' => $this->routeUrlIfAccessible('myeventlane_vendor.console.event_analytics', ['event' => $nid], $account),
      ],
      '_created' => $node->getCreatedTime(),
      '_changed' => $node->getChangedTime(),
      '_start_ts' => $startTs,
      '_section' => $this->resolveSectionKey($status, $needsAttention),
    ];
  }

  /**
   * Resolves lifecycle status through the canonical MEL state service.
   *
   * Falls back to the existing publication/date calculation when the optional
   * lifecycle module is unavailable or cannot resolve a state.
   *
   * @return array{status: string, label: string, severity: string}
   *   Lifecycle presentation state.
   */
  private function resolveLifecycleStatus(NodeInterface $node, int $endTs, int $now): array {
    if ($this->lifecycleStateResolver !== NULL) {
      try {
        $state = $this->lifecycleStateResolver->resolveState($node);
        $resolved = match ($state) {
          'draft' => [(string) $this->t('Draft'), 'warning'],
          'scheduled' => [(string) $this->t('Upcoming'), 'info'],
          'live' => [(string) $this->t('Live'), 'success'],
          'sold_out' => [(string) $this->t('Sold out'), 'success'],
          'ended' => [(string) $this->t('Past'), 'neutral'],
          'cancelled' => [(string) $this->t('Cancelled'), 'neutral'],
          'archived' => [(string) $this->t('Archived'), 'neutral'],
          default => NULL,
        };
        if ($resolved !== NULL) {
          return [
            'status' => $state,
            'label' => $resolved[0],
            'severity' => $resolved[1],
          ];
        }
      }
      catch (\Throwable $e) {
        $this->loggerFactory->get('myeventlane_vendor')->warning(
          'Event index lifecycle resolution failed for nid @nid: @message',
          [
            '@nid' => (string) $node->id(),
            '@message' => $e->getMessage(),
          ],
        );
      }
    }

    return $this->resolvePublicationStatus($node, $endTs, $now);
  }

  /**
   * Maps lifecycle and attention state to the recommended page section.
   */
  private function resolveSectionKey(string $status, bool $needsAttention): string {
    if ($this->isPastStatus($status)) {
      return 'past';
    }
    if ($status === 'draft') {
      return 'draft';
    }
    if ($needsAttention) {
      return 'attention';
    }
    return 'current';
  }

  /**
   * Resolves legacy publication state when lifecycle resolution is unavailable.
   *
   * @return array{status: string, label: string, severity: string}
   *   Publication presentation state.
   */
  private function resolvePublicationStatus(NodeInterface $node, int $endTs, int $now): array {
    if (!$node->isPublished()) {
      return [
        'status' => 'draft',
        'label' => (string) $this->t('Draft'),
        'severity' => 'warning',
      ];
    }

    $inReview = $node->hasField('moderation_state')
      && !$node->get('moderation_state')->isEmpty()
      && (string) $node->get('moderation_state')->value === 'review';
    if ($inReview) {
      return [
        'status' => 'unknown',
        'label' => (string) $this->t('Needs review'),
        'severity' => 'warning',
      ];
    }

    if ($endTs > 0 && $endTs < $now) {
      return [
        'status' => 'past',
        'label' => (string) $this->t('Past'),
        'severity' => 'neutral',
      ];
    }

    return [
      'status' => 'published',
      'label' => (string) $this->t('Published'),
      'severity' => 'success',
    ];
  }

  /**
   * Builds a concise actionable label for incomplete event setup.
   *
   * @param string $status
   *   Lifecycle status.
   * @param string $eventType
   *   Normalised booking type.
   * @param bool $titleEmpty
   *   Whether the event title is empty.
   * @param bool $missingDate
   *   Whether the event has no start or end date.
   * @param list<array<string, mixed>> $presentationItems
   *   Presentation issues from the canonical alert builder.
   *
   * @return string|null
   *   Attention label, or NULL when no action is required.
   */
  private function buildAttentionLabel(string $status, string $eventType, bool $titleEmpty, bool $missingDate, array $presentationItems = []): ?string {
    $base = NULL;
    if ($titleEmpty) {
      $base = (string) $this->t('Add a title');
    }
    elseif ($missingDate) {
      $base = (string) $this->t('Add date & time');
    }
    elseif ($status === 'draft') {
      $base = (string) $this->t('Draft — finish setup');
    }
    elseif ($eventType === 'unknown') {
      $base = (string) $this->t('Set up tickets or RSVP');
    }

    $chipLabels = [];
    foreach ($presentationItems as $item) {
      if (!empty($item['label'])) {
        $chipLabels[] = (string) $item['label'];
      }
    }
    if ($chipLabels === []) {
      return $base;
    }
    $suffix = implode(' · ', $chipLabels);
    return $base !== NULL ? $base . ' · ' . $suffix : $suffix;
  }

  /**
   * Counts actionable lifecycle states for the current search.
   *
   * @param list<array<string, mixed>> $rows
   *   Searched event rows.
   *
   * @return array<string, int>
   *   Portfolio counts keyed by lifecycle state.
   */
  private function buildSummary(array $rows): array {
    $summary = [
      'total' => count($rows),
      'draft' => 0,
      'current' => 0,
      'needs_attention' => 0,
      'past' => 0,
    ];

    foreach ($rows as $row) {
      $st = (string) ($row['status'] ?? '');
      if ($st === 'draft') {
        $summary['draft']++;
      }
      elseif ($this->isPastStatus($st)) {
        $summary['past']++;
      }
      else {
        $summary['current']++;
      }

      if ($st !== 'draft'
        && !empty($row['needs_attention'])
        && !$this->isPastStatus($st)) {
        $summary['needs_attention']++;
      }
    }

    return $summary;
  }

  /**
   * Builds the three actionable portfolio counters shown above the list.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Current organiser account.
   * @param array<string, int> $summary
   *   Summary counts for the current search.
   * @param string $sort
   *   Active sort key.
   * @param string $search
   *   Normalised event-title search.
   *
   * @return list<array<string, mixed>>
   *   Actionable summary links.
   */
  private function buildSummaryItems(
    AccountInterface $account,
    array $summary,
    string $sort,
    string $search,
  ): array {
    return [
      [
        'key' => 'needs_attention',
        'label' => (string) $this->t('Needs attention'),
        'value' => (int) ($summary['needs_attention'] ?? 0),
        'url' => $this->safeEventsIndexUrl(
          $account,
          'needs_attention',
          $sort,
          $search,
        ),
        'emphasis' => TRUE,
      ],
      [
        'key' => 'active',
        'label' => (string) $this->t('Upcoming & live'),
        'value' => (int) ($summary['current'] ?? 0),
        'url' => $this->safeEventsIndexUrl(
          $account,
          'active',
          $sort,
          $search,
        ),
        'emphasis' => FALSE,
      ],
      [
        'key' => 'draft',
        'label' => (string) $this->t('Drafts'),
        'value' => (int) ($summary['draft'] ?? 0),
        'url' => $this->safeEventsIndexUrl(
          $account,
          'draft',
          $sort,
          $search,
        ),
        'emphasis' => FALSE,
      ],
    ];
  }

  /**
   * Builds lifecycle filter links and matching result counts.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Current organiser account.
   * @param string $activeStatus
   *   Active lifecycle filter.
   * @param string $sort
   *   Active sort key.
   * @param string $search
   *   Normalised event-title search.
   * @param list<array<string, mixed>> $rows
   *   Searched event rows.
   *
   * @return list<array<string, mixed>>
   *   Filter link view models.
   */
  private function buildFilterItems(
    AccountInterface $account,
    string $activeStatus,
    string $sort,
    string $search,
    array $rows,
  ): array {
    $definitions = [
      'current' => (string) $this->t('Current'),
      'active' => (string) $this->t('Upcoming & live'),
      'needs_attention' => (string) $this->t('Needs attention'),
      'draft' => (string) $this->t('Drafts'),
      'past' => (string) $this->t('Past'),
      'all' => (string) $this->t('All events'),
    ];

    $items = [];
    foreach ($definitions as $key => $label) {
      $items[] = [
        'key' => $key,
        'label' => $label,
        'url' => $this->safeEventsIndexUrl(
          $account,
          $key,
          $sort,
          $search,
        ),
        'active' => $activeStatus === $key,
        'count' => $this->countForFilter($rows, $key),
      ];
    }

    return $items;
  }

  /**
   * Counts rows matching one lifecycle filter.
   *
   * @param list<array<string, mixed>> $rows
   *   Event rows to count.
   * @param string $key
   *   Lifecycle filter key.
   */
  private function countForFilter(array $rows, string $key): int {
    if ($key === 'all') {
      return count($rows);
    }
    $n = 0;
    foreach ($rows as $row) {
      if ($this->rowMatchesFilter($row, $key)) {
        $n++;
      }
    }
    return $n;
  }

  /**
   * Whether an event row matches a lifecycle filter.
   *
   * @param array<string, mixed> $row
   *   Event row.
   * @param string $key
   *   Lifecycle filter key.
   */
  private function rowMatchesFilter(array $row, string $key): bool {
    $status = (string) ($row['status'] ?? '');
    $isPast = $this->isPastStatus($status);
    return match ($key) {
      'current' => !$isPast,
      'active' => !$isPast && $status !== 'draft',
      'draft' => $status === 'draft',
      'past' => $isPast,
      'needs_attention' => $status !== 'draft'
        && !empty($row['needs_attention'])
        && !$isPast,
      default => FALSE,
    };
  }

  /**
   * Filters event rows by lifecycle state.
   *
   * @param list<array<string, mixed>> $rows
   *   Event rows to filter.
   * @param string $status
   *   Lifecycle filter key.
   *
   * @return list<array<string, mixed>>
   *   Matching event rows.
   */
  private function filterRowsInternal(array $rows, string $status): array {
    if ($status === 'all') {
      return $rows;
    }
    $out = [];
    foreach ($rows as $row) {
      if ($this->rowMatchesFilter($row, $status)) {
        $out[] = $row;
      }
    }
    return $out;
  }

  /**
   * Removes internal sorting keys from event rows.
   *
   * @param list<array<string, mixed>> $rows
   *   Internal event rows.
   *
   * @return list<array<string, mixed>>
   *   Presentation-safe event rows.
   */
  private function stripInternals(array $rows): array {
    return array_map(fn (array $row): array => $this->stripInternalsRow($row), $rows);
  }

  /**
   * Converts one internal event row to its presentation shape.
   *
   * @param array<string, mixed> $row
   *   Internal event row.
   *
   * @return array<string, mixed>
   *   Presentation-safe event row.
   */
  private function stripInternalsRow(array $row): array {
    $section = (string) ($row['_section'] ?? 'current');
    $row['section_key'] = $section;
    $row['section_label'] = match ($section) {
      'attention' => (string) $this->t('Needs attention'),
      'draft' => (string) $this->t('Drafts'),
      'past' => (string) $this->t('Past events'),
      default => (string) $this->t('Upcoming & live'),
    };
    unset(
      $row['_created'],
      $row['_changed'],
      $row['_start_ts'],
      $row['_section'],
    );
    return $row;
  }

  /**
   * Sorts event rows using the selected portfolio ordering.
   *
   * @param list<array<string, mixed>> $rows
   *   Event rows to sort.
   * @param string $sort
   *   Normalised sort key.
   *
   * @return list<array<string, mixed>>
   *   Sorted event rows.
   */
  private function sortRowsInternal(array $rows, string $sort): array {
    if ($sort === 'updated') {
      usort($rows, static function (array $a, array $b): int {
        return (int) ($b['_changed'] ?? 0) <=> (int) ($a['_changed'] ?? 0);
      });
    }
    elseif ($sort === 'name') {
      usort($rows, static function (array $a, array $b): int {
        return strnatcasecmp(
          (string) ($a['title'] ?? ''),
          (string) ($b['title'] ?? ''),
        );
      });
    }
    elseif ($sort === 'date') {
      usort($rows, static function (array $a, array $b): int {
        $aPast = ($a['_section'] ?? '') === 'past';
        $bPast = ($b['_section'] ?? '') === 'past';
        if ($aPast !== $bPast) {
          return $aPast ? 1 : -1;
        }
        $sa = (int) ($a['_start_ts'] ?? 0);
        $sb = (int) ($b['_start_ts'] ?? 0);
        if ($sa === 0 && $sb === 0) {
          return (int) ($b['_changed'] ?? 0) <=> (int) ($a['_changed'] ?? 0);
        }
        if ($sa === 0) {
          return 1;
        }
        if ($sb === 0) {
          return -1;
        }
        return $aPast ? $sb <=> $sa : $sa <=> $sb;
      });
    }
    else {
      $priority = [
        'attention' => 0,
        'current' => 1,
        'draft' => 2,
        'past' => 3,
      ];
      usort($rows, static function (array $a, array $b) use ($priority): int {
        $aSection = (string) ($a['_section'] ?? 'current');
        $bSection = (string) ($b['_section'] ?? 'current');
        $groupOrder = ($priority[$aSection] ?? 9)
          <=> ($priority[$bSection] ?? 9);
        if ($groupOrder !== 0) {
          return $groupOrder;
        }

        if ($aSection === 'draft') {
          return (int) ($b['_changed'] ?? 0)
            <=> (int) ($a['_changed'] ?? 0);
        }

        $sa = (int) ($a['_start_ts'] ?? 0);
        $sb = (int) ($b['_start_ts'] ?? 0);
        if ($sa === 0 || $sb === 0) {
          if ($sa === $sb) {
            return (int) ($b['_changed'] ?? 0)
              <=> (int) ($a['_changed'] ?? 0);
          }
          return $sa === 0 ? 1 : -1;
        }
        return $aSection === 'past' ? $sb <=> $sa : $sa <=> $sb;
      });
    }

    return $rows;
  }

  /**
   * Builds accessible sort options for the current result set.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Current organiser account.
   * @param string $status
   *   Active lifecycle filter.
   * @param string $activeSort
   *   Active sort key.
   * @param string $search
   *   Normalised event-title search.
   *
   * @return list<array<string, mixed>>
   *   Sort option view models.
   */
  private function buildSortItems(
    AccountInterface $account,
    string $status,
    string $activeSort,
    string $search,
  ): array {
    return [
      [
        'key' => 'recommended',
        'label' => (string) $this->t('Recommended'),
        'url' => $this->safeEventsIndexUrl(
          $account,
          $status,
          'recommended',
          $search,
        ),
        'active' => $activeSort === 'recommended',
      ],
      [
        'key' => 'date',
        'label' => (string) $this->t('Event date'),
        'url' => $this->safeEventsIndexUrl(
          $account,
          $status,
          'date',
          $search,
        ),
        'active' => $activeSort === 'date',
      ],
      [
        'key' => 'updated',
        'label' => (string) $this->t('Recently updated'),
        'url' => $this->safeEventsIndexUrl(
          $account,
          $status,
          'updated',
          $search,
        ),
        'active' => $activeSort === 'updated',
      ],
      [
        'key' => 'name',
        'label' => (string) $this->t('Event name'),
        'url' => $this->safeEventsIndexUrl(
          $account,
          $status,
          'name',
          $search,
        ),
        'active' => $activeSort === 'name',
      ],
    ];
  }

  /**
   * Builds the empty state for an empty portfolio or filtered result.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Current organiser account.
   * @param list<array<string, mixed>> $allRows
   *   All managed event rows.
   * @param list<array<string, mixed>> $filteredRowsInternal
   *   Rows matching the current search and filter.
   * @param \Drupal\Core\Url|null $primaryUrl
   *   Accessible create-event URL.
   * @param string $search
   *   Normalised event-title search.
   *
   * @return array<string, string|\Drupal\Core\Url|null>
   *   Empty-state presentation model.
   */
  private function buildEmptyState(
    AccountInterface $account,
    array $allRows,
    array $filteredRowsInternal,
    ?Url $primaryUrl,
    string $search,
  ): array {
    if ($allRows === []) {
      return [
        'title' => (string) $this->t('No events yet'),
        'message' => (string) $this->t('Create your first event to sell tickets or collect RSVPs.'),
        'action_label' => (string) $this->t('Create event'),
        'url' => $primaryUrl,
      ];
    }

    if ($filteredRowsInternal === []) {
      return [
        'title' => (string) $this->t('No matches'),
        'message' => (string) $this->t('Try another filter or clear filters to see all events.'),
        'action_label' => (string) $this->t('Show all events'),
        'url' => $this->safeEventsIndexUrl(
          $account,
          'all',
          'recommended',
          '',
        ),
      ];
    }

    return [
      'title' => '',
      'message' => '',
      'action_label' => '',
      'url' => NULL,
    ];
  }

  /**
   * Normalises lifecycle filter keys and legacy query values.
   */
  private function normalizeStatus(string $raw): string {
    $v = strtolower(trim($raw));
    if ($v === 'published') {
      return 'current';
    }
    if (in_array($v, ['rsvp', 'paid', 'boosted'], TRUE)) {
      return 'all';
    }
    return in_array(
      $v,
      ['current', 'active', 'needs_attention', 'draft', 'past', 'all'],
      TRUE,
    ) ? $v : 'current';
  }

  /**
   * Normalises sort keys and legacy query values.
   */
  private function normalizeSort(string $raw): string {
    $v = strtolower(trim($raw));
    $v = match ($v) {
      'created' => 'updated',
      'soonest' => 'date',
      default => $v,
    };
    return in_array(
      $v,
      ['recommended', 'date', 'updated', 'name'],
      TRUE,
    ) ? $v : 'recommended';
  }

  /**
   * Trims and bounds the event-title search value.
   */
  private function normalizeSearch(string $raw): string {
    return mb_substr(trim($raw), 0, 100);
  }

  /**
   * Filters event rows by a case-insensitive title search.
   *
   * @param list<array<string, mixed>> $rows
   *   Event rows to search.
   * @param string $search
   *   Normalised title search.
   *
   * @return list<array<string, mixed>>
   *   Matching event rows.
   */
  private function searchRowsInternal(array $rows, string $search): array {
    if ($search === '') {
      return $rows;
    }
    return array_values(array_filter(
      $rows,
      static fn (array $row): bool => mb_stripos(
        (string) ($row['title'] ?? ''),
        $search,
      ) !== FALSE,
    ));
  }

  /**
   * Whether a lifecycle status belongs outside the current portfolio.
   */
  private function isPastStatus(string $status): bool {
    return in_array(
      $status,
      ['past', 'ended', 'cancelled', 'archived'],
      TRUE,
    );
  }

  /**
   * Builds an accessible event-index URL while preserving active controls.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Current organiser account.
   * @param string $status
   *   Lifecycle filter key.
   * @param string $sort
   *   Sort key.
   * @param string $search
   *   Event-title search.
   *
   * @return \Drupal\Core\Url|null
   *   Accessible event-index URL, or NULL.
   */
  private function safeEventsIndexUrl(
    AccountInterface $account,
    string $status,
    string $sort,
    string $search = '',
  ): ?Url {
    $query = [];
    if ($status !== 'current') {
      $query['status'] = $status;
    }
    if ($sort !== 'recommended') {
      $query['sort'] = $sort;
    }
    if ($search !== '') {
      $query['search'] = $search;
    }
    return $this->routeUrlIfAccessible(self::ROUTE_EVENTS_INDEX, [], $account, ['query' => $query]);
  }

  /**
   * Reads a timestamp from a date field when available.
   */
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
   * Whether a named route exists.
   */
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
   * Builds a route URL after checking current-account access.
   *
   * @param string $routeName
   *   Route name.
   * @param array<string, mixed> $parameters
   *   Route parameters.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Current organiser account.
   * @param array<string, mixed> $options
   *   URL options.
   *
   * @return \Drupal\Core\Url|null
   *   Accessible route URL, or NULL.
   */
  private function routeUrlIfAccessible(string $routeName, array $parameters, AccountInterface $account, array $options = []): ?Url {
    if (!$account->isAuthenticated()) {
      return NULL;
    }
    if (!$this->routeExists($routeName)) {
      return NULL;
    }
    try {
      $access = $this->accessManager->checkNamedRoute($routeName, $parameters, $account, TRUE);
      if (!$access->isAllowed()) {
        return NULL;
      }
    }
    catch (\Throwable) {
      return NULL;
    }

    try {
      return Url::fromRoute($routeName, $parameters, $options);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_vendor')->warning('Event index: failed to build URL for route @route: @message', [
        '@route' => $routeName,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

}
