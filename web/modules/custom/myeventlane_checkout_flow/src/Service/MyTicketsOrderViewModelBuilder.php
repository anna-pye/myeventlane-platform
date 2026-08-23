<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_commerce\Service\OperationalOrderItemDisplayBuilder;
use Drupal\myeventlane_core\MelReadinessHelper;
use Drupal\myeventlane_core\Service\TicketLabelResolver;
use Drupal\myeventlane_legal\Service\LegalSettingsService;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Service\UniversalTicketViewModelBuilder;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;

/**
 * Builds My Tickets order view models from canonical ticket models.
 */
final class MyTicketsOrderViewModelBuilder {

  use StringTranslationTrait;

  /**
   * Order workflow states that represent a finished purchase for My Tickets.
   *
   * @var list<string>
   */
  public const COMPLETED_ORDER_STATES = [
    'placed',
    'completed',
    'fulfilled',
    'fulfillment',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly UniversalTicketViewModelBuilder $ticketViewModelBuilder,
    private readonly TicketLabelResolver $ticketLabelResolver,
    TranslationInterface $string_translation,
    private readonly ?OperationalOrderItemDisplayBuilder $operationalOrderItemDisplayBuilder = NULL,
    private readonly ?MelReadinessHelper $readiness = NULL,
    private readonly ?LegalSettingsService $legalSettings = NULL,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Builds My Tickets order view models in input order.
   *
   * @param array<int|string, \Drupal\commerce_order\Entity\OrderInterface> $orders
   *   Commerce orders keyed by order ID or arbitrary list indexes.
   *
   * @return list<array<string, mixed>>
   *   My Tickets order view models.
   */
  public function buildMultiple(array $orders, bool $includeDetails = FALSE, bool $includeQr = FALSE): array {
    $ticketModelsByOrder = $this->loadTicketModelsByOrder($orders, $includeQr);
    $models = [];

    foreach ($orders as $order) {
      if (!$order instanceof OrderInterface) {
        continue;
      }
      $orderId = (int) $order->id();
      $models[] = $this->build($order, $includeDetails, $ticketModelsByOrder[$orderId] ?? []);
    }

    return $models;
  }

  /**
   * Builds one My Tickets order view model.
   *
   * @param list<array<string, mixed>>|null $ticketModels
   *   Optional preloaded canonical ticket models for the order.
   *
   * @return array<string, mixed>
   *   My Tickets order view model.
   */
  public function build(OrderInterface $order, bool $includeDetails = FALSE, ?array $ticketModels = NULL, bool $includeQr = TRUE): array {
    $ticketModels ??= $this->loadTicketModelsByOrder([$order], $includeQr)[(int) $order->id()] ?? [];

    $events = [];
    $legacyTicketItems = [];
    $donationTotal = 0.0;
    $hasUpcomingEvents = FALSE;
    $now = time();

    foreach ($ticketModels as $ticketModel) {
      if (!is_array($ticketModel)) {
        continue;
      }
      $event = $this->eventFromTicketModel($ticketModel);
      if ($event !== NULL) {
        $events[$event['id']] = $event;
        if ((int) $event['start_timestamp'] > $now) {
          $hasUpcomingEvents = TRUE;
        }
      }
    }

    foreach ($order->getItems() as $item) {
      if (!$item instanceof OrderItemInterface) {
        continue;
      }

      $bundle = $item->bundle();
      if (in_array($bundle, ['checkout_donation', 'platform_donation', 'rsvp_donation'], TRUE)) {
        $price = $item->getTotalPrice();
        if ($price) {
          $donationTotal += (float) $price->getNumber();
        }
        continue;
      }

      // Boost is an admin product; exclude from My Tickets.
      if ($bundle === 'boost') {
        continue;
      }

      if ($ticketModels === []) {
        $legacyTicketItems[] = $this->legacyTicketItem($item, $includeDetails);
      }

      if ($ticketModels !== []) {
        continue;
      }

      $event = $this->resolveEventFromOrderItem($item);
      if ($event instanceof NodeInterface && $event->bundle() === 'event') {
        $eventId = (int) $event->id();
        if (!isset($events[$eventId])) {
          $eventData = $this->eventFromNode($event);
          $events[$eventId] = $eventData;
          if ((int) $eventData['start_timestamp'] > $now) {
            $hasUpcomingEvents = TRUE;
          }
        }
      }
    }

    if (!$hasUpcomingEvents && ($ticketModels !== [] || $legacyTicketItems !== []) && $events === []) {
      $hasUpcomingEvents = TRUE;
    }

    $stateId = $order->getState()->getId();
    $stateCustomer = in_array($stateId, self::COMPLETED_ORDER_STATES, TRUE)
      ? (string) $this->t('Confirmed')
      : (string) $order->getState()->getLabel();

    $ctaLabels = $this->readiness?->customerHubBookingCtaLabels() ?? [
      'view_booking' => (string) $this->t('View Digital Pass'),
      'view_ticket' => (string) $this->t('Show QR code'),
      'view_event' => (string) $this->t('View event'),
      'add_to_calendar' => (string) $this->t('Add to calendar'),
      'download_ticket' => (string) $this->t('Download PDF'),
      'get_directions' => (string) $this->t('Get directions'),
      'contact_organiser' => (string) $this->t('Contact organiser'),
      'help_centre' => (string) $this->t('Help Centre'),
    ];

    $help = $this->buildHelpLinks();
    $bookingConfirmed = in_array($stateId, self::COMPLETED_ORDER_STATES, TRUE);
    $bookingNumber = (string) ($order->getOrderNumber() ?? $order->id());
    $enrichedTicketModels = [];
    $passIndex = 0;
    foreach ($ticketModels as $ticketModel) {
      if (!is_array($ticketModel)) {
        continue;
      }
      $enriched = $this->enrichDigitalPassPresentation(
        $ticketModel,
        $events,
        $bookingNumber,
        $ctaLabels,
      );
      $passIndex++;
      $passEvent = is_array($enriched['pass']['event'] ?? NULL)
        ? $enriched['pass']['event']
        : NULL;
      $eventId = (int) ($passEvent['id'] ?? ($enriched['event']['id'] ?? 0));
      // Every digital pass gets its own readiness accordion (same-event bookings
      // included). Primary action anchors to this pass's QR, not a sibling pass.
      if ($eventId > 0) {
        $passAnchor = $passIndex === 1 ? '#mel-pass-entry' : '#mel-pass-entry-' . $passIndex;
        $enriched['readiness'] = $this->buildEventReadiness(
          $order,
          $passEvent !== NULL ? [$passEvent] : [],
          [$enriched],
          $help,
          $ctaLabels,
          $bookingConfirmed,
          $passAnchor,
        );
      }
      $enrichedTicketModels[] = $enriched;
    }

    $eventsList = array_values($events);
    // Order-level alias: first attached pass readiness (hub/tests/legacy consumers).
    $orderReadiness = NULL;
    foreach ($enrichedTicketModels as $enrichedModel) {
      if (isset($enrichedModel['readiness']) && is_array($enrichedModel['readiness'])) {
        $orderReadiness = $enrichedModel['readiness'];
        break;
      }
    }
    $orderReadiness ??= $this->buildEventReadiness(
      $order,
      $eventsList,
      $enrichedTicketModels,
      $help,
      $ctaLabels,
      $bookingConfirmed,
    );
    $refundSummary = $this->buildRefundSummary($order);

    return [
      'order' => $order,
      'order_id' => $order->id(),
      'order_number' => $order->getOrderNumber(),
      'order_url' => $this->orderUrl($order),
      'placed_date' => $order->getPlacedTime() ? date('F j, Y g:i A', $order->getPlacedTime()) : NULL,
      'state' => $order->getState()->getLabel(),
      'state_customer_presentation' => $stateCustomer,
      'total_price' => $order->getTotalPrice() ? (float) $order->getTotalPrice()->getNumber() : 0.0,
      'events' => $eventsList,
      'ticket_models' => $enrichedTicketModels,
      'ticket_items' => $legacyTicketItems,
      'donation_total' => $donationTotal,
      'has_upcoming_events' => $hasUpcomingEvents,
      'help' => $help,
      'pass_labels' => $ctaLabels,
      'readiness' => $orderReadiness,
      'refund_summary' => $refundSummary['summary'],
      'cache_tags' => $refundSummary['cache_tags'],
    ];
  }

  /**
   * Builds the customer-facing refund outcome from Commerce payments.
   *
   * Commerce payments are the canonical financial record. The refund module's
   * operational ledger may enrich internal audit data, but the booking page
   * must remain accurate even when that optional module is unavailable.
   *
   * @return array{
   *   summary: array<string, mixed>|null,
   *   cache_tags: list<string>
   *   }
   */
  public function buildRefundSummary(OrderInterface $order): array {
    if (
      $order->id() === NULL
      || !$this->entityTypeManager->hasDefinition('commerce_payment')
    ) {
      return ['summary' => NULL, 'cache_tags' => []];
    }

    $storage = $this->entityTypeManager->getStorage('commerce_payment');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_id', (int) $order->id())
      ->execute();
    if ($ids === []) {
      return ['summary' => NULL, 'cache_tags' => []];
    }

    $paidTotal = NULL;
    $refundedTotal = NULL;
    $cacheTags = [];
    foreach ($storage->loadMultiple($ids) as $payment) {
      if (!$payment instanceof PaymentInterface) {
        continue;
      }
      $cacheTags = array_merge($cacheTags, $payment->getCacheTags());
      $amount = $payment->getAmount();
      $refunded = $payment->getRefundedAmount();
      if (!$amount instanceof Price || !$refunded instanceof Price || $refunded->isZero()) {
        continue;
      }
      if ($paidTotal instanceof Price && $paidTotal->getCurrencyCode() !== $amount->getCurrencyCode()) {
        continue;
      }
      $paidTotal = $paidTotal instanceof Price ? $paidTotal->add($amount) : $amount;
      $refundedTotal = $refundedTotal instanceof Price ? $refundedTotal->add($refunded) : $refunded;
    }

    $cacheTags = array_values(array_unique($cacheTags));
    if (!$paidTotal instanceof Price || !$refundedTotal instanceof Price) {
      return ['summary' => NULL, 'cache_tags' => $cacheTags];
    }

    $isFull = $refundedTotal->greaterThanOrEqual($paidTotal);
    $remaining = $isFull
      ? new Price('0', $paidTotal->getCurrencyCode())
      : $paidTotal->subtract($refundedTotal);

    return [
      'summary' => [
        'status' => $isFull ? 'full' : 'partial',
        'heading' => $isFull
          ? (string) $this->t('Refund processed')
          : (string) $this->t('Partial refund processed'),
        'refunded_amount' => $refundedTotal,
        'original_paid_amount' => $paidTotal,
        'remaining_amount' => $remaining,
        'message' => (string) $this->t('The refund has been approved and recorded. Your bank may take several business days to show it.'),
      ],
      'cache_tags' => $cacheTags,
    ];
  }

  /**
   * Loads canonical ticket models for the supplied orders.
   *
   * @param array<int|string, \Drupal\commerce_order\Entity\OrderInterface> $orders
   *   Orders already scoped by My Tickets customer access.
   *
   * @return array<int, list<array<string, mixed>>>
   *   Canonical ticket models keyed by order ID.
   */
  private function loadTicketModelsByOrder(array $orders, bool $includeQr = TRUE): array {
    $orderIds = [];
    foreach ($orders as $order) {
      if ($order instanceof OrderInterface && $order->id() !== NULL) {
        $orderIds[] = (int) $order->id();
      }
    }
    $orderIds = array_values(array_unique(array_filter($orderIds)));
    if ($orderIds === []) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('myeventlane_ticket');
    $ids = $storage->getQuery()
      ->condition('order_id', $orderIds, 'IN')
      // Orders are already customer-scoped before this query; ticket entity
      // access is admin-oriented and would hide valid customer entitlements.
      ->accessCheck(FALSE)
      ->sort('order_id', 'ASC')
      ->sort('order_item_id', 'ASC')
      ->sort('id', 'ASC')
      ->execute();

    if ($ids === []) {
      return [];
    }

    $models = [];
    foreach ($storage->loadMultiple($ids) as $ticket) {
      if (!$ticket instanceof Ticket || $ticket->get('order_id')->isEmpty()) {
        continue;
      }
      $orderId = (int) $ticket->get('order_id')->target_id;
      // Customer UI may degrade QR when the host secret is missing; PDF/wallet
      // callers use UniversalTicketViewModelBuilder::build() defaults (fail-loud).
      $models[$orderId][] = $this->ticketViewModelBuilder->build($ticket, $includeQr, TRUE);
    }

    return $models;
  }

  /**
   * Adds ACE digital-pass presentation fields without altering operational model keys.
   *
   * @param array<string, mixed> $ticketModel
   *   Canonical ticket view model.
   * @param array<int, array<string, mixed>> $events
   *   Formatted events keyed by event id.
   * @param array<string, string> $ctaLabels
   *   Shared ACE CTA labels.
   *
   * @return array<string, mixed>
   *   Ticket model with a `pass` presentation section.
   */
  private function enrichDigitalPassPresentation(array $ticketModel, array $events, string $bookingNumber, array $ctaLabels): array {
    $eventId = (int) (($ticketModel['event']['id'] ?? 0));
    $passEvent = $events[$eventId] ?? $this->eventFromTicketModel($ticketModel);
    $statusKey = $this->resolveDigitalPassStatusKey($ticketModel);
    $statusLabel = $this->readiness instanceof MelReadinessHelper
      ? $this->readiness->customerDigitalPassStatusLabel($statusKey)
      : $this->fallbackDigitalPassStatusLabel($statusKey);
    $nextStep = $this->readiness instanceof MelReadinessHelper
      ? $this->readiness->customerDigitalPassNextStep($statusKey)
      : (string) $this->t('Show this QR code at entry when you arrive.');

    $vendor = is_array($ticketModel['vendor'] ?? NULL) ? $ticketModel['vendor'] : [];
    $organiser = trim((string) ($vendor['label'] ?? ''));

    $ticketModel['pass'] = [
      'status_key' => $statusKey,
      'status_label' => $statusLabel,
      'next_step' => $nextStep,
      'booking_number' => $bookingNumber,
      'organiser' => $organiser,
      'event' => $passEvent,
      'labels' => [
        'download_ticket' => $ctaLabels['download_ticket'] ?? (string) $this->t('Download ticket'),
        'add_to_calendar' => $ctaLabels['add_to_calendar'] ?? (string) $this->t('Add to calendar'),
        'view_event' => $ctaLabels['view_event'] ?? (string) $this->t('View event'),
        'view_booking' => $ctaLabels['view_booking'] ?? (string) $this->t('View booking'),
      ],
    ];

    return $ticketModel;
  }

  /**
   * Maps issued-ticket signals to ACE digital-pass status keys.
   *
   * @param array<string, mixed> $ticketModel
   *   Canonical ticket view model.
   */
  private function resolveDigitalPassStatusKey(array $ticketModel): string {
    $scannerStatus = (string) ($ticketModel['scanner']['status'] ?? '');
    if (!empty($ticketModel['expiry']['expired']) || $scannerStatus === 'expired' || $scannerStatus === 'fulfilment_expired') {
      return 'expired';
    }

    $status = (string) ($ticketModel['ticket']['status'] ?? '');
    if ($status === Ticket::STATUS_CHECKED_IN || $scannerStatus === 'checked_in') {
      return 'checked_in';
    }
    if (
      $status === Ticket::STATUS_REFUNDED
      || $status === Ticket::STATUS_VOID
      || $scannerStatus === 'refunded'
      || $scannerStatus === 'void'
      || $scannerStatus === 'fulfilment_cancelled'
    ) {
      return 'cancelled';
    }

    return 'ticket_ready';
  }

  /**
   * Fallback ACE pass labels when MelReadinessHelper is unavailable.
   */
  private function fallbackDigitalPassStatusLabel(string $statusKey): string {
    return match ($statusKey) {
      'checked_in' => (string) $this->t('Checked in'),
      'expired' => (string) $this->t('Expired'),
      'cancelled' => (string) $this->t('Cancelled'),
      'payment_pending' => (string) $this->t('Booking received'),
      default => (string) $this->t('Ticket ready'),
    };
  }

  /**
   * Existing help destinations only — no invented organiser contact routes.
   *
   * @return array{refund_url: string, help_centre_url: string}
   *   Help link URLs for the pass footer.
   */
  private function buildHelpLinks(): array {
    $refundUrl = '';
    if ($this->legalSettings instanceof LegalSettingsService) {
      $refundUrl = trim($this->legalSettings->getRefundPolicyUrl());
    }
    if ($refundUrl === '') {
      $refundUrl = '/help/policies/refund-policy';
    }

    return [
      'refund_url' => $refundUrl,
      'help_centre_url' => $this->helpCentreUrl(),
    ];
  }

  /**
   * ACE Event Readiness panel — presentation orchestration over existing data.
   *
   * Does not invent venue/accessibility metadata. Omits checklist rows when the
   * underlying field or link is empty. Reuses MelReadinessHelper copy and the
   * existing messaging reminder timings (7d / 24h) as presentation note only.
   *
   * @param list<array<string, mixed>> $events
   *   Events scoped to this readiness panel (normally the pass's event only).
   * @param list<array<string, mixed>> $ticketModels
   *   Ticket models for the same event / pass (normally a single row).
   * @param array{refund_url: string, help_centre_url: string} $help
   * @param array<string, string> $ctaLabels
   * @param string $passEntryAnchor
   *   In-page QR anchor for this pass (#mel-pass-entry or #mel-pass-entry-N).
   *
   * @return array<string, mixed>
   */
  private function buildEventReadiness(
    OrderInterface $order,
    array $events,
    array $ticketModels,
    array $help,
    array $ctaLabels,
    bool $bookingConfirmed,
    string $passEntryAnchor = '#mel-pass-entry',
  ): array {
    $panel = $this->readiness instanceof MelReadinessHelper
      ? $this->readiness->customerEventReadinessPanelLabels()
      : [
        'heading' => (string) $this->t('Event readiness'),
        'summary' => (string) $this->t('Need more information?'),
        'intro' => (string) $this->t('Accessibility, venue notes, refunds, and who to contact.'),
        'checklist_heading' => (string) $this->t('Your checklist'),
        'reminder_note' => (string) $this->t('We email a reminder 7 days and 24 hours before the event starts.'),
      ];
    $itemLabels = $this->readiness instanceof MelReadinessHelper
      ? $this->readiness->customerEventReadinessItemLabels()
      : [
        'booking_confirmed' => (string) $this->t('Booking confirmed'),
        'ticket_ready' => (string) $this->t('Ticket ready'),
        'date_time' => (string) $this->t('Date & time'),
        'venue' => (string) $this->t('Venue'),
        'organiser' => (string) $this->t('Organiser'),
        'accessibility' => (string) $this->t('Accessibility'),
        'contact_organiser' => (string) $this->t('Contact organiser'),
        'refund_policy' => (string) $this->t('Refund policy'),
        'help' => (string) $this->t('Help'),
      ];

    // Always pair readiness to the ticket/pass shown — never an unrelated events[0].
    $primaryTicket = $ticketModels[0] ?? NULL;
    $primaryEvent = $this->resolveReadinessEventForTicket(
      is_array($primaryTicket) ? $primaryTicket : NULL,
      $events,
    );
    $pass = is_array($primaryTicket) && is_array($primaryTicket['pass'] ?? NULL)
      ? $primaryTicket['pass']
      : [];
    $eventId = (int) ($primaryEvent['id'] ?? 0);
    $eventContext = $this->loadEventReadinessContext($eventId);
    $passEntryAnchor = $this->normalizePassEntryAnchor($passEntryAnchor);

    $stateKey = $this->resolveEventReadinessStateKey(
      $bookingConfirmed,
      is_array($primaryTicket) ? $primaryTicket : NULL,
      is_array($primaryEvent) ? $primaryEvent : NULL,
    );
    $stateLabel = $this->readiness instanceof MelReadinessHelper
      ? $this->readiness->customerEventReadinessStatusLabel($stateKey)
      : $this->fallbackDigitalPassStatusLabel($stateKey);

    $items = [];
    if ($bookingConfirmed) {
      $items[] = [
        'key' => 'booking_confirmed',
        'label' => $itemLabels['booking_confirmed'],
        'ready' => TRUE,
        'detail' => (string) ($order->getOrderNumber() ?? $order->id()),
        'url' => '',
      ];
    }

    $ticketReady = is_array($primaryTicket)
      && (
        !empty($primaryTicket['qr']['data_uri'])
        || !empty($primaryTicket['actions']['pdf']['download']['url'])
        || (($pass['status_key'] ?? '') === 'ticket_ready')
        || (($pass['status_key'] ?? '') === 'checked_in')
      );
    if ($ticketReady) {
      $items[] = [
        'key' => 'ticket_ready',
        'label' => $itemLabels['ticket_ready'],
        'ready' => TRUE,
        'detail' => (string) ($primaryTicket['ticket']['code'] ?? ''),
        'url' => $passEntryAnchor,
      ];
    }

    if (is_array($primaryEvent)) {
      $dateParts = array_filter([
        (string) ($primaryEvent['start_date'] ?? ''),
        (string) ($primaryEvent['start_time'] ?? ''),
      ]);
      if ($dateParts !== []) {
        $items[] = [
          'key' => 'date_time',
          'label' => $itemLabels['date_time'],
          'ready' => TRUE,
          'detail' => implode(' · ', $dateParts),
          'url' => (string) ($primaryEvent['ics_url'] ?? ''),
        ];
      }

      $location = trim((string) ($primaryEvent['location'] ?? ''));
      if ($location !== '') {
        $items[] = [
          'key' => 'venue',
          'label' => $itemLabels['venue'],
          'ready' => TRUE,
          'detail' => $location,
          'url' => $this->directionsUrl($location),
        ];
      }
    }

    $organiser = trim((string) ($pass['organiser'] ?? $eventContext['organiser'] ?? ''));
    if ($organiser !== '') {
      $items[] = [
        'key' => 'organiser',
        'label' => $itemLabels['organiser'],
        'ready' => TRUE,
        'detail' => $organiser,
        'url' => (string) ($primaryEvent['url'] ?? ''),
      ];
    }

    if ($eventContext['accessibility_summary'] !== '') {
      $items[] = [
        'key' => 'accessibility',
        'label' => $itemLabels['accessibility'],
        'ready' => TRUE,
        'detail' => $eventContext['accessibility_summary'],
        'url' => (string) ($primaryEvent['url'] ?? ''),
      ];
    }

    $contactUrl = $eventContext['contact_mailto'];
    if ($contactUrl === '' && is_array($primaryEvent)) {
      $contactUrl = (string) ($primaryEvent['url'] ?? '');
    }
    if ($contactUrl !== '') {
      $items[] = [
        'key' => 'contact_organiser',
        'label' => $itemLabels['contact_organiser'],
        'ready' => TRUE,
        'detail' => $eventContext['contact_email'] !== ''
          ? $eventContext['contact_email']
          : (string) $this->t('Details on the event page'),
        'url' => $contactUrl,
      ];
    }

    $refundUrl = trim((string) ($help['refund_url'] ?? ''));
    if ($refundUrl !== '') {
      $refundDetail = $eventContext['refund_label'] !== ''
        ? $eventContext['refund_label']
        : (string) $this->t('Platform refund policy');
      $items[] = [
        'key' => 'refund_policy',
        'label' => $itemLabels['refund_policy'],
        'ready' => TRUE,
        'detail' => $refundDetail,
        'url' => $refundUrl,
      ];
    }

    $helpUrl = trim((string) ($help['help_centre_url'] ?? ''));
    if ($helpUrl !== '') {
      $items[] = [
        'key' => 'help',
        'label' => $itemLabels['help'],
        'ready' => TRUE,
        'detail' => (string) $this->t('Help Centre'),
        'url' => $helpUrl,
      ];
    }

    // Accordion content: secondary facts only (pass already shows booking/ticket/when).
    $detailKeys = [
      'accessibility',
      'venue',
      'contact_organiser',
      'refund_policy',
      'help',
      'organiser',
    ];
    $detailItems = [];
    foreach ($items as $item) {
      if (in_array((string) ($item['key'] ?? ''), $detailKeys, TRUE)) {
        $detailItems[] = $item;
      }
    }

    return [
      'heading' => $panel['heading'],
      'summary' => $panel['summary'] ?? (string) $this->t('Need more information?'),
      'intro' => $panel['intro'],
      'checklist_heading' => $panel['checklist_heading'],
      'state_key' => $stateKey,
      'state_label' => $stateLabel,
      'items' => $items,
      'detail_items' => $detailItems,
      'primary_action' => $this->resolveEventReadinessPrimaryAction(
        $stateKey,
        is_array($primaryEvent) ? $primaryEvent : NULL,
        is_array($primaryTicket) ? $primaryTicket : NULL,
        $help,
        $ctaLabels,
        $eventContext,
        $passEntryAnchor,
      ),
      'reminder_note' => $panel['reminder_note'],
    ];
  }

  /**
   * Event for readiness must belong to the pass ticket, not a sibling events[0].
   *
   * @param array<string, mixed>|null $ticketModel
   * @param list<array<string, mixed>> $events
   *
   * @return array<string, mixed>|null
   */
  private function resolveReadinessEventForTicket(?array $ticketModel, array $events): ?array {
    if ($ticketModel === NULL) {
      return $events[0] ?? NULL;
    }

    if (is_array($ticketModel['pass']['event'] ?? NULL)) {
      return $ticketModel['pass']['event'];
    }

    $ticketEventId = (int) ($ticketModel['event']['id'] ?? 0);
    if ($ticketEventId > 0) {
      foreach ($events as $candidate) {
        if (!is_array($candidate)) {
          continue;
        }
        if ((int) ($candidate['id'] ?? 0) === $ticketEventId) {
          return $candidate;
        }
      }
      $fromTicket = $this->eventFromTicketModel($ticketModel);
      if ($fromTicket !== NULL) {
        return $fromTicket;
      }
    }

    return $events[0] ?? NULL;
  }

  /**
   * Restricts pass anchors to the Digital Pass QR fragment contract.
   */
  private function normalizePassEntryAnchor(string $anchor): string {
    $anchor = trim($anchor);
    if ($anchor === '#mel-pass-entry' || preg_match('/^#mel-pass-entry-\d+$/', $anchor) === 1) {
      return $anchor;
    }
    return '#mel-pass-entry';
  }

  /**
   * Loads optional event node fields for readiness — only values that exist.
   *
   * @return array{
   *   organiser: string,
   *   contact_email: string,
   *   contact_mailto: string,
   *   accessibility_summary: string,
   *   refund_label: string
   * }
   */
  private function loadEventReadinessContext(int $eventId): array {
    $empty = [
      'organiser' => '',
      'contact_email' => '',
      'contact_mailto' => '',
      'accessibility_summary' => '',
      'refund_label' => '',
    ];
    if ($eventId < 1) {
      return $empty;
    }

    $event = $this->entityTypeManager->getStorage('node')->load($eventId);
    if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
      return $empty;
    }

    $contactEmail = '';
    if ($event->hasField('field_contact_email') && !$event->get('field_contact_email')->isEmpty()) {
      $contactEmail = trim((string) $event->get('field_contact_email')->value);
    }

    $a11yParts = [];
    if ($event->hasField('field_accessibility') && !$event->get('field_accessibility')->isEmpty()) {
      foreach ($event->get('field_accessibility')->referencedEntities() as $term) {
        $label = trim((string) $term->label());
        if ($label !== '') {
          $a11yParts[] = $label;
        }
      }
    }
    foreach (['field_accessibility_entry', 'field_accessibility_parking', 'field_accessibility_directions'] as $fieldName) {
      if (!$event->hasField($fieldName) || $event->get($fieldName)->isEmpty()) {
        continue;
      }
      $value = $event->get($fieldName)->value ?? $event->get($fieldName)->processed ?? '';
      $text = trim(strip_tags((string) $value));
      if ($text !== '') {
        $a11yParts[] = mb_strlen($text) > 120 ? mb_substr($text, 0, 117) . '…' : $text;
        break;
      }
    }

    $refundLabel = '';
    if ($event->hasField('field_refund_policy') && !$event->get('field_refund_policy')->isEmpty()) {
      $value = (string) $event->get('field_refund_policy')->value;
      if ($value !== '' && $value !== 'none_specified') {
        $allowed = $event->get('field_refund_policy')->getFieldDefinition()->getSetting('allowed_values') ?? [];
        if (is_array($allowed) && isset($allowed[$value]) && is_string($allowed[$value])) {
          $refundLabel = $allowed[$value];
        }
        else {
          foreach ($allowed as $option) {
            if (is_array($option) && ($option['value'] ?? '') === $value) {
              $refundLabel = (string) ($option['label'] ?? '');
              break;
            }
          }
        }
      }
    }

    return [
      'organiser' => '',
      'contact_email' => $contactEmail,
      'contact_mailto' => $contactEmail !== '' ? 'mailto:' . $contactEmail : '',
      'accessibility_summary' => implode(' · ', array_slice($a11yParts, 0, 3)),
      'refund_label' => $refundLabel,
    ];
  }

