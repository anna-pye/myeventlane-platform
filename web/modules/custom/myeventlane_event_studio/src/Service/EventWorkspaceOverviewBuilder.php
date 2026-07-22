<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_commerce\Service\OrderItemClassifier;
use Drupal\myeventlane_event_attendees\Service\AttendanceWaitlistManager;
use Drupal\myeventlane_event_studio\DTO\EventReadinessResult;
use Drupal\myeventlane_vendor\Service\BoostStatusService;
use Drupal\myeventlane_vendor\Service\PaidPublishStripeGate;
use Drupal\myeventlane_vendor\Service\TicketSalesService;
use Drupal\myeventlane_vendor\Service\VendorEventWorkspaceViewModelBuilder;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the Event Workspace Home (organiser home for one event).
 *
 * Compositional dashboard — not a status dump. Confirmed metrics only.
 */
final class EventWorkspaceOverviewBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly VendorEventWorkspaceViewModelBuilder $workspaceViewModel,
    private readonly PaidPublishStripeGate $stripeGate,
    private readonly EventReadinessFacade $readinessFacade,
    private readonly TicketSalesService $ticketSalesService,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly OrderItemClassifier $orderItemClassifier,
    private readonly LoggerInterface $logger,
    TranslationInterface $stringTranslation,
    private readonly ?BoostStatusService $boostStatusService = NULL,
    private readonly ?AttendanceWaitlistManager $waitlistManager = NULL,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Builds the Home render array for one event.
   *
   * @return array<string, mixed>
   *   Themeable render array.
   */
  public function build(NodeInterface $event, AccountInterface $account): array {
    try {
      $workspace = $this->workspaceViewModel->build($event, $account);
    }
    catch (\Throwable $e) {
      $this->logger->error('Event Workspace home model failed for event @nid: @message', [
        '@nid' => (string) $event->id(),
        '@message' => $e->getMessage(),
      ]);
      $workspace = [];
    }

    $bundle = $this->readinessFacade->evaluate($event, $account);
    $readiness = $bundle['publish'];
    $recommended = is_array($bundle['recommended'] ?? NULL) ? $bundle['recommended'] : [];
    $nid = (int) $event->id();
    $published = $event->isPublished();

    $next = is_array($workspace['next_action'] ?? NULL) ? $workspace['next_action'] : [];
    $eventMeta = is_array($workspace['event'] ?? NULL) ? $workspace['event'] : [];
    $humanChecklist = $this->buildHumanChecklist(
      $readiness->completed,
      $readiness->errors,
      $readiness->warnings,
      $recommended,
    );

    $salesSummary = $this->safeSalesSummary($event);
    $stripe = $this->buildStripeHealth($account, $event, $eventMeta);
    $remainingErrors = count($readiness->errors);
    $completedCount = count($readiness->completed);
    // Match expandable checklist length (completed + blockers + warnings + ideas).
    $totalChecklist = count($humanChecklist);
    if ($totalChecklist < 1) {
      $totalChecklist = 1;
    }

    $nextRecommended = $this->resolveNextRecommendedAction($next, $readiness, $published, $nid, $stripe);
    $celebration = $this->buildCelebrationHint($event, $readiness, $published);
    if (!empty($celebration['show']) && $nextRecommended['message'] === '') {
      $nextRecommended['message'] = (string) ($celebration['message'] ?? '');
    }
    elseif (!empty($celebration['show']) && is_string($nextRecommended['message'])) {
      // Soft celebration stays in Next Action message when early draft.
      $nextRecommended['message'] = trim($nextRecommended['message'] . ' ' . (string) ($celebration['message'] ?? ''));
    }

    $statusLabel = (string) ($eventMeta['status_label'] ?? ($published ? $this->t('Live') : $this->t('Draft')));
    $statusKey = (string) ($eventMeta['status'] ?? ($published ? 'live' : 'draft'));

    return [
      '#theme' => 'mel_event_studio_overview',
      '#event_ready' => $this->buildEventReady(
        $readiness,
        $published,
        $statusLabel,
        $statusKey,
        $completedCount,
        $totalChecklist,
        $remainingErrors,
        $event,
        $stripe,
        $eventMeta,
      ),
      '#next_action' => $nextRecommended,
      '#readiness' => [
        'ready' => $readiness->ready,
        'score' => (int) ($workspace['readiness']['score'] ?? 0),
        'items' => $humanChecklist,
        'complete_count' => $completedCount,
        'total_count' => $totalChecklist,
        'headline' => $this->readinessHeadline($readiness->ready, $published, $remainingErrors),
        'explanation' => $this->readinessExplanation($readiness),
      ],
      '#tickets' => $this->buildTicketsCard($event, $account, $nid, $salesSummary, $workspace),
      '#attendees' => $this->buildAttendeesCard($account, $nid, $workspace, $salesSummary),
      '#sales' => $this->buildSalesCard($salesSummary, $nid),
      '#marketing' => $this->buildMarketingCard($event, $account, $nid),
      '#boost' => $this->buildBoostCard($event, $nid),
      '#analytics' => $this->buildAnalyticsCard($workspace, $salesSummary, $nid),
      '#activity' => $this->buildActivityFeed($event, $salesSummary),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function safeSalesSummary(NodeInterface $event): array {
    try {
      return $this->ticketSalesService->getSalesSummary($event);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Home sales summary failed for event @nid: @message', [
        '@nid' => (string) $event->id(),
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Event Ready card — Q2 health (not “Event Status”).
   *
   * @param array<string, mixed> $eventMeta
   *   Workspace event metadata (same source as Stripe health booking type).
   *
   * @return array<string, mixed>
   */
  private function buildEventReady(
    EventReadinessResult $readiness,
    bool $published,
    string $statusLabel,
    string $statusKey,
    int $completedCount,
    int $totalChecklist,
    int $remainingErrors,
    NodeInterface $event,
    array $stripe,
    array $eventMeta = [],
  ): array {
    $tone = 'success';
    $headline = (string) $this->t('Ready to publish');
    $detail = (string) $this->t('Everything looks good.');
    if ($published && $readiness->ready) {
      $headline = (string) $this->t('Live and healthy');
      $detail = (string) $this->t('Your event is published and ready for guests.');
    }
    elseif (!$readiness->ready) {
      $tone = 'attention';
      $headline = $remainingErrors === 1
        ? (string) $this->t('Almost ready')
        : (string) $this->t('Needs a few things');
      $detail = $remainingErrors === 1
        ? (string) $this->t('One thing left before publishing.')
        : (string) $this->t('@count items left before publishing.', ['@count' => $remainingErrors]);
    }

    if (($stripe['tone'] ?? '') === 'attention' && in_array($this->resolveEventBookingType($event, $eventMeta), ['paid', 'both'], TRUE)) {
      $tone = 'attention';
      if ($readiness->ready) {
        $headline = (string) $this->t('Almost ready');
        $detail = (string) ($stripe['detail'] ?? $detail);
      }
    }

    $changed = (int) $event->getChangedTime();
    $updated = $changed > 0
      ? $this->dateFormatter->formatTimeDiffSince($changed, ['granularity' => 1])
      : '';

    return [
      'headline' => $headline,
      'detail' => $detail,
      'tone' => $tone,
      'status_label' => $statusLabel,
      'status_key' => $statusKey,
      'complete_count' => $completedCount,
      'total_count' => $totalChecklist,
      'updated_label' => $updated !== ''
        ? (string) $this->t('Updated @when ago', ['@when' => $updated])
        : '',
      'checklist_label' => (string) $this->t('View checklist'),
    ];
  }

  /**
   * @param array<string, mixed> $salesSummary
   * @param array<string, mixed> $workspace
   *
   * @return array<string, mixed>
   */
  private function buildTicketsCard(
    NodeInterface $event,
    AccountInterface $account,
    int $nid,
    array $salesSummary,
    array $workspace,
  ): array {
    $types = 0;
    if ($event->hasField('field_ticket_types') && !$event->get('field_ticket_types')->isEmpty()) {
      $types = count($event->get('field_ticket_types')->getValue());
    }

    $sold = (int) ($salesSummary['tickets_sold'] ?? 0);
    $available = $salesSummary['tickets_available'] ?? NULL;
    // TicketSalesService returns 0 when capacity is unset/unknown — omit remaining
    // rather than implying sell-out (metrics policy).
    $remaining = NULL;
    if ((is_int($available) || (is_string($available) && ctype_digit($available))) && (int) $available > 0) {
      $remaining = max(0, (int) $available - $sold);
    }

    $metrics = [];
    if ($types > 0) {
      $metrics[] = [
        'label' => $types === 1
          ? (string) $this->t('1 ticket type')
          : (string) $this->t('@count ticket types', ['@count' => $types]),
        'value' => (string) $types,
      ];
    }
    $metrics[] = [
      'label' => (string) $this->t('sold'),
      'value' => (string) $sold,
    ];
    if ($remaining !== NULL) {
      $metrics[] = [
        'label' => (string) $this->t('remaining'),
        'value' => (string) $remaining,
      ];
    }
    elseif (is_string($available) && $available !== '' && !ctype_digit($available)) {
      $metrics[] = [
        'label' => (string) $available,
        'value' => '',
      ];
    }

    $url = $this->safeUrl('myeventlane_event_studio.workspace_tickets', ['node' => $nid]);
    return [
      'title' => (string) $this->t('Tickets'),
      'metrics' => $metrics,
      'empty' => $types === 0 && $sold === 0,
      'empty_message' => (string) $this->t('Add tickets so people can register.'),
      'action_label' => (string) $this->t('Manage tickets'),
      'url' => $url,
    ];
  }

  /**
   * @param array<string, mixed> $workspace
   * @param array<string, mixed> $salesSummary
   *
   * @return array<string, mixed>
   */
  private function buildAttendeesCard(AccountInterface $account, int $nid, array $workspace, array $salesSummary): array {
    $salesMetrics = is_array($workspace['sales_snapshot']['metrics'] ?? NULL)
      ? $workspace['sales_snapshot']['metrics']
      : [];
    $booked = (int) ($salesSummary['tickets_sold'] ?? 0);
    $rsvps = 0;
    $checkins = 0;
    foreach ($salesMetrics as $metric) {
      if (!is_array($metric)) {
        continue;
      }
      $key = (string) ($metric['key'] ?? '');
      $label = strtolower((string) ($metric['label'] ?? ''));
      $value = (int) ($metric['value'] ?? 0);
      if ($key === 'rsvps' || str_contains($label, 'rsvp')) {
        $rsvps = $value;
      }
      if ($key === 'checkins' || str_contains($label, 'check')) {
        $checkins = $value;
      }
    }
    if ($booked === 0 && $rsvps > 0) {
      $booked = $rsvps;
    }

    $waitlist = NULL;
    if ($this->waitlistManager !== NULL) {
      try {
        $waitlist = $this->waitlistManager->getWaitlistCount($nid);
      }
      catch (\Throwable $e) {
        $this->logger->warning('Home waitlist count failed for event @nid: @message', [
          '@nid' => (string) $nid,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    $metrics = [
      ['label' => (string) $this->t('booked'), 'value' => (string) $booked],
      ['label' => (string) $this->t('checked in'), 'value' => (string) $checkins],
    ];
    if ($waitlist !== NULL) {
      $metrics[] = ['label' => (string) $this->t('waitlist'), 'value' => (string) $waitlist];
    }

    $hasWaitlist = is_int($waitlist) && $waitlist > 0;

    return [
      'title' => (string) $this->t('Attendees'),
      'metrics' => $metrics,
      'empty' => $booked === 0 && $checkins === 0 && !$hasWaitlist,
      'empty_message' => (string) $this->t('Guests will appear here after your first booking.'),
      'action_label' => (string) $this->t('Manage attendees'),
      'url' => $this->safeUrl('myeventlane_event_studio.workspace_attendees', ['node' => $nid]),
    ];
  }

  /**
   * @param array<string, mixed> $salesSummary
   *
   * @return array<string, mixed>
   */
  private function buildSalesCard(array $salesSummary, int $nid): array {
    $gross = trim((string) ($salesSummary['gross'] ?? ''));
    $orders = (int) ($salesSummary['orders_count'] ?? 0);
    $metrics = [];
    if ($gross !== '') {
      $metrics[] = ['label' => (string) $this->t('gross'), 'value' => $gross, 'primary' => TRUE];
    }
    $metrics[] = [
      'label' => (string) $this->t('bookings'),
      'value' => (string) $orders,
    ];

    return [
      'title' => (string) $this->t('Sales'),
      'metrics' => $metrics,
      'empty' => $orders === 0 && ($gross === '' || $gross === '$0.00' || $gross === '$0'),
      'empty_message' => (string) $this->t('Sales will appear after your first booking.'),
      'action_label' => (string) $this->t('View orders'),
      'url' => $this->safeUrl('myeventlane_event_studio.workspace_orders', ['node' => $nid])
        ?? $this->safeUrl('myeventlane_vendor.console.event_orders', ['event' => $nid]),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildMarketingCard(NodeInterface $event, AccountInterface $account, int $nid): array {
    $boostActive = FALSE;
    if ($this->boostStatusService !== NULL) {
      try {
        $payload = $this->boostStatusService->getVisibilityPayload($event);
        $boostActive = !empty($payload['active']);
      }
      catch (\Throwable) {
        $boostActive = FALSE;
      }
    }

    $featured = $event->hasField('field_promoted')
      && !$event->get('field_promoted')->isEmpty()
      && (bool) $event->get('field_promoted')->value;

    $lines = [];
    $lines[] = $boostActive
      ? (string) $this->t('Boost active')
      : (string) $this->t('Boost not running');
    $lines[] = $featured
      ? (string) $this->t('Homepage featured')
      : (string) $this->t('Not homepage featured');
    if (!$event->isPublished()) {
      $lines = [(string) $this->t('Publish to share and promote.')];
    }

    $url = !$event->isPublished()
      ? $this->safeUrl('myeventlane_event_studio.workspace_publishing', ['node' => $nid])
      : ($this->safeUrl('myeventlane_boost.vendor_event_boost', ['event' => $nid])
        ?? $this->safeUrl('myeventlane_event_studio.workspace_marketing', ['node' => $nid]));

    return [
      'title' => (string) $this->t('Marketing'),
      'lines' => $lines,
      'action_label' => (string) $this->t('Promote'),
      'url' => $url,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildBoostCard(NodeInterface $event, int $nid): array {
    $active = FALSE;
    $detail = (string) $this->t('Reach more people on MyEventLane.');
    $days = NULL;
    if ($this->boostStatusService !== NULL) {
      try {
        $payload = $this->boostStatusService->getVisibilityPayload($event);
        $active = !empty($payload['active']);
        if ($active) {
          $days = $payload['days_remaining'] ?? NULL;
          $detail = is_numeric($days)
            ? (string) $this->formatPlural((int) $days, '1 day remaining', '@count days remaining')
            : (string) $this->t('Featured across MyEventLane');
        }
      }
      catch (\Throwable) {
        // Keep default detail.
      }
    }

    return [
      'title' => (string) $this->t('Boost'),
      'status' => $active ? (string) $this->t('Active') : (string) $this->t('Available'),
      'detail' => $detail,
      'active' => $active,
      'action_label' => $active
        ? (string) $this->t('View performance')
        : (string) $this->t('Boost event'),
      'url' => $this->safeUrl('myeventlane_boost.vendor_event_boost', ['event' => $nid])
        ?? $this->safeUrl('myeventlane_event_studio.workspace_marketing', ['node' => $nid]),
    ];
  }

  /**
   * @param array<string, mixed> $workspace
   * @param array<string, mixed> $salesSummary
   *
   * @return array<string, mixed>
   */
  private function buildAnalyticsCard(array $workspace, array $salesSummary, int $nid): array {
    $metrics = [];
    $conversion = $salesSummary['conversion'] ?? NULL;
    $available = $salesSummary['tickets_available'] ?? NULL;
    if (is_numeric($conversion) && (float) $conversion > 0 && (is_int($available) || (is_string($available) && ctype_digit((string) $available))) && (int) $available > 0) {
      $pct = round((float) $conversion * 100, 1);
      $metrics[] = [
        'label' => (string) $this->t('conversion'),
        'value' => $pct . '%',
        'primary' => TRUE,
      ];
    }

    $orders = (int) ($salesSummary['orders_count'] ?? 0);
    $sold = (int) ($salesSummary['tickets_sold'] ?? 0);
    if ($sold > 0) {
      $metrics[] = ['label' => (string) $this->t('bookings'), 'value' => (string) $sold];
    }
    elseif ($orders > 0) {
      $metrics[] = ['label' => (string) $this->t('orders'), 'value' => (string) $orders];
    }

    // Fall back to workspace metric snippets (confirmed only).
    if ($metrics === []) {
      $workspaceMetrics = is_array($workspace['metrics'] ?? NULL) ? $workspace['metrics'] : [];
      foreach ($workspaceMetrics as $metric) {
        if (!is_array($metric)) {
          continue;
        }
        $label = trim((string) ($metric['label'] ?? ''));
        $value = trim((string) ($metric['value'] ?? ''));
        if ($label !== '' && $value !== '' && $value !== '—') {
          $metrics[] = ['label' => strtolower($label), 'value' => $value];
        }
        if (count($metrics) >= 2) {
          break;
        }
      }
    }

    return [
      'title' => (string) $this->t('Analytics'),
      'metrics' => $metrics,
      'empty' => $metrics === [],
      'empty_message' => (string) $this->t('Publish your event to start tracking sales.'),
      'action_label' => (string) $this->t('View report'),
      'url' => $this->safeUrl('myeventlane_event_studio.workspace_analytics', ['node' => $nid]),
    ];
  }

  /**
   * Recent bookings / sales / orders activity (2B). No messages/system yet.
   *
   * @param array<string, mixed> $salesSummary
   *
   * @return array{items: list<array<string, mixed>>, empty: bool, empty_message: string}
   */
  private function buildActivityFeed(NodeInterface $event, array $salesSummary): array {
    $items = [];
    $eventId = (int) $event->id();
    try {
      if (!$this->entityTypeManager->hasDefinition('commerce_order_item')) {
        return $this->emptyActivity();
      }
      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
      // Distinct recent *orders*, not a fixed line-item window: multi-line carts
      // must not crowd out newer single-line bookings.
      $query = $orderItemStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_target_event', $eventId);
      $excludedTypes = $this->orderItemClassifier->getExcludedTypes();
      if ($excludedTypes !== []) {
        $query->condition('type', $excludedTypes, 'NOT IN');
      }
      $ids = $query->execute();
      if ($ids === []) {
        return $this->emptyActivity();
      }
      /** @var \Drupal\commerce_order\Entity\OrderItemInterface[] $orderItems */
      $orderItems = $orderItemStorage->loadMultiple($ids);
      // Aggregate per completed order using vendor-revenue-eligible lines only
      // (same rules as TicketSalesService — exclude Boost / donations).
      $orderAgg = [];
      foreach ($orderItems as $item) {
        if (!$this->orderItemClassifier->isVendorRevenueEligible($item)) {
          continue;
        }
        $order = $item->getOrder();
        if ($order === NULL) {
          continue;
        }
        if ($order->getState()->getId() !== 'completed') {
          continue;
        }
        $oid = (int) $order->id();
        if (!isset($orderAgg[$oid])) {
          $orderAgg[$oid] = [
            'order' => $order,
            'qty' => 0,
            'placed' => (int) $order->getPlacedTime(),
            'amount' => 0.0,
            'currency' => 'AUD',
          ];
        }
        $orderAgg[$oid]['qty'] += (int) round((float) $item->getQuantity());
        $lineTotal = $item->getTotalPrice();
        if ($lineTotal) {
          $orderAgg[$oid]['amount'] += (float) $lineTotal->getNumber();
          $currency = $lineTotal->getCurrencyCode();
          if (is_string($currency) && $currency !== '') {
            $orderAgg[$oid]['currency'] = $currency;
          }
        }
      }

      uasort($orderAgg, static function (array $a, array $b): int {
        $byPlaced = ((int) $b['placed']) <=> ((int) $a['placed']);
        if ($byPlaced !== 0) {
          return $byPlaced;
        }
        return ((int) $b['order']->id()) <=> ((int) $a['order']->id());
      });

      foreach (array_slice($orderAgg, 0, 8, TRUE) as $oid => $row) {
        /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
        $order = $row['order'];
        $amountNumber = (float) $row['amount'];
        $amount = $amountNumber > 0
          ? $this->formatActivityMoney($amountNumber, (string) $row['currency'])
          : '';
        $qty = (int) $row['qty'];
        $placed = (int) $row['placed'];
        $when = $placed > 0
          ? $this->dateFormatter->formatTimeDiffSince($placed, ['granularity' => 1])
          : '';
        $items[] = [
          'title' => (string) $this->t('Order @number', [
            '@number' => $order->getOrderNumber() ?: (string) $oid,
          ]),
          'detail' => trim(implode(' · ', array_filter([
            $qty > 0 ? (string) $this->formatPlural($qty, '1 ticket', '@count tickets') : '',
            $amount,
            $when !== '' ? (string) $this->t('@when ago', ['@when' => $when]) : '',
          ]))),
          'type' => 'order',
        ];
      }
    }
    catch (\Throwable $e) {
      $this->logger->warning('Home activity feed failed for event @nid: @message', [
        '@nid' => (string) $eventId,
        '@message' => $e->getMessage(),
      ]);
      return $this->emptyActivity();
    }

    if ($items === []) {
      // Confirmed aggregate fallback when item query is empty but sales exist.
      $orders = (int) ($salesSummary['orders_count'] ?? 0);
      $sold = (int) ($salesSummary['tickets_sold'] ?? 0);
      if ($orders > 0 || $sold > 0) {
        $items[] = [
          'title' => (string) $this->t('Sales activity'),
          'detail' => (string) $this->t('@orders bookings · @sold tickets sold', [
            '@orders' => $orders,
            '@sold' => $sold,
          ]),
          'type' => 'sales',
        ];
      }
    }

    return [
      'items' => $items,
      'empty' => $items === [],
      'empty_message' => (string) $this->t('Recent bookings and orders will show up here.'),
    ];
  }

  /**
   * @return array{items: list<array<string, mixed>>, empty: bool, empty_message: string}
   */
  private function emptyActivity(): array {
    return [
      'items' => [],
      'empty' => TRUE,
      'empty_message' => (string) $this->t('Recent bookings and orders will show up here.'),
    ];
  }

  /**
   * Formats event-scoped activity money (aligned with TicketSalesService / vendor orders).
   */
  private function formatActivityMoney(float $amount, string $currency): string {
    $currency = strtoupper($currency !== '' ? $currency : 'AUD');
    if ($currency === 'AUD') {
      return '$' . number_format($amount, 2);
    }
    return number_format($amount, 2) . ' ' . $currency;
  }

  /**
   * @param list<string> $completed
   * @param list<string> $errors
   * @param list<string> $warnings
   * @param list<string> $recommendations
   *
   * @return list<array{label: string, complete: bool, tone: string}>
   */
  private function buildHumanChecklist(array $completed, array $errors, array $warnings, array $recommendations): array {
    $items = [];
    foreach ($completed as $label) {
      $items[] = [
        'label' => $this->humaniseChecklistLabel((string) $label),
        'complete' => TRUE,
        'tone' => 'success',
      ];
    }
    foreach ($errors as $label) {
      $items[] = [
        'label' => $this->humaniseChecklistLabel((string) $label),
        'complete' => FALSE,
        'tone' => 'attention',
      ];
    }
    foreach ($warnings as $label) {
      $items[] = [
        'label' => $this->humaniseChecklistLabel((string) $label),
        'complete' => FALSE,
        'tone' => 'warning',
      ];
    }
    foreach ($recommendations as $label) {
      $items[] = [
        'label' => $this->humaniseChecklistLabel((string) $label),
        'complete' => FALSE,
        'tone' => 'idea',
      ];
    }
    return $items;
  }

  private function humaniseChecklistLabel(string $label): string {
    $trimmed = rtrim($label, '.');
    $map = [
      'Event title added' => (string) $this->t('Event title'),
      'Event dates complete' => (string) $this->t('Schedule'),
      'Booking mode selected' => (string) $this->t('Booking mode'),
      'Ticketing configured' => (string) $this->t('Tickets ready'),
      'Payment onboarding complete' => (string) $this->t('Payments connected'),
      'Vendor publish requirements complete' => (string) $this->t('Organiser profile ready'),
      'Branding image added' => (string) $this->t('Cover image'),
      'Capacity settings valid' => (string) $this->t('Capacity'),
      'External booking URL added' => (string) $this->t('External booking link'),
    ];
    return $map[$trimmed] ?? $trimmed;
  }

  private function readinessHeadline(bool $ready, bool $published, int $remaining): string {
    if ($published && $ready) {
      return (string) $this->t('Your event is live');
    }
    if ($ready) {
      return (string) $this->t('Ready to publish');
    }
    if ($remaining === 1) {
      return (string) $this->t('Almost ready');
    }
    return (string) $this->t('A few things left before publishing');
  }

  private function readinessExplanation(EventReadinessResult $readiness): string {
    if ($readiness->ready) {
      if ($readiness->warnings !== []) {
        return (string) $this->t('You can publish now. Optional improvements are in the checklist.');
      }
      return (string) $this->t('Everything needed to go live looks good.');
    }
    if (count($readiness->errors) === 1) {
      return (string) $this->t('One more thing before publishing: @reason', [
        '@reason' => $this->humaniseChecklistLabel($readiness->errors[0]),
      ]);
    }
    if ($readiness->errors !== []) {
      return (string) $this->t('Finish the checklist so guests can find and book your event.');
    }
    return (string) $this->t('Review the suggestions to make your event page stronger.');
  }

  /**
   * @param array<string, mixed> $eventMeta
   *
   * @return array{label: string, tone: string, detail: string, url: ?string}
   */
  private function buildStripeHealth(AccountInterface $account, NodeInterface $event, array $eventMeta): array {
    $eventId = (int) $event->id();
    $eventType = $this->resolveEventBookingType($event, $eventMeta);
    if (!in_array($eventType, ['paid', 'both'], TRUE)) {
      return [
        'label' => (string) $this->t('Payments'),
        'tone' => 'muted',
        'detail' => (string) $this->t('Payments apply when you sell paid tickets for this event.'),
        'url' => $this->safeUrl('myeventlane_vendor.payouts'),
      ];
    }

    try {
      $denial = $this->stripeGate->validatePaidPublishAllowed($account, $eventId);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Stripe health check failed for home event @nid: @message', [
        '@nid' => (string) $eventId,
        '@message' => $e->getMessage(),
      ]);
      return [
        'label' => (string) $this->t('Payments'),
        'tone' => 'attention',
        'detail' => (string) $this->t('We could not confirm Stripe just now.'),
        'url' => $this->safeUrl('myeventlane_vendor.payouts'),
      ];
    }

    if ($denial === NULL) {
      return [
        'label' => (string) $this->t('Payments'),
        'tone' => 'success',
        'detail' => (string) $this->t("Stripe connected — you're ready to get paid."),
        'url' => $this->safeUrl('myeventlane_vendor.payouts'),
      ];
    }

    return [
      'label' => (string) $this->t('Payments'),
      'tone' => 'attention',
      'detail' => $denial,
      'url' => $this->safeUrl('myeventlane_vendor.payouts'),
    ];
  }

  /**
   * @param array<string, mixed> $eventMeta
   */
  private function resolveEventBookingType(NodeInterface $event, array $eventMeta): string {
    $fromMeta = trim((string) ($eventMeta['event_type'] ?? ''));
    if (in_array($fromMeta, ['paid', 'rsvp', 'both', 'external', 'unknown'], TRUE)) {
      return $fromMeta === 'unknown' ? '' : $fromMeta;
    }
    if ($event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()) {
      return (string) $event->get('field_event_type')->value;
    }
    return '';
  }

  /**
   * @param array<string, mixed> $next
   * @param array<string, mixed> $stripe
   *
   * @return array{title: string, message: string, action_label: ?string, url: ?string}
   */
  private function resolveNextRecommendedAction(
    array $next,
    EventReadinessResult $readiness,
    bool $published,
    int $nid,
    array $stripe = [],
  ): array {
    $title = trim((string) ($next['title'] ?? ''));
    $severity = (string) ($next['severity'] ?? '');

    if ($title !== '' && ($severity === 'error' || ($published && $severity === 'info'))) {
      return [
        'title' => $title,
        'message' => (string) ($next['message'] ?? ''),
        'action_label' => isset($next['action_label']) ? (string) $next['action_label'] : NULL,
        'url' => isset($next['url']) && $next['url'] instanceof Url
          ? $next['url']->toString()
          : (isset($next['url']) ? (string) $next['url'] : NULL),
      ];
    }

    // Publish blockers win over Stripe Connect — never push payouts while
    // checklist items (tickets, schedule, etc.) remain unresolved.
    if (!$readiness->ready) {
      return [
        'title' => (string) $this->t('Continue setup'),
        'message' => isset($readiness->errors[0])
          ? $this->humaniseChecklistLabel($readiness->errors[0])
          : (string) $this->t('Finish the readiness checklist so you can publish.'),
        'action_label' => (string) $this->t('Review publishing'),
        'url' => $this->safeUrl('myeventlane_event_studio.workspace_publishing', ['node' => $nid]),
      ];
    }

    if (($stripe['tone'] ?? '') === 'attention' && ($stripe['url'] ?? NULL)) {
      return [
        'title' => (string) $this->t('Connect Stripe'),
        'message' => (string) ($stripe['detail'] ?? $this->t('Connect payments so you can get paid.')),
        'action_label' => (string) $this->t('Connect Stripe'),
        'url' => (string) $stripe['url'],
      ];
    }

    if (!$published) {
      return [
        'title' => (string) $this->t('Ready when you are'),
        'message' => (string) $this->t('Your event looks ready. Publish when you want guests to find it.'),
        'action_label' => (string) $this->t('Go to publishing'),
        'url' => $this->safeUrl('myeventlane_event_studio.workspace_publishing', ['node' => $nid]),
      ];
    }
    return [
      'title' => (string) $this->t('Share your event'),
      'message' => (string) $this->t('Your event is live. Share the page or message your attendees.'),
      'action_label' => (string) $this->t('Promote'),
      'url' => $this->safeUrl('myeventlane_event_studio.workspace_marketing', ['node' => $nid]),
    ];
  }

  /**
   * @return array{show: bool, title: string, message: string}
   */
  private function buildCelebrationHint(NodeInterface $event, EventReadinessResult $readiness, bool $published): array {
    $title = trim($event->label());
    if ($title !== '' && strcasecmp($title, 'Untitled event') !== 0 && !$published && $readiness->completed !== []) {
      if (count($readiness->completed) === 1) {
        return [
          'show' => TRUE,
          'title' => (string) $this->t('Nice start'),
          'message' => (string) $this->t("Your first draft is underway. Keep going — you're building something people will love."),
        ];
      }
    }
    return [
      'show' => FALSE,
      'title' => '',
      'message' => '',
    ];
  }

  /**
   * @param array<string, mixed> $params
   */
  private function safeUrl(string $route, array $params = []): ?string {
    try {
      return Url::fromRoute($route, $params)->toString();
    }
    catch (\Throwable) {
      return NULL;
    }
  }

}
