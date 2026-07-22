<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\myeventlane_core\MelReadinessHelper;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_core\Service\EventStateResolver;
use Drupal\node\NodeInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * View model for `/vendor/events/{event}` event workspace (TASK 8).
 */
final class VendorEventWorkspaceViewModelBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly EventStateResolver $eventStateResolver,
    private readonly TicketSalesService $ticketSalesService,
    private readonly RsvpStatsService $rsvpStatsService,
    private readonly VendorEventTabsService $vendorEventTabsService,
    private readonly VendorEventPresentationAlertsBuilder $presentationAlerts,
    private readonly RouteProviderInterface $routeProvider,
    private readonly AccessManagerInterface $accessManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $time,
    TranslationInterface $string_translation,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly MelReadinessHelper $readinessHelper,
    private readonly VendorEventRemovalService $vendorEventRemovalService,
    private readonly ?DomainDetector $domainDetector = NULL,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @return array<string, mixed>
   */
  public function build(NodeInterface $event, AccountInterface $account): array {
    try {
      return $this->doBuild($event, $account);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_vendor')->error('Event workspace model failed for nid @nid: @message', [
        '@nid' => (string) $event->id(),
        '@message' => $e->getMessage(),
      ]);
      return $this->fallbackModel($event);
    }
  }

  /**
   * @return array<string, mixed>
   */
  private function doBuild(NodeInterface $event, AccountInterface $account): array {
    $nid = (int) $event->id();

    $domain = $this->eventStateResolver->getEventDomainState($event);
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
      'paid' => (string) $this->t('Paid tickets'),
      'rsvp' => (string) $this->t('RSVP'),
      'both' => (string) $this->t('RSVP and paid tickets'),
      default => (string) $this->t('Booking not set'),
    };

    $statusParts = $this->resolvePublicationStatus($event);
    $status = $statusParts['status'];
    $statusLabel = $statusParts['label'];
    $statusSeverity = $statusParts['severity'];

    $startTs = $this->getDateFieldTimestamp($event, 'field_event_start');
    $dateLabel = $startTs > 0
      ? $this->dateFormatter->format($startTs, 'medium')
      : '';

    $salesSummary = [];
    try {
      $salesSummary = $this->ticketSalesService->getSalesSummary($event);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_vendor')->warning('Workspace sales summary failed for nid @nid: @message', [
        '@nid' => (string) $nid,
        '@message' => $e->getMessage(),
      ]);
    }

    $rsvpCount = 0;
    try {
      $rsvpCount = $this->rsvpStatsService->getEventRsvpCount($nid);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_vendor')->warning('Workspace RSVP count failed for nid @nid: @message', [
        '@nid' => (string) $nid,
        '@message' => $e->getMessage(),
      ]);
    }

    $ticketsSold = (int) ($salesSummary['tickets_sold'] ?? 0);
    $ordersCount = (int) ($salesSummary['orders_count'] ?? 0);
    $grossDisplay = (string) ($salesSummary['gross'] ?? '');

    $eventBundleFields = $this->getEventFieldNames();
    $title = trim((string) $event->getTitle());

    $editUrl = $this->routeUrlIfAccessible('myeventlane_event_studio.edit', ['node' => $nid], $account);
    $advancedTicketsUrl = $this->routeUrlIfAccessible('myeventlane_vendor.console.event_tickets', ['event' => $nid], $account);

    $presentationAlerts = $this->presentationAlerts->buildWorkspaceAlerts(
      $event,
      $hasProduct,
      $eventType,
      $editUrl,
      $advancedTicketsUrl,
    );

    $readinessItems = $this->buildReadinessItems($event, $eventType, $title, $startTs, $eventBundleFields, $editUrl);
    $completeCount = count(array_filter($readinessItems, static fn(array $i): bool => !empty($i['complete'])));
    $score = $readinessItems !== []
      ? (int) round(100 * $completeCount / count($readinessItems))
      : 0;
    $hasBanner = $event->hasField('field_event_image') && !$event->get('field_event_image')->isEmpty();
    $hasCategory = $event->hasField('field_category') && !$event->get('field_category')->isEmpty();
    $hasTags = $event->hasField('field_tags') && !$event->get('field_tags')->isEmpty();
    $isPromoted = $event->hasField('field_promoted')
      && !$event->get('field_promoted')->isEmpty()
      && (bool) $event->get('field_promoted')->value;

    $nextAction = $this->buildNextAction(
      $eventType,
      $status,
      $editUrl,
      $this->routeUrlIfAccessible('myeventlane_event_attendees.vendor_list', ['node' => $nid], $account),
      $this->routeUrlIfAccessible('myeventlane_vendor.console.event_rsvps', ['event' => $nid], $account),
      $this->routeUrlIfAccessible('myeventlane_vendor.console.event_orders', ['event' => $nid], $account),
      $ticketsSold,
      $ordersCount,
      $rsvpCount,
    );

    $metrics = $this->buildMetrics(
      $eventType,
      $grossDisplay,
      $ticketsSold,
      $ordersCount,
      $rsvpCount,
      $account,
      $nid,
    );

    $tabs = $this->vendorEventTabsService->buildWorkspaceTabs($event, 'overview', $account);

    $previewUrl = $this->routeUrlIfAccessible('entity.node.canonical', ['node' => $nid], $account);
    $publicPreviewUrl = $this->domainDetector?->publicUrlObject($previewUrl) ?? $previewUrl;

    // Reuse tab availability so add-on order gating runs once per request.
    $addonOrdersUrl = $this->addonOrdersUrlFromWorkspaceTabs($tabs);

    $ordersUrl = $this->routeUrlIfAccessible('myeventlane_vendor.console.event_orders', ['event' => $nid], $account);
    $attendeesUrl = $this->routeUrlIfAccessible('myeventlane_event_attendees.vendor_list', ['node' => $nid], $account);
    $rsvpsUrl = $this->routeUrlIfAccessible('myeventlane_vendor.console.event_rsvps', ['event' => $nid], $account);
    $checkinUrl = $this->routeUrlIfAccessible('myeventlane_event_attendees.vendor_operations_door', ['node' => $nid], $account);
    $editTicketsUrl = $this->routeUrlIfAccessible('myeventlane_event_studio.workspace_tickets', ['node' => $nid], $account)
      ?? $advancedTicketsUrl;
    $extrasUrl = $this->routeUrlIfAccessible('myeventlane_event_studio.workspace_extras', ['node' => $nid], $account);
    $promoteUrl = $this->routeUrlIfAccessible('myeventlane_vendor.console.event_promotion', ['event' => $nid], $account);

    $checkins = 0;
    try {
      $rsvpStats = $this->rsvpStatsService->getStatsForEvent($nid);
      $checkins = (int) ($rsvpStats['checkins'] ?? 0);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_vendor')->warning('Workspace check-in count failed for nid @nid: @message', [
        '@nid' => (string) $nid,
        '@message' => $e->getMessage(),
      ]);
    }

    $operationalItems = $this->readinessHelper->vendorEventWorkspaceOperationalSummary(
      $eventType,
      $status,
      $ticketsSold,
      $ordersCount,
      $rsvpCount,
    );

    $actions = [
      'edit' => $editUrl,
      'advanced_tickets' => $advancedTicketsUrl,
      'edit_tickets' => $editTicketsUrl,
      'extras' => $extrasUrl,
      'promote' => $promoteUrl,
      'rsvps' => $rsvpsUrl,
      'orders' => $ordersUrl,
      'addon_orders' => $addonOrdersUrl,
      'attendees' => $attendeesUrl,
      'checkin' => $checkinUrl,
      'analytics' => $this->routeUrlIfAccessible('myeventlane_vendor.console.event_analytics', ['event' => $nid], $account),
      'settings' => $this->routeUrlIfAccessible('myeventlane_vendor.console.event_settings', ['event' => $nid], $account),
      'preview' => $publicPreviewUrl,
    ];

    return [
      'event' => [
        'nid' => $nid,
        'title' => $title !== '' ? $title : (string) $this->t('Untitled event'),
        'status' => $status,
        'status_label' => $statusLabel,
        'status_severity' => $statusSeverity,
        'date_label' => $dateLabel,
        'event_type' => $eventType,
        'event_type_label' => $eventTypeLabel,
        'public_url' => $publicPreviewUrl,
        'image' => $this->vendorEventRemovalService->buildEventThumbnailData($event),
      ],
      'readiness' => [
        'score' => $score,
        'items' => $readinessItems,
      ],
      'operational_readiness' => [
        'heading' => $this->readinessHelper->vendorEventWorkspaceOperationalSummaryHeading(),
        'intro' => $this->readinessHelper->vendorEventWorkspaceOperationalSummaryIntro(),
        'items' => $operationalItems,
      ],
      'todays_focus' => $this->buildTodaysFocus(
        $eventType,
        $status,
        $score,
        $readinessItems,
        $actions,
        $ticketsSold,
        $ordersCount,
        $rsvpCount,
      ),
      'sales_snapshot' => $this->buildSalesSnapshot(
        $eventType,
        $grossDisplay,
        $ticketsSold,
        $ordersCount,
        $rsvpCount,
        $checkins,
        $account,
        $nid,
      ),
      'action_grid' => $this->buildActionGrid($actions),
      'readiness_summary' => $this->buildReadinessSummary($operationalItems, $readinessItems),
      'lifecycle_guidance' => $this->readinessHelper->vendorEventWorkspaceLifecycleSummary(
        $eventType,
        $status,
        $ticketsSold,
        $ordersCount,
        $rsvpCount,
        $hasBanner,
        $hasCategory,
        $hasTags,
        $isPromoted,
      ),
      'next_action' => $nextAction,
      'metrics' => $metrics,
      'tabs' => $tabs,
      'actions' => $actions,
      'presentation_alerts' => $presentationAlerts,
      'empty_state' => [
        'title' => '',
        'message' => '',
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function fallbackModel(NodeInterface $event): array {
    $nid = (int) $event->id();
    return [
      'event' => [
        'nid' => $nid,
        'title' => (string) $event->getTitle(),
        'status' => 'unknown',
        'status_label' => (string) $this->t('Unknown'),
        'status_severity' => 'neutral',
        'date_label' => '',
        'event_type' => 'unknown',
        'event_type_label' => (string) $this->t('Unknown'),
        'public_url' => NULL,
        'image' => $this->vendorEventRemovalService->buildEventThumbnailData($event),
      ],
      'readiness' => ['score' => 0, 'items' => []],
      'operational_readiness' => [
        'heading' => $this->readinessHelper->vendorEventWorkspaceOperationalSummaryHeading(),
        'intro' => $this->readinessHelper->vendorEventWorkspaceOperationalSummaryIntro(),
        'items' => [],
      ],
      'lifecycle_guidance' => $this->readinessHelper->vendorEventWorkspaceLifecycleSummary(
        'unknown',
        'unknown',
        0,
        0,
        0,
        FALSE,
        FALSE,
        FALSE,
        FALSE,
      ),
      'next_action' => [
        'severity' => 'warning',
        'title' => (string) $this->t('Something went wrong'),
        'message' => (string) $this->t('Reload this page or try again shortly.'),
        'action_label' => NULL,
        'url' => NULL,
      ],
      'metrics' => [],
      'tabs' => [],
      'presentation_alerts' => [],
      'todays_focus' => [
        'message' => (string) $this->t('Reload this page or try again shortly.'),
        'severity' => 'warning',
        'actions' => [],
      ],
      'sales_snapshot' => ['metrics' => []],
      'action_grid' => ['sales' => [], 'setup' => [], 'growth' => []],
      'readiness_summary' => [
        'all_ready' => FALSE,
        'issue_count' => 0,
        'groups' => [],
        'detail_items' => [],
      ],
      'actions' => [
        'edit' => NULL,
        'advanced_tickets' => NULL,
        'edit_tickets' => NULL,
        'extras' => NULL,
        'promote' => NULL,
        'rsvps' => NULL,
        'orders' => NULL,
        'addon_orders' => NULL,
        'attendees' => NULL,
        'checkin' => NULL,
        'analytics' => NULL,
        'settings' => NULL,
        'preview' => NULL,
      ],
      'empty_state' => [
        'title' => (string) $this->t('Workspace unavailable'),
        'message' => (string) $this->t('We could not load this event workspace. Please try again.'),
      ],
    ];
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  private function buildReadinessItems(
    NodeInterface $event,
    string $eventType,
    string $title,
    int $startTs,
    array $eventBundleFields,
    ?Url $editUrl,
  ): array {
    $items = [];

    $titleOk = $title !== '';
    $items[] = [
      'key' => 'title',
      'label' => (string) $this->t('Event title'),
      'complete' => $titleOk,
      'severity' => $titleOk ? 'success' : 'warning',
      'url' => $titleOk ? NULL : $editUrl,
    ];

    $dateFieldExists = in_array('field_event_start', $eventBundleFields, TRUE);
    if ($dateFieldExists) {
      $dateOk = $startTs > 0;
      $items[] = [
        'key' => 'datetime',
        'label' => (string) $this->t('Date and time'),
        'complete' => $dateOk,
        'severity' => $dateOk ? 'success' : 'warning',
        'url' => $dateOk ? NULL : $editUrl,
      ];
    }

    $bookingOk = $eventType !== 'unknown';
    $items[] = [
      'key' => 'booking',
      'label' => (string) $this->t('RSVP or ticket setup'),
      'complete' => $bookingOk,
      'severity' => $bookingOk ? 'success' : 'error',
      'url' => $bookingOk ? NULL : $editUrl,
    ];

    if (in_array('body', $eventBundleFields, TRUE) && $event->hasField('body')) {
      $bodyOk = !$event->get('body')->isEmpty()
        && trim((string) $event->get('body')->value) !== '';
      $items[] = [
        'key' => 'description',
        'label' => (string) $this->t('Description'),
        'complete' => $bodyOk,
        'severity' => $bodyOk ? 'success' : 'neutral',
        'url' => $bodyOk ? NULL : $editUrl,
      ];
    }

    $publishedOk = $event->isPublished();
    $items[] = [
      'key' => 'published',
      'label' => (string) $this->t('Published'),
      'complete' => $publishedOk,
      'severity' => $publishedOk ? 'success' : 'warning',
      'url' => $publishedOk ? NULL : $editUrl,
    ];

    if (in_array('field_event_image', $eventBundleFields, TRUE) && $event->hasField('field_event_image')) {
      $imageOk = !$event->get('field_event_image')->isEmpty();
      $items[] = [
        'key' => 'banner',
        'label' => (string) $this->t('Banner image'),
        'complete' => $imageOk,
        'severity' => $imageOk ? 'success' : 'neutral',
        'url' => $imageOk ? NULL : $editUrl,
      ];
    }

    return $items;
  }

  /**
   * @return list<string>
   */
  private function getEventFieldNames(): array {
    try {
      $defs = $this->entityFieldManager->getFieldDefinitions('node', 'event');
      return array_keys($defs);
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * @return array{status: string, label: string, severity: string}
   */
  private function resolvePublicationStatus(NodeInterface $node): array {
    $now = (int) $this->time->getRequestTime();
    $endTs = $this->getDateFieldTimestamp($node, 'field_event_end');

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
        'label' => (string) $this->t('In review'),
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
      'label' => (string) $this->t('Live'),
      'severity' => 'success',
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
   * @return array<string, mixed>|null
   */
  private function buildNextAction(
    string $eventType,
    string $status,
    ?Url $editUrl,
    ?Url $attendeesUrl,
    ?Url $rsvpsUrl,
    ?Url $ordersUrl,
    int $ticketsSold,
    int $ordersCount,
    int $rsvpCount,
  ): ?array {
    if ($eventType === 'unknown') {
      return [
        'severity' => 'error',
        'title' => (string) $this->t('Add RSVP or tickets'),
        'message' => (string) $this->t('Choose how people register so your event can accept bookings.'),
        'action_label' => (string) $this->t('Edit event'),
        'url' => $editUrl,
      ];
    }

    if ($status === 'draft' || $status === 'unknown') {
      return [
        'severity' => 'warning',
        'title' => (string) $this->t('Finish and publish your event'),
        'message' => (string) $this->t('Review your details and publish when you are ready to go live.'),
        'action_label' => (string) $this->t('Continue editing'),
        'url' => $editUrl,
      ];
    }

    $hasActivity = $ticketsSold > 0 || $ordersCount > 0 || $rsvpCount > 0;
    if ($hasActivity && ($status === 'published' || $status === 'past')) {
      $target = $attendeesUrl;
      $label = (string) $this->t('View attendees');
      if ($eventType === 'rsvp' && $rsvpsUrl !== NULL) {
        $target = $rsvpsUrl;
        $label = (string) $this->t('View RSVPs');
      }
      elseif ($eventType === 'both') {
        if ($rsvpCount > 0 && $rsvpsUrl !== NULL) {
          $target = $rsvpsUrl;
          $label = (string) $this->t('View RSVPs');
        }
        elseif ($ordersUrl !== NULL && $ordersCount > 0) {
          $target = $ordersUrl;
          $label = (string) $this->t('View orders');
        }
      }
      elseif (($eventType === 'paid') && $ordersUrl !== NULL && $ordersCount > 0) {
        $target = $ordersUrl;
        $label = (string) $this->t('View orders');
      }

      return [
        'severity' => 'info',
        'title' => (string) $this->t('Manage bookings'),
        'message' => (string) $this->t('You have attendee or order activity for this event.'),
        'action_label' => $label,
        'url' => $target,
      ];
    }

    return [
      'severity' => 'success',
      'title' => (string) $this->t('Event looks ready'),
      'message' => (string) $this->t('Keep an eye on bookings and attendee activity.'),
      'action_label' => NULL,
      'url' => NULL,
    ];
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function buildMetrics(
    string $eventType,
    string $grossDisplay,
    int $ticketsSold,
    int $ordersCount,
    int $rsvpCount,
    AccountInterface $account,
    int $nid,
  ): array {
    $metrics = [];

    if ($eventType === 'paid' || $eventType === 'both') {
      $grossVal = $grossDisplay !== '' ? $grossDisplay : '—';
      $metrics[] = [
        'key' => 'gross',
        'label' => (string) $this->t('Gross sales'),
        'value' => $grossVal,
        'context' => (string) $this->t('Completed orders'),
        'severity' => 'neutral',
        'url' => $this->routeUrlIfAccessible('myeventlane_vendor.console.event_orders', ['event' => $nid], $account),
      ];
      $metrics[] = [
        'key' => 'tickets_sold',
        'label' => (string) $this->t('Tickets sold'),
        'value' => (string) $ticketsSold,
        'context' => NULL,
        'severity' => 'neutral',
        'url' => $this->routeUrlIfAccessible('myeventlane_vendor.console.event_tickets', ['event' => $nid], $account),
      ];
      $metrics[] = [
        'key' => 'orders',
        'label' => (string) $this->t('Orders'),
        'value' => (string) $ordersCount,
        'context' => NULL,
        'severity' => 'neutral',
        'url' => $this->routeUrlIfAccessible('myeventlane_vendor.console.event_orders', ['event' => $nid], $account),
      ];
    }

    if ($eventType === 'rsvp' || $eventType === 'both') {
      $metrics[] = [
        'key' => 'rsvps',
        'label' => (string) $this->t('Confirmed RSVPs'),
        'value' => (string) $rsvpCount,
        'context' => NULL,
        'severity' => 'neutral',
        'url' => $this->routeUrlIfAccessible('myeventlane_vendor.console.event_rsvps', ['event' => $nid], $account),
      ];
    }

    if ($eventType === 'unknown') {
      $metrics[] = [
        'key' => 'booking',
        'label' => (string) $this->t('Booking setup'),
        'value' => '—',
        'context' => (string) $this->t('Not configured'),
        'severity' => 'warning',
        'url' => $this->routeUrlIfAccessible('myeventlane_event_studio.edit', ['node' => $nid], $account),
      ];
    }

    return $metrics;
  }

  /**
   * @param array<string, ?\Drupal\Core\Url> $actions
   *
   * @return array{message: string, severity: string, actions: list<array{label: string, url: string, primary: bool}>}
   */
  private function buildTodaysFocus(
    string $eventType,
    string $status,
    int $readinessScore,
    array $readinessItems,
    array $actions,
    int $ticketsSold,
    int $ordersCount,
    int $rsvpCount,
  ): array {
    $incomplete = array_filter($readinessItems, static fn(array $i): bool => empty($i['complete']));
    $hasActivity = $ticketsSold > 0 || $ordersCount > 0 || $rsvpCount > 0;
    $isLive = $status === 'published' || $status === 'past';

    if ($eventType === 'unknown') {
      return [
        'message' => (string) $this->t('Finish setup before publishing.'),
        'severity' => 'warning',
        'actions' => $this->pickFocusActions([
          ['label' => (string) $this->t('Edit event'), 'url' => $actions['edit'] ?? NULL, 'primary' => TRUE],
          ['label' => (string) $this->t('Edit tickets'), 'url' => $actions['edit_tickets'] ?? $actions['advanced_tickets'] ?? NULL, 'primary' => FALSE],
          ['label' => (string) $this->t('Preview public event'), 'url' => $actions['preview'] ?? NULL, 'primary' => FALSE],
        ]),
      ];
    }

    if ($status === 'draft' || $status === 'unknown') {
      return [
        'message' => (string) $this->t('Finish setup before publishing.'),
        'severity' => 'warning',
        'actions' => $this->pickFocusActions([
          ['label' => (string) $this->t('Edit event'), 'url' => $actions['edit'] ?? NULL, 'primary' => TRUE],
          ['label' => (string) $this->t('Edit tickets'), 'url' => $actions['edit_tickets'] ?? $actions['advanced_tickets'] ?? NULL, 'primary' => FALSE],
          ['label' => (string) $this->t('Preview public event'), 'url' => $actions['preview'] ?? NULL, 'primary' => FALSE],
        ]),
      ];
    }

    if ($incomplete !== [] && $readinessScore < 100) {
      return [
        'message' => (string) $this->t('Review these items before sharing.'),
        'severity' => 'info',
        'actions' => $this->pickFocusActions([
          ['label' => (string) $this->t('Edit event'), 'url' => $actions['edit'] ?? NULL, 'primary' => TRUE],
          ['label' => (string) $this->t('Preview public event'), 'url' => $actions['preview'] ?? NULL, 'primary' => FALSE],
          ['label' => (string) $this->t('Messages'), 'url' => $actions['promote'] ?? NULL, 'primary' => FALSE],
        ]),
      ];
    }

    if ($isLive && $hasActivity) {
      return [
        'message' => (string) $this->t('Your event is live and taking bookings.'),
        'severity' => 'success',
        'actions' => $this->pickFocusActions([
          ['label' => (string) $this->t('View orders'), 'url' => $actions['orders'] ?? NULL, 'primary' => TRUE],
          ['label' => (string) $this->t('Edit tickets'), 'url' => $actions['edit_tickets'] ?? $actions['advanced_tickets'] ?? NULL, 'primary' => FALSE],
          ['label' => (string) $this->t('Add merch & add-ons'), 'url' => $actions['extras'] ?? NULL, 'primary' => FALSE],
        ]),
      ];
    }

    if ($isLive) {
      return [
        'message' => (string) $this->t('Your event is live. Share it to start collecting bookings.'),
        'severity' => 'success',
        'actions' => $this->pickFocusActions([
          ['label' => (string) $this->t('Messages'), 'url' => $actions['promote'] ?? NULL, 'primary' => TRUE],
          ['label' => (string) $this->t('Preview public event'), 'url' => $actions['preview'] ?? NULL, 'primary' => FALSE],
          ['label' => (string) $this->t('Edit tickets'), 'url' => $actions['edit_tickets'] ?? $actions['advanced_tickets'] ?? NULL, 'primary' => FALSE],
        ]),
      ];
    }

    return [
      'message' => (string) $this->t('Keep an eye on bookings and attendee activity.'),
      'severity' => 'info',
      'actions' => $this->pickFocusActions([
        ['label' => (string) $this->t('Edit event'), 'url' => $actions['edit'] ?? NULL, 'primary' => TRUE],
        ['label' => (string) $this->t('Preview public event'), 'url' => $actions['preview'] ?? NULL, 'primary' => FALSE],
        ['label' => (string) $this->t('Edit tickets'), 'url' => $actions['edit_tickets'] ?? $actions['advanced_tickets'] ?? NULL, 'primary' => FALSE],
      ]),
    ];
  }

  /**
   * @param list<array{label: string, url: ?Url, primary: bool}> $candidates
   *
   * @return list<array{label: string, url: string, primary: bool}>
   */
  private function pickFocusActions(array $candidates): array {
    $picked = [];
    foreach ($candidates as $candidate) {
      if (count($picked) >= 3) {
        break;
      }
      $url = $candidate['url'] ?? NULL;
      if (!$url instanceof Url) {
        continue;
      }
      $picked[] = [
        'label' => $candidate['label'],
        'url' => $url->toString(),
        'primary' => !empty($candidate['primary']) && count(array_filter($picked, static fn(array $a): bool => $a['primary'])) === 0,
      ];
    }
    if ($picked !== [] && !array_filter($picked, static fn(array $a): bool => $a['primary'])) {
      $picked[0]['primary'] = TRUE;
    }
    return $picked;
  }

  /**
   * @return array{metrics: list<array<string, mixed>>}
   */
  private function buildSalesSnapshot(
    string $eventType,
    string $grossDisplay,
    int $ticketsSold,
    int $ordersCount,
    int $rsvpCount,
    int $checkins,
    AccountInterface $account,
    int $nid,
  ): array {
    $metrics = [];

    if ($eventType === 'paid' || $eventType === 'both') {
      $metrics[] = [
        'key' => 'gross',
        'label' => (string) $this->t('Gross sales'),
        'value' => $grossDisplay !== '' ? $grossDisplay : '—',
        'url' => $this->routeUrlIfAccessible('myeventlane_vendor.console.event_orders', ['event' => $nid], $account)?->toString(),
      ];
      $metrics[] = [
        'key' => 'tickets_sold',
        'label' => (string) $this->t('Tickets sold'),
        'value' => (string) $ticketsSold,
        'url' => $this->routeUrlIfAccessible('myeventlane_vendor.console.event_tickets', ['event' => $nid], $account)?->toString(),
      ];
    }

    if ($eventType === 'rsvp' || $eventType === 'both') {
      $metrics[] = [
        'key' => 'rsvps',
        'label' => (string) $this->t('Confirmed RSVPs'),
        'value' => (string) $rsvpCount,
        'url' => $this->routeUrlIfAccessible('myeventlane_vendor.console.event_rsvps', ['event' => $nid], $account)?->toString(),
      ];
    }

    if ($eventType !== 'unknown') {
      $metrics[] = [
        'key' => 'checkins',
        'label' => (string) $this->t('Check-ins'),
        'value' => (string) $checkins,
        'url' => $this->routeUrlIfAccessible('myeventlane_event_attendees.vendor_operations_door', ['node' => $nid], $account)?->toString(),
      ];
    }

    if ($eventType === 'paid' || $eventType === 'both') {
      $metrics[] = [
        'key' => 'orders',
        'label' => (string) $this->t('Orders'),
        'value' => (string) $ordersCount,
        'url' => $this->routeUrlIfAccessible('myeventlane_vendor.console.event_orders', ['event' => $nid], $account)?->toString(),
      ];
    }

    return ['metrics' => $metrics];
  }

  /**
   * @param array<string, ?Url> $actions
   *
   * @return array{sales: list<array{label: string, url: string}>, setup: list<array{label: string, url: string}>, growth: list<array{label: string, url: string}>}
   */
  private function buildActionGrid(array $actions): array {
    return [
      'sales' => $this->pickGridActions([
        ['label' => (string) $this->t('View orders'), 'url' => $actions['orders'] ?? NULL],
        ['label' => (string) $this->t('Attendees'), 'url' => $actions['attendees'] ?? NULL],
        ['label' => (string) $this->t('Check-in'), 'url' => $actions['checkin'] ?? NULL],
      ]),
      'setup' => $this->pickGridActions([
        ['label' => (string) $this->t('Edit event'), 'url' => $actions['edit'] ?? NULL],
        ['label' => (string) $this->t('Edit tickets'), 'url' => $actions['edit_tickets'] ?? $actions['advanced_tickets'] ?? NULL],
        ['label' => (string) $this->t('Merch & add-ons'), 'url' => $actions['extras'] ?? NULL],
      ]),
      'growth' => $this->pickGridActions([
        ['label' => (string) $this->t('Messages'), 'url' => $actions['promote'] ?? NULL],
        ['label' => (string) $this->t('View analytics'), 'url' => $actions['analytics'] ?? NULL],
      ]),
    ];
  }

  /**
   * @param list<array{label: string, url: ?Url}> $candidates
   *
   * @return list<array{label: string, url: string}>
   */
  private function pickGridActions(array $candidates): array {
    $picked = [];
    foreach ($candidates as $candidate) {
      $url = $candidate['url'] ?? NULL;
      if (!$url instanceof Url) {
        continue;
      }
      $picked[] = [
        'label' => $candidate['label'],
        'url' => $url->toString(),
      ];
    }
    return $picked;
  }

  /**
   * @param list<array<string, mixed>> $operationalItems
   * @param list<array<string, mixed>> $readinessItems
   *
   * @return array{all_ready: bool, issue_count: int, groups: list<array<string, mixed>>, detail_items: list<array<string, mixed>>}
   */
  private function buildReadinessSummary(array $operationalItems, array $readinessItems): array {
    $opsByKey = [];
    foreach ($operationalItems as $item) {
      $key = (string) ($item['key'] ?? '');
      if ($key !== '') {
        $opsByKey[$key] = $item;
      }
    }

    $groups = [
      $this->buildReadinessGroup(
        'public_visibility',
        (string) $this->t('Public visibility'),
        array_filter([$opsByKey['visibility'] ?? NULL]),
      ),
      $this->buildReadinessGroup(
        'booking_setup',
        (string) $this->t('Booking setup'),
        array_filter([
          $opsByKey['booking'] ?? NULL,
          $opsByKey['paid_tickets'] ?? NULL,
        ]),
      ),
      $this->buildReadinessGroup(
        'day_of_event',
        (string) $this->t('Day-of event readiness'),
        array_filter([
          $opsByKey['day_of_event'] ?? NULL,
          $opsByKey['attendees'] ?? NULL,
        ]),
      ),
      $this->buildReadinessGroup(
        'event_content',
        (string) $this->t('Event content'),
        $this->mapReadinessItemsToSummary($readinessItems),
      ),
    ];

    $issueCount = 0;
    foreach ($groups as $group) {
      if (($group['status'] ?? 'ready') !== 'ready') {
        $issueCount++;
      }
    }

    return [
      'all_ready' => $issueCount === 0,
      'issue_count' => $issueCount,
      'groups' => $groups,
      'detail_items' => $readinessItems,
    ];
  }

  /**
   * @param list<array<string, mixed>> $items
   *
   * @return array{key: string, label: string, status: string, expand: bool, items: list<array<string, mixed>>}
   */
  private function buildReadinessGroup(string $key, string $label, array $items): array {
    $normalized = [];
    $hasIssue = FALSE;
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      $severity = (string) ($item['severity'] ?? 'info');
      $complete = !empty($item['complete']);
      if ($severity !== 'success' && $severity !== 'neutral' && !$complete) {
        $hasIssue = TRUE;
      }
      $normalized[] = [
        'label' => (string) ($item['label'] ?? $item['stage'] ?? ''),
        'message' => (string) ($item['message'] ?? ''),
        'severity' => $severity,
        'complete' => $complete,
      ];
    }

    return [
      'key' => $key,
      'label' => $label,
      'status' => $hasIssue ? 'attention' : 'ready',
      'expand' => $hasIssue,
      'items' => $normalized,
    ];
  }

  /**
   * @param list<array<string, mixed>> $readinessItems
   *
   * @return list<array<string, mixed>>
   */
  private function mapReadinessItemsToSummary(array $readinessItems): array {
    $mapped = [];
    foreach ($readinessItems as $item) {
      $mapped[] = [
        'label' => (string) ($item['label'] ?? ''),
        'message' => !empty($item['complete'])
          ? (string) $this->t('Complete')
          : (string) $this->t('Needs attention'),
        'severity' => !empty($item['complete']) ? 'success' : (string) ($item['severity'] ?? 'warning'),
        'complete' => !empty($item['complete']),
      ];
    }
    return $mapped;
  }

  /**
   * Resolves the add-on orders shortcut from workspace tabs.
   *
   * @param list<array<string, mixed>> $tabs
   */
  private function addonOrdersUrlFromWorkspaceTabs(array $tabs): ?Url {
    foreach ($tabs as $tab) {
      if (($tab['key'] ?? '') !== 'addon_orders') {
        continue;
      }
      if (empty($tab['available'])) {
        return NULL;
      }
      $url = $tab['url'] ?? NULL;
      return $url instanceof Url ? $url : NULL;
    }
    return NULL;
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
   */
  private function routeUrlIfAccessible(string $routeName, array $parameters, AccountInterface $account): ?Url {
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
      return Url::fromRoute($routeName, $parameters);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_vendor')->warning('Workspace URL build failed for @route: @message', [
        '@route' => $routeName,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

}