  /**
   * Resolves ACE readiness state without exposing Commerce workflow ids.
   *
   * @param array<string, mixed>|null $ticketModel
   * @param array<string, mixed>|null $event
   */
  private function resolveEventReadinessStateKey(
    bool $bookingConfirmed,
    ?array $ticketModel,
    ?array $event,
  ): string {
    if (!$bookingConfirmed) {
      return 'payment_pending';
    }

    $passKey = '';
    if (is_array($ticketModel) && is_array($ticketModel['pass'] ?? NULL)) {
      $passKey = (string) ($ticketModel['pass']['status_key'] ?? '');
    }
    if (in_array($passKey, ['cancelled', 'expired', 'checked_in'], TRUE)) {
      return $passKey;
    }

    $startTs = (int) ($event['start_timestamp'] ?? 0);
    if ($startTs > 0) {
      $startDay = date('Y-m-d', $startTs);
      $today = date('Y-m-d');
      if ($startDay === $today) {
        return 'today';
      }
      if ($startDay === date('Y-m-d', time() + 86400)) {
        return 'tomorrow';
      }
      if ($startTs < time()) {
        return 'completed';
      }
    }

    if ($passKey === 'ticket_ready' || $ticketModel !== NULL) {
      return 'ticket_ready';
    }

    return 'confirmed';
  }

