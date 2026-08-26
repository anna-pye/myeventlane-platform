<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_boost\Service\BoostExtensionRecommendationService;
use Drupal\myeventlane_core\Service\EventStateResolver;
use Drupal\myeventlane_core\MelReadinessHelper;
use Drupal\myeventlane_event_state\Service\EventStateResolverInterface as LifecycleStateResolverInterface;
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

  public function __construct(
    private readonly UserVendorMembershipQuery $userVendorMembershipQuery,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TicketSalesService $ticketSalesService,
    private readonly RsvpStatsService $rsvpStatsService,
    private readonly EventStateResolver $eventStateResolver,
    private readonly LifecycleStateResolverInterface $lifecycleStateResolver,
    private readonly VendorEventPresentationAlertsBuilder $presentationAlerts,
    private readonly RouteProviderInterface $routeProvider,
    private readonly AccessManagerInterface $accessManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    TranslationInterface $string_translation,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly VendorEventRemovalService $vendorEventRemovalService,
    private readonly MelReadinessHelper $readinessHelper,
    private readonly BoostStatusService $boostStatusService,
    private readonly ?BoostExtensionRecommendationService $boostExtensionRecommendation = NULL,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @param array<string, mixed> $filters
   *   Keys: status (string), sort (string).
   *
   * @return array<string, mixed>
   *   Normalised TASK 6 index model.
   */
  public function build(AccountInterface $account, array $filters = []): array {
    $uid = (int) $account->id();
    if ($uid <= 0) {
      return $this->guestModel();
    }

    $boostFieldExists = $this->eventBundleHasBoostField();

    $statusParam = $this->normalizeStatus((string) ($filters['status'] ?? 'all'));
    $sortParam = $this->normalizeSort((string) ($filters['sort'] ?? 'created'));
    if (!$boostFieldExists && $statusParam === 'boosted') {
      $statusParam = 'all';
    }

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

    $summary = $this->buildSummary($rows);
    $filterItems = $this->buildFilterItems($account, $statusParam, $sortParam, $rows, $boostFieldExists);
    $sortItems = $this->buildSortItems($account, $statusParam, $sortParam);

    $filteredInternal = $this->filterRowsInternal($rows, $statusParam, $boostFieldExists);
    $filteredInternal = $this->sortRowsInternal($filteredInternal, $sortParam);
    $filtered = $this->stripInternals($filteredInternal);

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
      'summary' => $summary,
      'events' => $filtered,
      'empty_state' => $this->buildEmptyState($account, $rows, $filteredInternal, $primaryUrl),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function guestModel(): array {
    return [
      'title' => (string) $this->t('Events'),
      'subtitle' => (string) $this->t('Create, manage, and track your events.'),
      'primary_action' => [
        'label' => (string) $this->t('Create event'),
        'url' => NULL,
      ],
      'filters' => ['active' => 'all', 'items' => []],
      'sort' => ['active' => 'created', 'items' => []],
      'summary' => [
        'total' => 0,
        'draft' => 0,
        'current' => 0,
        'needs_attention' => 0,
        'paid' => 0,
        'rsvp' => 0,
      ],
      'events' => [],
      'empty_state' => [
        'title' => $this->readinessHelper->vendorOrganiserPortalSignInTitle(),
        'message' => $this->readinessHelper->vendorOrganiserPortalSignInEventsBody(),
        'action_label' => (string) $this->t('Create event'),
        'url' => NULL,
      ],
    ];
  }

  private function eventBundleHasBoostField(): bool {
    try {
      $defs = $this->entityFieldManager->getFieldDefinitions('node', 'event');
      return isset($defs['field_promoted']);
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * @return list<array<string, mixed>>
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
   * @return array<string, mixed>
   */
  private function buildEventRow(NodeInterface $node, AccountInterface $account): array {
    $nid = (int) $node->id();
    $startTs = $this->getDateFieldTimestamp($node, 'field_event_start');
    $endTs = $this->getDateFieldTimestamp($node, 'field_event_end');

    $statusParts = $this->lifecyclePresentation($this->lifecycleStateResolver->resolveState($node));
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

    $isTerminal = in_array($status, ['ended', 'cancelled', 'archived'], TRUE);
    $inReview = $this->isInReview($node);
    $missingDate = $startTs <= 0 && $endTs <= 0;
    $presentation = $isTerminal
      ? ['items' => []]
      : $this->presentationAlerts->buildChipSummary($node, $hasProduct, $eventType);
    $presentationItems = $presentation['items'] ?? [];
    $needsAttention = !$isTerminal && (
      $status === 'draft'
      || $inReview
      || $eventType === 'unknown'
      || $titleEmpty
      || $missingDate
      || $presentationItems !== []
    );

    $attentionLabel = $this->buildAttentionLabel($status, $eventType, $titleEmpty, $missingDate, $inReview, $presentationItems);

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
        'manage' => $this->routeUrlIfAccessible('myeventlane_event_studio.workspace', ['node' => $nid], $account),
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
    ];
  }

  /**
   * Maps a lifecycle state to its organiser-facing presentation.
   *
   * @return array{status: string, label: string, severity: string}
   *   The status key, translated label, and visual severity.
   */
  private function lifecyclePresentation(string $state): array {
    return match ($state) {
      'draft' => ['status' => 'draft', 'label' => (string) $this->t('Draft'), 'severity' => 'warning'],
      'scheduled' => ['status' => 'scheduled', 'label' => (string) $this->t('Upcoming'), 'severity' => 'neutral'],
      'live' => ['status' => 'live', 'label' => (string) $this->t('Live'), 'severity' => 'success'],
      'sold_out' => ['status' => 'sold_out', 'label' => (string) $this->t('Sold out'), 'severity' => 'warning'],
      'ended' => ['status' => 'ended', 'label' => (string) $this->t('Completed'), 'severity' => 'neutral'],
      'cancelled' => ['status' => 'cancelled', 'label' => (string) $this->t('Cancelled'), 'severity' => 'error'],
      'archived' => ['status' => 'archived', 'label' => (string) $this->t('Archived'), 'severity' => 'neutral'],
      default => ['status' => 'unknown', 'label' => (string) $this->t('Event'), 'severity' => 'neutral'],
    };
  }

  /**
   * Determines whether the event is waiting for moderation review.
   */
  private function isInReview(NodeInterface $node): bool {
    return $node->hasField('moderation_state')
      && !$node->get('moderation_state')->isEmpty()
      && (string) $node->get('moderation_state')->value === 'review';
  }

  /**
   * Builds the first useful setup instruction for an event card.
   *
   * @param string $status
   *   The canonical lifecycle state.
   * @param string $eventType
   *   The booking method derived from saved event information.
   * @param bool $titleEmpty
   *   Whether the event title is empty.
   * @param bool $missingDate
   *   Whether the event has no start or end date.
   * @param bool $inReview
   *   Whether the event is waiting for moderation review.
   * @param list<array<string, mixed>> $presentationItems
   *   Additional setup issues.
   *
   * @return string|null
   *   The organiser-facing instruction, or NULL when none is needed.
   */
  private function buildAttentionLabel(string $status, string $eventType, bool $titleEmpty, bool $missingDate, bool $inReview, array $presentationItems = []): ?string {
    $base = NULL;
    if ($titleEmpty) {
      $base = (string) $this->t('Add a title');
    }
    elseif ($missingDate) {
      $base = (string) $this->t('Add date & time');
    }
    elseif ($inReview) {
      $base = (string) $this->t('Needs review');
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
   * @param list<array<string, mixed>> $rows
   *
   * @return array<string, int>
   */
  private function buildSummary(array $rows): array {
    $summary = [
      'total' => count($rows),
      'draft' => 0,
      'current' => 0,
      'needs_attention' => 0,
      'paid' => 0,
      'rsvp' => 0,
    ];

    foreach ($rows as $row) {
      $st = (string) ($row['status'] ?? '');
      if ($st === 'draft') {
        $summary['draft']++;
      }
      elseif (in_array($st, ['scheduled', 'live', 'sold_out'], TRUE)) {
        $summary['current']++;
      }

      if (!empty($row['needs_attention'])) {
        $summary['needs_attention']++;
      }

      $et = (string) ($row['event_type'] ?? 'unknown');
      if ($et === 'paid' || $et === 'both') {
        $summary['paid']++;
      }
      if ($et === 'rsvp' || $et === 'both') {
        $summary['rsvp']++;
      }
    }

    return $summary;
  }

  /**
   * @param list<array<string, mixed>> $rows
   *
   * @return list<array<string, mixed>>
   */
  private function buildFilterItems(AccountInterface $account, string $activeStatus, string $sort, array $rows, bool $boostFieldExists): array {
    $definitions = [
      'all' => (string) $this->t('All'),
      'needs_attention' => (string) $this->t('Needs attention'),
      'draft' => (string) $this->t('Draft'),
      'current' => (string) $this->t('Current'),
      'ended' => (string) $this->t('Completed'),
      'cancelled' => (string) $this->t('Cancelled'),
      'archived' => (string) $this->t('Archived'),
      'rsvp' => (string) $this->t('RSVP'),
      'paid' => (string) $this->t('Paid'),
    ];

    $items = [];
    foreach ($definitions as $key => $label) {
      $items[] = [
        'key' => $key,
        'label' => $label,
        'url' => $this->safeEventsIndexUrl($account, $key, $sort),
        'active' => $activeStatus === $key,
        'count' => $this->countForFilter($rows, $key, $boostFieldExists),
      ];
    }

    if ($boostFieldExists) {
      $items[] = [
        'key' => 'boosted',
        'label' => (string) $this->t('Boosted'),
        'url' => $this->safeEventsIndexUrl($account, 'boosted', $sort),
        'active' => $activeStatus === 'boosted',
        'count' => $this->countForFilter($rows, 'boosted', TRUE),
      ];
    }

    return $items;
  }

  /**
   * @param list<array<string, mixed>> $rows
   */
  private function countForFilter(array $rows, string $key, bool $boostFieldExists): int {
    if ($key === 'all') {
      return count($rows);
    }
    $n = 0;
    foreach ($rows as $row) {
      if ($this->rowMatchesFilter($row, $key, $boostFieldExists)) {
        $n++;
      }
    }
    return $n;
  }

  /**
   * @param array<string, mixed> $row
   */
  private function rowMatchesFilter(array $row, string $key, bool $boostFieldExists): bool {
    return match ($key) {
      'draft' => ($row['status'] ?? '') === 'draft',
      'current' => in_array(($row['status'] ?? ''), ['scheduled', 'live', 'sold_out'], TRUE),
      'ended' => ($row['status'] ?? '') === 'ended',
      'cancelled' => ($row['status'] ?? '') === 'cancelled',
      'archived' => ($row['status'] ?? '') === 'archived',
      'needs_attention' => !empty($row['needs_attention']),
      'rsvp' => in_array(($row['event_type'] ?? ''), ['rsvp', 'both'], TRUE),
      'paid' => in_array(($row['event_type'] ?? ''), ['paid', 'both'], TRUE),
      'boosted' => !empty($row['boost']['active']),
      default => FALSE,
    };
  }

  /**
   * @param list<array<string, mixed>> $rows
   *
   * @return list<array<string, mixed>>
   */
  private function filterRowsInternal(array $rows, string $status, bool $boostFieldExists): array {
    if ($status === 'all') {
      return $rows;
    }
    $out = [];
    foreach ($rows as $row) {
      if ($this->rowMatchesFilter($row, $status, $boostFieldExists)) {
        $out[] = $row;
      }
    }
    return $out;
  }

  /**
   * @param list<array<string, mixed>> $rows
   *
   * @return list<array<string, mixed>>
   */
  private function stripInternals(array $rows): array {
    return array_map(fn (array $row): array => $this->stripInternalsRow($row), $rows);
  }

  /**
   * @param array<string, mixed> $row
   *
   * @return array<string, mixed>
   */
  private function stripInternalsRow(array $row): array {
    unset($row['_created'], $row['_changed'], $row['_start_ts']);
    return $row;
  }

  /**
   * @param list<array<string, mixed>> $rows
   *
   * @return list<array<string, mixed>>
   */
  private function sortRowsInternal(array $rows, string $sort): array {
    if ($sort === 'created') {
      usort($rows, static function (array $a, array $b): int {
        return (int) ($b['_created'] ?? 0) <=> (int) ($a['_created'] ?? 0);
      });
    }
    elseif ($sort === 'updated') {
      usort($rows, static function (array $a, array $b): int {
        return (int) ($b['_changed'] ?? 0) <=> (int) ($a['_changed'] ?? 0);
      });
    }
    else {
      usort($rows, static function (array $a, array $b): int {
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
        return $sa <=> $sb;
      });
    }

    return $rows;
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function buildSortItems(AccountInterface $account, string $status, string $activeSort): array {
    return [
      [
        'key' => 'created',
        'label' => (string) $this->t('Newest created'),
        'url' => $this->safeEventsIndexUrl($account, $status, 'created'),
        'active' => $activeSort === 'created',
      ],
      [
        'key' => 'soonest',
        'label' => (string) $this->t('Soonest'),
        'url' => $this->safeEventsIndexUrl($account, $status, 'soonest'),
        'active' => $activeSort === 'soonest',
      ],
      [
        'key' => 'updated',
        'label' => (string) $this->t('Recently updated'),
        'url' => $this->safeEventsIndexUrl($account, $status, 'updated'),
        'active' => $activeSort === 'updated',
      ],
    ];
  }

  /**
   * @param list<array<string, mixed>> $allRows
   * @param list<array<string, mixed>> $filteredRowsInternal
   *
   * @return array<string, string|\Drupal\Core\Url|null>
   */
  private function buildEmptyState(AccountInterface $account, array $allRows, array $filteredRowsInternal, ?Url $primaryUrl): array {
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
        'url' => $this->safeEventsIndexUrl($account, 'all', 'created'),
      ];
    }

    return [
      'title' => '',
      'message' => '',
      'action_label' => '',
      'url' => NULL,
    ];
  }

  private function normalizeStatus(string $raw): string {
    $v = strtolower(trim($raw));
    $v = match ($v) {
      'published' => 'current',
      'past' => 'ended',
      default => $v,
    };
    $allowed = [
      'all',
      'needs_attention',
      'draft',
      'current',
      'ended',
      'cancelled',
      'archived',
      'rsvp',
      'paid',
      'boosted',
    ];
    return in_array($v, $allowed, TRUE) ? $v : 'all';
  }

  private function normalizeSort(string $raw): string {
    $v = strtolower(trim($raw));
    return in_array($v, ['created', 'soonest', 'updated'], TRUE) ? $v : 'created';
  }

  private function safeEventsIndexUrl(AccountInterface $account, string $status, string $sort): ?Url {
    $query = [];
    if ($status !== 'all') {
      $query['status'] = $status;
    }
    if ($sort !== 'created') {
      $query['sort'] = $sort;
    }
    return $this->routeUrlIfAccessible(self::ROUTE_EVENTS_INDEX, [], $account, ['query' => $query]);
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
   * @param array<string, mixed> $parameters
   * @param array<string, mixed> $options
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
