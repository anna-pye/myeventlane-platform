<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_attendees\Service;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds the VX2 One Attendee Workspace view model (B7).
 *
 * Organisers see Attendees only — paid, RSVP, waitlist, and door actions in
 * one guest list with search, filters, cards, and entry points for Message,
 * Export, Refund, and Door Mode.
 */
final class EventAttendeeWorkspaceBuilder {

  use StringTranslationTrait;

  private const CHECKIN_CSRF_ID = 'mel_vendor_attendee_checkin';

  /**
   * Card layout below this count; dense table at/above for large events.
   */
  public const DENSE_TABLE_THRESHOLD = 100;

  public function __construct(
    private readonly AttendanceManagerInterface $attendanceManager,
    private readonly VendorAttendeePresentationServiceInterface $vendorPresentation,
    private readonly MelAttendeeExportBuilder $exportBuilder,
    private readonly CsrfTokenGenerator $csrfToken,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly RouteProviderInterface $routeProvider,
    private readonly RequestStack $requestStack,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerChannelInterface $logger,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Builds the workspace render array for an event.
   *
   * @return array<string, mixed>
   *   Theme render array for the Attendees workspace.
   */
  public function build(NodeInterface $event): array {
    $eventId = (int) $event->id();
    $attendees = $this->attendanceManager->getAttendeesForEvent($eventId);
    $availability = $this->attendanceManager->getAvailability($event);

    $grouped = [
      'ticket' => 0,
      'rsvp' => 0,
      'manual' => 0,
      'waitlist' => 0,
    ];
    $checkedIn = 0;
    $ticketTypes = [];
    $rows = [];

    foreach ($attendees as $attendee) {
      if (!$attendee instanceof EventAttendee) {
        continue;
      }
      $source = $attendee->getSource();
      if (isset($grouped[$source])) {
        $grouped[$source]++;
      }
      if ($attendee->getStatus() === EventAttendee::STATUS_WAITLIST) {
        $grouped['waitlist']++;
      }
      if ($attendee->isCheckedIn()) {
        $checkedIn++;
      }

      $row = $this->buildRow($attendee, $event);
      $ticketLabel = trim((string) ($row['ticket_type'] ?? ''));
      if ($ticketLabel !== '' && $ticketLabel !== '—') {
        $ticketTypes[$ticketLabel] = $ticketLabel;
      }
      $rows[] = $row;
    }

    ksort($ticketTypes, SORT_NATURAL | SORT_FLAG_CASE);

    $request = $this->requestStack->getCurrentRequest();
    $initialFilter = $request ? (string) $request->query->get('filter', 'all') : 'all';
    $allowedFilters = [
      'all', 'ticket', 'rsvp', 'waitlist', 'checked_in', 'not_checked_in',
      'refunded', 'cancelled',
    ];
    if (!in_array($initialFilter, $allowedFilters, TRUE)) {
      $initialFilter = 'all';
    }

    $total = count($rows);
    $layout = $total >= self::DENSE_TABLE_THRESHOLD ? 'dense' : 'cards';

    $initialMatchCount = 0;
    foreach ($rows as &$row) {
      $keys = $row['filter_keys'] ?? [];
      $matches = $initialFilter === 'all' || in_array($initialFilter, $keys, TRUE);
      $row['matches_initial_filter'] = $matches;
      if ($matches) {
        $initialMatchCount++;
      }
    }
    unset($row);

    $this->recordAnalyticsHook('attendee_viewed', $event, [
      'attendee_count' => $total,
      'layout' => $layout,
      'filter' => $initialFilter,
      'filter_match_count' => $initialMatchCount,
    ]);

    $capacity = $availability['capacity'] ?? 0;
    $summary = [
      'total' => $total,
      'checked_in' => $checkedIn,
      'ticket' => $grouped['ticket'],
      'rsvp' => $grouped['rsvp'],
      'manual' => $grouped['manual'],
      'waitlist' => $grouped['waitlist'],
      'capacity' => $capacity > 0 ? $capacity : 'Unlimited',
      'remaining' => $availability['remaining'] ?? NULL,
    ];

    $publicUrl = Url::fromRoute('entity.node.canonical', ['node' => $eventId])->toString();

    return [
      '#theme' => 'mel_event_studio_attendees_workspace',
      '#event' => $event,
      '#summary' => $summary,
      '#attendees' => $rows,
      '#filters' => $this->buildFilterChips(),
      '#ticket_types' => array_values($ticketTypes),
      '#initial_filter' => $initialFilter,
      '#initial_match_count' => $initialMatchCount,
      '#empty_filter' => $this->buildEmptyFilterState($initialFilter, $initialMatchCount),
      '#layout' => $layout,
      '#dense_threshold' => self::DENSE_TABLE_THRESHOLD,
      '#actions' => $this->buildWorkspaceActions($event),
      '#checkin_csrf' => $this->csrfToken->get(self::CHECKIN_CSRF_ID),
      '#public_event_url' => $publicUrl,
      '#empty' => $this->buildEmptyState($event, $publicUrl),
      '#attached' => [
        'library' => [
          'myeventlane_event_studio/mel_event_studio_attendees_app',
        ],
        'drupalSettings' => [
          'melAttendeesWorkspace' => [
            'eventId' => $eventId,
            'initialFilter' => $initialFilter,
            'denseThreshold' => self::DENSE_TABLE_THRESHOLD,
            'instrumentation' => [
              'attendee_filtered' => TRUE,
              'attendee_exported' => TRUE,
              'door_mode_opened' => TRUE,
              'message_attendees_clicked' => TRUE,
              'attendee_checked_in' => TRUE,
            ],
          ],
        ],
      ],
      '#cache' => [
        'tags' => array_merge($event->getCacheTags(), ['event_attendee_list:' . $eventId]),
        'contexts' => ['user', 'url.query_args:filter'],
      ],
    ];
  }

  /**
   * Builds one attendee card row.
   *
   * @return array<string, mixed>
   *   Card view-model for Twig.
   */
  private function buildRow(EventAttendee $attendee, NodeInterface $event): array {
    $vm = $this->vendorPresentation->buildVendorRowFromEventAttendee($attendee);
    $orderItem = NULL;
    if ($attendee->hasField('order_item') && !$attendee->get('order_item')->isEmpty()) {
      $entity = $attendee->get('order_item')->entity;
      if ($entity && (!method_exists($entity, 'bundle') || $entity->bundle() !== 'boost')) {
        $orderItem = $entity;
      }
    }
    $operational = $this->exportBuilder->resolveOperationalState($attendee, $orderItem);
    $orderLink = $this->buildOrderLink($attendee, $event);
    $refundUrl = $this->buildRefundUrl($attendee, $event);
    $source = (string) ($vm['source'] ?? $attendee->getSource());
    $status = $attendee->getStatus();
    $ticketType = (string) ($vm['ticket_type'] ?? '');
    if ($ticketType === '' && $source === EventAttendee::SOURCE_RSVP) {
      $ticketType = (string) $this->t('RSVP');
    }
    if ($status === EventAttendee::STATUS_WAITLIST && $ticketType === '') {
      $ticketType = (string) $this->t('Waitlist');
    }

    $bookingLabel = match ($operational) {
      MelAttendeeExportBuilder::STATE_CHECKED_IN => (string) $this->t('Checked in'),
      MelAttendeeExportBuilder::STATE_CANCELLED => (string) $this->t('Cancelled'),
      MelAttendeeExportBuilder::STATE_REFUNDED => (string) $this->t('Refunded'),
      MelAttendeeExportBuilder::STATE_PENDING => $status === EventAttendee::STATUS_WAITLIST
        ? (string) $this->t('Waitlist')
        : (string) $this->t('Pending'),
      MelAttendeeExportBuilder::STATE_REGISTERED => (string) $this->t('Confirmed'),
      default => (string) $this->t('Needs attention'),
    };

    $sourceLabel = match ($source) {
      EventAttendee::SOURCE_RSVP => (string) $this->t('RSVP'),
      EventAttendee::SOURCE_TICKET => (string) $this->t('Ticket'),
      EventAttendee::SOURCE_MANUAL => (string) $this->t('Manual'),
      default => ucfirst($source),
    };

    $checkInUrl = NULL;
    // MelAttendeeCheckinManager only transitions MelAttendeeAttendanceState::Registered
    // (export STATE_REGISTERED). Do not expose Check in for refunded / waitlist / pending.
    if ($operational === MelAttendeeExportBuilder::STATE_REGISTERED) {
      try {
        $checkInUrl = Url::fromRoute('myeventlane_event_attendees.vendor_checkin', [
          'event_attendee' => $attendee->id(),
        ])->toString();
      }
      catch (\Throwable) {
        $checkInUrl = NULL;
      }
    }

    $messageUrl = $this->buildMessageUrl($event);
    $viewUrl = $orderLink['url'] ?? NULL;
    if ($viewUrl === NULL && !empty($vm['email'])) {
      $viewUrl = 'mailto:' . rawurlencode((string) $vm['email']);
    }

    return [
      'id' => (int) $attendee->id(),
      'name' => (string) ($vm['full_name'] ?? $attendee->label() ?? ''),
      'email' => (string) ($vm['email'] ?? ''),
      'phone' => (string) ($vm['phone'] ?? ''),
      'source' => $source,
      'source_label' => $sourceLabel,
      'ticket_type' => $ticketType !== '' ? $ticketType : '—',
      'booking_status' => $bookingLabel,
      'booking_state' => $operational,
      'status' => $status,
      'checked_in' => $attendee->isCheckedIn(),
      'check_in_label' => $attendee->isCheckedIn()
        ? (string) $this->t('Checked in')
        : (string) $this->t('Not checked in'),
      'order_link' => $orderLink,
      'order_reference' => $orderLink['label'] ?? '',
      'ticket_code' => (string) ($vm['ticket_code'] ?? ''),
      'custom_answers' => $vm['custom_answers'] ?? [],
      'custom_answers_display' => $vm['custom_answers_display'] ?? '',
      'actions' => [
        'view_url' => $viewUrl,
        'message_url' => $messageUrl,
        'refund_url' => $refundUrl,
        'checkin_url' => $checkInUrl,
      ],
      'filter_keys' => $this->buildFilterKeys($attendee, $source, $operational),
    ];
  }

  /**
   * Builds filter token keys for a guest row.
   *
   * @return list<string>
   *   Filter keys applied by the Attendees app JS.
   */
  private function buildFilterKeys(EventAttendee $attendee, string $source, string $operational): array {
    $keys = ['all', $source];
    if ($attendee->getStatus() === EventAttendee::STATUS_WAITLIST) {
      $keys[] = 'waitlist';
    }
    if ($attendee->isCheckedIn()) {
      $keys[] = 'checked_in';
    }
    else {
      $keys[] = 'not_checked_in';
    }
    if ($operational === MelAttendeeExportBuilder::STATE_REFUNDED) {
      $keys[] = 'refunded';
    }
    if ($operational === MelAttendeeExportBuilder::STATE_CANCELLED
      || $attendee->getStatus() === EventAttendee::STATUS_CANCELLED) {
      $keys[] = 'cancelled';
    }
    return array_values(array_unique($keys));
  }

  /**
   * Builds filter chip definitions for the toolbar.
   *
   * @return list<array{id: string, label: string}>
   *   Filter chips shown above the guest list.
   */
  private function buildFilterChips(): array {
    return [
      ['id' => 'all', 'label' => (string) $this->t('All')],
      ['id' => 'ticket', 'label' => (string) $this->t('Ticket')],
      ['id' => 'rsvp', 'label' => (string) $this->t('RSVP')],
      ['id' => 'waitlist', 'label' => (string) $this->t('Waitlist')],
      ['id' => 'checked_in', 'label' => (string) $this->t('Checked in')],
      ['id' => 'not_checked_in', 'label' => (string) $this->t('Not checked in')],
      ['id' => 'refunded', 'label' => (string) $this->t('Refunded')],
      ['id' => 'cancelled', 'label' => (string) $this->t('Cancelled')],
    ];
  }

  /**
   * Builds primary workspace actions (Door Mode, Message, Export).
   *
   * @return list<array{label: string, url: string, class: string, analytics: string}>
   *   Header action buttons.
   */
  private function buildWorkspaceActions(NodeInterface $event): array {
    $eventId = (int) $event->id();
    $actions = [];

    $doorUrl = $this->safeRouteUrl('myeventlane_event_attendees.vendor_operations_door', ['node' => $eventId]);
    if ($doorUrl === NULL) {
      $doorUrl = $this->safeRouteUrl('myeventlane_event_attendees.vendor_operations', ['node' => $eventId]);
    }
    if ($doorUrl !== NULL) {
      $actions[] = [
        'label' => (string) $this->t('Door Mode'),
        'url' => $doorUrl,
        'class' => 'mel-btn--primary',
        'analytics' => 'door_mode_opened',
      ];
    }

    $messageUrl = $this->buildMessageUrl($event);
    if ($messageUrl !== NULL) {
      $actions[] = [
        'label' => (string) $this->t('Message attendees'),
        'url' => $messageUrl,
        'class' => 'mel-btn--secondary',
        'analytics' => 'message_attendees_clicked',
      ];
    }

    $exportUrl = $this->safeRouteUrl('myeventlane_event_attendees.vendor_export', ['node' => $eventId]);
    if ($exportUrl !== NULL) {
      $actions[] = [
        'label' => (string) $this->t('Export attendees'),
        'url' => $exportUrl,
        'class' => 'mel-btn--secondary',
        'analytics' => 'attendee_exported',
      ];
    }

    return $actions;
  }

  /**
   * Builds empty-state copy when the guest list has no attendees.
   *
   * @return array{title: string, body: string, prompt: string, cta_label: string, cta_url: string}|null
   *   Empty-state payload for Twig.
   */
  private function buildEmptyState(NodeInterface $event, string $publicUrl): ?array {
    return [
      'title' => (string) $this->t('No attendees yet'),
      'body' => (string) $this->t('Publish your event and share it to receive your first booking.'),
      'prompt' => (string) $this->t('Guests who buy a ticket or RSVP will appear here in one guest list.'),
      'cta_label' => (string) $this->t('View event page'),
      'cta_url' => $publicUrl,
    ];
  }

  /**
   * Builds empty-filter copy for an initial ?filter= that matches zero guests.
   *
   * Server-rendered so legacy redirects and no-JS see guidance without waiting
   * for the Attendees app behaviour.
   *
   * @return array{show: bool, title: string, body: string}
   *   Empty-filter panel payload for Twig.
   */
  private function buildEmptyFilterState(string $initialFilter, int $initialMatchCount): array {
    $show = $initialFilter !== 'all' && $initialMatchCount === 0;
    if ($initialFilter === 'checked_in') {
      return [
        'show' => $show,
        'title' => (string) $this->t('No checked-in guests'),
        'body' => (string) $this->t('Door Mode will update this list as guests arrive.'),
      ];
    }
    return [
      'show' => $show,
      'title' => (string) $this->t('No matching attendees'),
      'body' => (string) $this->t('Try another search or clear your filters.'),
    ];
  }

  /**
   * Resolves the Message attendees entry URL for this event.
   */
  private function buildMessageUrl(NodeInterface $event): ?string {
    $eventId = (int) $event->id();
    $url = $this->safeRouteUrl('myeventlane_vendor.console.event_promotion', ['event' => $eventId]);
    if ($url !== NULL) {
      return $url;
    }
    return $this->safeRouteUrl('myeventlane_event_studio.workspace_messaging', ['node' => $eventId]);
  }

  /**
   * Builds an order reference link when the guest came from a ticket purchase.
   *
   * @return array{url: string, label: string}|null
   *   Order link payload, or NULL when not applicable.
   */
  private function buildOrderLink(EventAttendee $attendee, NodeInterface $event): ?array {
    if ($attendee->getSource() !== EventAttendee::SOURCE_TICKET) {
      return NULL;
    }
    if (!$attendee->hasField('order_item') || $attendee->get('order_item')->isEmpty()) {
      return NULL;
    }
    $orderItem = $attendee->get('order_item')->entity;
    if (!$orderItem || (method_exists($orderItem, 'bundle') && $orderItem->bundle() === 'boost')) {
      return NULL;
    }
    try {
      $order = $orderItem->getOrder();
    }
    catch (\Throwable) {
      return NULL;
    }
    if (!$order) {
      return NULL;
    }
    $url = $this->safeRouteUrl('myeventlane_vendor.console.event_order_view', [
      'event' => $event->id(),
      'order' => $order->id(),
    ]);
    if ($url === NULL) {
      return NULL;
    }
    $label = '#' . ($order->getOrderNumber() ?: $order->id());
    return ['url' => $url, 'label' => $label];
  }

  /**
   * Builds a refund entry URL when the guest has a refundable order.
   */
  private function buildRefundUrl(EventAttendee $attendee, NodeInterface $event): ?string {
    if (!$this->moduleHandler->moduleExists('myeventlane_refunds')) {
      return NULL;
    }
    if ($attendee->getSource() !== EventAttendee::SOURCE_TICKET) {
      return NULL;
    }
    if (!$attendee->hasField('order_item') || $attendee->get('order_item')->isEmpty()) {
      return NULL;
    }
    $orderItem = $attendee->get('order_item')->entity;
    if (!$orderItem) {
      return NULL;
    }
    $operational = $this->exportBuilder->resolveOperationalState($attendee, $orderItem);
    if (in_array($operational, [
      MelAttendeeExportBuilder::STATE_REFUNDED,
      MelAttendeeExportBuilder::STATE_CANCELLED,
    ], TRUE)) {
      return NULL;
    }
    try {
      $order = $orderItem->getOrder();
    }
    catch (\Throwable) {
      return NULL;
    }
    if (!$order) {
      return NULL;
    }
    return $this->safeRouteUrl('myeventlane_refunds.vendor_refund', [
      'commerce_order' => $order->id(),
    ]);
  }

  /**
   * Returns a route URL when the route exists; otherwise NULL.
   */
  private function safeRouteUrl(string $routeName, array $params = []): ?string {
    try {
      $this->routeProvider->getRouteByName($routeName);
      return Url::fromRoute($routeName, $params)->toString();
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Records instrumentation hooks until a full analytics pipeline exists.
   *
   * @param string $eventName
   *   Analytics event name (for example attendee_viewed).
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param array<string, mixed> $context
   *   Extra logger context.
   */
  private function recordAnalyticsHook(string $eventName, NodeInterface $event, array $context = []): void {
    $this->logger->info('MEL attendee analytics hook @event for event @nid uid @uid.', [
      '@event' => $eventName,
      '@nid' => (string) $event->id(),
      '@uid' => (string) $this->currentUser->id(),
      'mel_analytics_event' => $eventName,
      'event_id' => (int) $event->id(),
      'uid' => (int) $this->currentUser->id(),
    ] + $context);
  }

}