  /**
   * One primary action that can succeed with existing URLs only.
   *
   * @param array<string, mixed>|null $event
   * @param array<string, mixed>|null $ticketModel
   * @param array{refund_url: string, help_centre_url: string} $help
   * @param array<string, string> $ctaLabels
   * @param array<string, string> $eventContext
   *
   * @return array{key: string, label: string, url: string}|null
   */
  private function resolveEventReadinessPrimaryAction(
    string $stateKey,
    ?array $event,
    ?array $ticketModel,
    array $help,
    array $ctaLabels,
    array $eventContext,
    string $passEntryAnchor = '#mel-pass-entry',
  ): ?array {
    $helpUrl = trim((string) ($help['help_centre_url'] ?? ''));
    $eventUrl = trim((string) ($event['url'] ?? ''));
    $location = trim((string) ($event['location'] ?? ''));
    $directionsUrl = $location !== '' ? $this->directionsUrl($location) : '';
    $pdfUrl = trim((string) ($ticketModel['actions']['pdf']['download']['url'] ?? ''));
    $hasQr = !empty($ticketModel['qr']['data_uri']);
    $contactMailto = trim((string) ($eventContext['contact_mailto'] ?? ''));
    $passEntryAnchor = $this->normalizePassEntryAnchor($passEntryAnchor);

    if (in_array($stateKey, ['cancelled', 'expired'], TRUE)) {
      if ($helpUrl !== '') {
        return [
          'key' => 'help_centre',
          'label' => $ctaLabels['help_centre'] ?? (string) $this->t('Help Centre'),
          'url' => $helpUrl,
        ];
      }
      if ($eventUrl !== '') {
        return [
          'key' => 'view_event',
          'label' => $ctaLabels['view_event'] ?? (string) $this->t('View event'),
          'url' => $eventUrl,
        ];
      }
      return NULL;
    }

    if ($stateKey === 'checked_in') {
      if ($eventUrl !== '') {
        return [
          'key' => 'view_event',
          'label' => $ctaLabels['view_event'] ?? (string) $this->t('View event'),
          'url' => $eventUrl,
        ];
      }
      return NULL;
    }

    if ($stateKey === 'today' && $hasQr) {
      return [
        'key' => 'view_ticket',
        'label' => $ctaLabels['view_ticket'] ?? (string) $this->t('View ticket'),
        'url' => $passEntryAnchor,
      ];
    }

    if ($stateKey === 'today' && $directionsUrl !== '') {
      return [
        'key' => 'get_directions',
        'label' => $ctaLabels['get_directions'] ?? (string) $this->t('Get directions'),
        'url' => $directionsUrl,
      ];
    }

    if ($hasQr || $ticketModel !== NULL) {
      if ($hasQr) {
        return [
          'key' => 'view_ticket',
          'label' => $ctaLabels['view_ticket'] ?? (string) $this->t('View ticket'),
          'url' => $passEntryAnchor,
        ];
      }
      if ($pdfUrl !== '') {
        return [
          'key' => 'download_ticket',
          'label' => $ctaLabels['download_ticket'] ?? (string) $this->t('Download ticket'),
          'url' => $pdfUrl,
        ];
      }
    }

    if ($directionsUrl !== '') {
      return [
        'key' => 'get_directions',
        'label' => $ctaLabels['get_directions'] ?? (string) $this->t('Get directions'),
        'url' => $directionsUrl,
      ];
    }

    if ($contactMailto !== '') {
      return [
        'key' => 'contact_organiser',
        'label' => $ctaLabels['contact_organiser'] ?? (string) $this->t('Contact organiser'),
        'url' => $contactMailto,
      ];
    }

    if ($eventUrl !== '') {
      return [
        'key' => 'view_event',
        'label' => $ctaLabels['view_event'] ?? (string) $this->t('View event'),
        'url' => $eventUrl,
      ];
    }

    if ($helpUrl !== '') {
      return [
        'key' => 'help_centre',
        'label' => $ctaLabels['help_centre'] ?? (string) $this->t('Help Centre'),
        'url' => $helpUrl,
      ];
    }

    return NULL;
  }

  /**
   * Directions URL from an existing location string (same pattern as event pages).
   */
  private function directionsUrl(string $location): string {
    $location = trim($location);
    if ($location === '') {
      return '';
    }
    return 'https://maps.google.com/?q=' . rawurlencode($location);
  }

  /**
   * Resolves the Help Centre home URL when the route exists.
   */
  private function helpCentreUrl(): string {
    try {
      return Url::fromRoute('myeventlane_help_centre.home')->toString();
    }
    catch (\Throwable) {
      return '/help';
    }
  }

  /**
   * Builds event display data from a canonical ticket model.
   *
   * @return array<string, mixed>|null
   *   Event data, or NULL when no event is linked.
   */
  private function eventFromTicketModel(array $ticketModel): ?array {
    $event = $ticketModel['event'] ?? [];
    if (!is_array($event)) {
      return NULL;
    }

    $eventId = (int) ($event['id'] ?? 0);
    if ($eventId < 1) {
      return NULL;
    }

    $start = is_array($event['start'] ?? NULL) ? $event['start'] : [];
    $end = is_array($event['end'] ?? NULL) ? $event['end'] : [];
    $startTimestamp = (int) ($start['timestamp'] ?? 0);
    $endTimestamp = (int) ($end['timestamp'] ?? 0);

    return [
      'id' => $eventId,
      'title' => (string) ($event['label'] ?? ''),
      'url' => (string) ($event['url'] ?? ''),
      'ics_url' => $this->calendarUrl($eventId),
      'start_date' => $startTimestamp > 0 ? date('F j, Y', $startTimestamp) : NULL,
      'start_time' => $startTimestamp > 0 ? date('g:i A', $startTimestamp) : NULL,
      'start_timestamp' => $startTimestamp,
      'end_date' => $endTimestamp > 0 ? date('F j, Y', $endTimestamp) : NULL,
      'end_time' => $endTimestamp > 0 ? date('g:i A', $endTimestamp) : NULL,
      'location' => (string) ($event['location'] ?? ''),
    ];
  }

  /**
   * Builds event display data from a legacy order item event.
   *
   * @return array<string, mixed>
   *   Event data.
   */
  private function eventFromNode(NodeInterface $event): array {
    $eventId = (int) $event->id();
    $startTime = $this->timestampFromField($event, 'field_event_start');
    $endTime = $this->timestampFromField($event, 'field_event_end');

    return [
      'id' => $eventId,
      'title' => $event->label(),
      'url' => $event->toUrl()->toString(),
      'ics_url' => $this->calendarUrl($eventId),
      'start_date' => $startTime > 0 ? date('F j, Y', $startTime) : NULL,
      'start_time' => $startTime > 0 ? date('g:i A', $startTime) : NULL,
      'start_timestamp' => $startTime,
      'end_date' => $endTime > 0 ? date('F j, Y', $endTime) : NULL,
      'end_time' => $endTime > 0 ? date('g:i A', $endTime) : NULL,
      'location' => $this->eventLocation($event),
    ];
  }

  /**
   * Builds a legacy order-item ticket row when no issued tickets exist yet.
   *
   * @return array<string, mixed>
   *   Legacy ticket line display data.
   */
  private function legacyTicketItem(OrderItemInterface $item, bool $includeDetails): array {
    $operational_display = $this->operationalOrderItemDisplayBuilder?->buildForOrderItem($item);
    $ticketItem = [
      'title' => is_array($operational_display)
        ? (string) ($operational_display['title'] ?? '')
        : $this->ticketLabelResolver->getTicketLabel($item),
      'quantity' => (int) $item->getQuantity(),
      'price' => $item->getTotalPrice() ? $item->getTotalPrice()->getNumber() : 0.0,
      'attendees' => [],
      'operational_addon_display' => $operational_display,
    ];

    if ($includeDetails && $item->hasField('field_ticket_holder') && !$item->get('field_ticket_holder')->isEmpty()) {
      foreach ($item->get('field_ticket_holder')->referencedEntities() as $paragraph) {
        if (!$paragraph instanceof ParagraphInterface) {
          continue;
        }
        $firstName = $paragraph->hasField('field_first_name') && !$paragraph->get('field_first_name')->isEmpty()
          ? $paragraph->get('field_first_name')->value : '';
        $lastName = $paragraph->hasField('field_last_name') && !$paragraph->get('field_last_name')->isEmpty()
          ? $paragraph->get('field_last_name')->value : '';
        $email = $paragraph->hasField('field_email') && !$paragraph->get('field_email')->isEmpty()
          ? $paragraph->get('field_email')->value : '';

        $ticketItem['attendees'][] = [
          'name' => trim($firstName . ' ' . $lastName),
          'email' => $email,
        ];
      }
    }

    return $ticketItem;
  }

  /**
   * Resolves event node from order item.
   *
   * Mirrors \Drupal\myeventlane_tickets\Ticket\TicketIssuer::resolveEventFromOrderItem
   * (same precedence and instanceof-only checks) so legacy My Tickets rows
   * continue to match issuance when issued ticket entities are absent.
   */
  private function resolveEventFromOrderItem(OrderItemInterface $orderItem): ?NodeInterface {
    $purchasedEntity = $orderItem->getPurchasedEntity();
    if ($purchasedEntity && $purchasedEntity->hasField('field_event') && !$purchasedEntity->get('field_event')->isEmpty()) {
      $node = $purchasedEntity->get('field_event')->entity;
      return $node instanceof NodeInterface ? $node : NULL;
    }

    $product = $purchasedEntity !== NULL && method_exists($purchasedEntity, 'getProduct')
      ? $purchasedEntity->getProduct()
      : NULL;
    if ($product && $product->hasField('field_event') && !$product->get('field_event')->isEmpty()) {
      $node = $product->get('field_event')->entity;
      return $node instanceof NodeInterface ? $node : NULL;
    }

    if ($orderItem->hasField('field_target_event') && !$orderItem->get('field_target_event')->isEmpty()) {
      $node = $orderItem->get('field_target_event')->entity;
      return $node instanceof NodeInterface ? $node : NULL;
    }

    return NULL;
  }

  /**
   * Reads a timestamp from a datetime field.
   */
  private function timestampFromField(NodeInterface $event, string $fieldName): int {
    if (!$event->hasField($fieldName) || $event->get($fieldName)->isEmpty()) {
      return 0;
    }

    $timestamp = strtotime((string) $event->get($fieldName)->value);
    return $timestamp === FALSE ? 0 : $timestamp;
  }

  /**
   * Extracts the legacy event location string.
   */
  private function eventLocation(NodeInterface $event): ?string {
    if ($event->hasField('field_location') && !$event->get('field_location')->isEmpty()) {
      return (string) $event->get('field_location')->value;
    }
    return NULL;
  }

  /**
   * Builds the optional calendar download URL.
   */
  private function calendarUrl(int $eventId): string {
    try {
      return Url::fromRoute('myeventlane_rsvp.ics_download', ['node' => $eventId])->toString();
    }
    catch (\Throwable) {
      return '';
    }
  }

  /**
   * Builds the Commerce canonical order URL when the route is available.
   */
  private function orderUrl(OrderInterface $order): string {
    try {
      return $order->toUrl('canonical', ['absolute' => TRUE])->toString();
    }
    catch (\Throwable) {
      return '';
    }
  }

}
