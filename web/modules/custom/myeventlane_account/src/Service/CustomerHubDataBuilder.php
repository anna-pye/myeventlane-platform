<?php

declare(strict_types=1);

namespace Drupal\myeventlane_account\Service;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\flag\FlaggingInterface;
use Drupal\image\ImageStyleInterface;
use Drupal\myeventlane_core\MelReadinessHelper;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\node\NodeInterface;

/**
 * Canonical customer participation data: tickets, RSVPs, orders, saved context.
 *
 * Consolidates participation logic in the canonical My Account dashboard.
 * Past/upcoming uses event end when present (else start), matching the My
 * Account dashboard semantics.
 */
final class CustomerHubDataBuilder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MelReadinessHelper $readiness,
  ) {}

  /**
   * Builds participation slices for hub UIs.
   *
   * @param int $userId
   *   Drupal user ID, or 0 when only email is known.
   * @param string $userEmail
   *   Normalised email; used for anonymous / guest order matching.
   * @param int $now
   *   Request time unix timestamp.
   * @param bool $includeRsvpSubmissions
   *   When FALSE, skips rsvp_submission entities (guest flows without uid).
   *
   * @return array{
   *   upcoming_tickets: list<array<string, mixed>>,
   *   upcoming_rsvps: list<array<string, mixed>>,
   *   past_events: list<array<string, mixed>>,
   *   unified_upcoming: list<array<string, mixed>>,
   *   unified_past: list<array<string, mixed>>,
   *   next_booking: array<string, mixed>|null,
   *   upcoming_bookings: list<array<string, mixed>>
   * }
   */
  public function buildParticipationLists(int $userId, string $userEmail, int $now, bool $includeRsvpSubmissions = TRUE): array {
    $userEmail = trim($userEmail);
    $eventMap = $this->buildEventMap($userId, $userEmail, $includeRsvpSubmissions);

    $upcomingTickets = [];
    $upcomingRsvps = [];
    $pastEvents = [];

    foreach ($eventMap as $eventData) {
      $isPast = $this->isPastEvent($eventData, $now);
      if ($isPast) {
        $pastEvents[] = $this->enrichBookingPresentation($eventData, 'past', $now, $userId);
      }
      elseif (($eventData['source'] ?? '') === 'ticket') {
        $upcomingTickets[] = $this->enrichBookingPresentation($eventData, 'upcoming', $now, $userId);
      }
      else {
        $upcomingRsvps[] = $this->enrichBookingPresentation($eventData, 'upcoming', $now, $userId);
      }
    }

    $sortTs = static fn(array $a, array $b): int => ($a['end_timestamp'] ?: $a['start_timestamp']) <=> ($b['end_timestamp'] ?: $b['start_timestamp']);
    usort($upcomingTickets, $sortTs);
    usort($upcomingRsvps, $sortTs);
    usort($pastEvents, static fn(array $a, array $b): int => ($b['end_timestamp'] ?: $b['start_timestamp']) <=> ($a['end_timestamp'] ?: $a['start_timestamp']));

    $unifiedUpcoming = array_merge($upcomingTickets, $upcomingRsvps);
    usort($unifiedUpcoming, $sortTs);

    $nextBooking = $unifiedUpcoming[0] ?? NULL;
    $upcomingBookings = $unifiedUpcoming;
    if ($nextBooking !== NULL) {
      $nextId = (int) ($nextBooking['id'] ?? 0);
      $upcomingBookings = array_values(array_filter(
        $unifiedUpcoming,
        static fn(array $row): bool => (int) ($row['id'] ?? 0) !== $nextId,
      ));
    }

    return [
      'upcoming_tickets' => $upcomingTickets,
      'upcoming_rsvps' => $upcomingRsvps,
      'past_events' => $pastEvents,
      'unified_upcoming' => $unifiedUpcoming,
      'unified_past' => $pastEvents,
      'next_booking' => $nextBooking,
      'upcoming_bookings' => $upcomingBookings,
    ];
  }

  /**
   * Builds a hub event card row from a published event node.
   *
   * Used by Saved Events and other customer surfaces that share
   * mel-account-event-card.html.twig.
   *
   * @return array<string, mixed>
   */
  public function buildHubEventFromNode(NodeInterface $event, string $source = 'saved'): array {
    $row = $this->buildEventItem($event, $source, '', NULL, NULL);
    return $this->enrichBookingPresentation($row, 'saved', time(), 0);
  }

  /**
   * Flag AJAX link for save / unsave on hub event cards.
   *
   * @return array<string, mixed>|null
   *   Render array for flag.link_builder, or NULL when Flag is unavailable.
   */
  public function buildEventSaveFlagLink(int $eventId): ?array {
    if ($eventId < 1) {
      return NULL;
    }
    if (!\Drupal::moduleHandler()->moduleExists('flag') || !\Drupal::hasService('flag.link_builder')) {
      return NULL;
    }
    return [
      '#lazy_builder' => [
        'flag.link_builder:build',
        [
          'node',
          (string) $eventId,
          'event_save',
          'default',
        ],
      ],
      '#create_placeholder' => TRUE,
    ];
  }

  /**
   * Saved events for the hub (event_save flag), newest saves first for preview.
   *
   * Aligns visibility with the mel_saved_events View: published event nodes in a
   * published moderation state when moderation is present.
   *
   * @return list<array<string, mixed>>
   */
  public function buildSavedEventsPreview(int $userId, int $limit = 6): array {
    if ($userId <= 0 || !$this->entityTypeManager->hasDefinition('flagging')) {
      return [];
    }

    $flaggingStorage = $this->entityTypeManager->getStorage('flagging');
    $flaggingIds = $flaggingStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('flag_id', 'event_save')
      ->condition('uid', $userId)
      ->execute();

    if ($flaggingIds === []) {
      return [];
    }

    $byId = [];
    foreach ($flaggingStorage->loadMultiple($flaggingIds) as $flagging) {
      if (!$flagging instanceof FlaggingInterface) {
        continue;
      }
      $entity = $flagging->getFlaggable();
      if (!$entity instanceof NodeInterface || $entity->bundle() !== 'event') {
        continue;
      }
      if (!$this->isPublishedCustomerVisibleEvent($entity)) {
        continue;
      }
      $nid = (int) $entity->id();
      if (isset($byId[$nid])) {
        continue;
      }
      $byId[$nid] = $this->buildHubEventFromNode($entity, 'saved');
    }

    $list = array_values($byId);
    $sortTs = static fn(array $a, array $b): int => ($a['start_timestamp'] ?: 0) <=> ($b['start_timestamp'] ?: 0);
    usort($list, $sortTs);

    if ($limit > 0) {
      return array_slice($list, 0, $limit);
    }
    return $list;
  }

  /**
   * Adds ACE status language and primary/secondary CTAs to a hub booking row.
   *
   * @param array<string, mixed> $eventData
   * @param string $lifecycle
   *   upcoming|past|saved
   * @param int $userId
   *   Signed-in user id (for RSVP manage deep links); 0 when unknown.
   *
   * @return array<string, mixed>
   */
  public function enrichBookingPresentation(array $eventData, string $lifecycle, int $now, int $userId = 0): array {
    $statusKey = $this->resolveStatusKey($eventData, $lifecycle, $now);
    $eventData['status_key'] = $statusKey;
    $eventData['status_label'] = $this->readiness->customerHubBookingStatusLabel($statusKey);

    $ctas = $this->resolveBookingCtas($eventData, $lifecycle, $userId);
    $eventData['primary_cta'] = $ctas['primary'];
    $eventData['secondary_cta'] = $ctas['secondary'];

    return $eventData;
  }

  /**
   * @param array<string, mixed> $eventData
   */
  private function resolveStatusKey(array $eventData, string $lifecycle, int $now): string {
    if ($lifecycle === 'past') {
      return 'completed';
    }
    if ($lifecycle === 'saved') {
      return 'confirmed';
    }

    $startTs = (int) ($eventData['start_timestamp'] ?? 0);
    if ($startTs > 0) {
      $startDay = date('Y-m-d', $startTs);
      $today = date('Y-m-d', $now);
      if ($startDay === $today) {
        return 'today';
      }
      if ($startDay === date('Y-m-d', $now + 86400)) {
        return 'tomorrow';
      }
    }

    if (!empty($eventData['has_ticket_code']) || !empty($eventData['pdf_available'])) {
      return 'ticket_ready';
    }

    if (($eventData['source'] ?? '') === 'rsvp') {
      return 'rsvp';
    }

    return 'confirmed';
  }

  /**
   * One primary + optional secondary CTA; only URLs that already exist on the row.
   *
   * @param array<string, mixed> $eventData
   *
   * @return array{primary: array{label: string, url: string}|null, secondary: array{label: string, url: string}|null}
   */
  private function resolveBookingCtas(array $eventData, string $lifecycle, int $userId = 0): array {
    $labels = $this->readiness->customerHubBookingCtaLabels();
    $primary = NULL;
    $secondary = NULL;

    if ($lifecycle === 'past') {
      $eventUrl = (string) ($eventData['url'] ?? '');
      if ($eventUrl !== '') {
        $primary = [
          'label' => $labels['view_event'],
          'url' => $eventUrl,
        ];
      }
      return ['primary' => $primary, 'secondary' => NULL];
    }

    if ($lifecycle === 'saved') {
      $eventUrl = (string) ($eventData['url'] ?? '');
      if ($eventUrl !== '') {
        $primary = [
          'label' => $labels['view_event'],
          'url' => $eventUrl,
        ];
      }
      $ics = (string) ($eventData['ics_url'] ?? '');
      if ($ics !== '') {
        $secondary = [
          'label' => $labels['add_to_calendar'],
          'url' => $ics,
        ];
      }
      return ['primary' => $primary, 'secondary' => $secondary];
    }

    $source = (string) ($eventData['source'] ?? '');
    $ticketUrl = (string) ($eventData['ticket_url'] ?? '');
    $pdfUrl = (string) ($eventData['pdf_url'] ?? '');
    $eventUrl = (string) ($eventData['url'] ?? '');
    $icsUrl = (string) ($eventData['ics_url'] ?? '');

    if ($source === 'rsvp') {
      if ($userId > 0) {
        try {
          $primary = [
            'label' => $labels['manage_rsvp'],
            'url' => Url::fromRoute('myeventlane_rsvp.user_list', ['user' => $userId])->toString(),
          ];
        }
        catch (\Throwable) {
          $primary = NULL;
        }
      }
      if ($primary === NULL && $eventUrl !== '') {
        $primary = [
          'label' => $labels['view_event'],
          'url' => $eventUrl,
        ];
      }
      if ($icsUrl !== '') {
        $secondary = [
          'label' => $labels['add_to_calendar'],
          'url' => $icsUrl,
        ];
      }
      elseif ($eventUrl !== '' && ($primary['url'] ?? '') !== $eventUrl) {
        $secondary = [
          'label' => $labels['view_event'],
          'url' => $eventUrl,
        ];
      }
      return ['primary' => $primary, 'secondary' => $secondary];
    }

    if ($ticketUrl !== '') {
      $primary = [
        'label' => $labels['view_booking'],
        'url' => $ticketUrl,
      ];
      if ($pdfUrl !== '') {
        $secondary = [
          'label' => $labels['download_ticket'],
          'url' => $pdfUrl,
        ];
      }
      elseif ($eventUrl !== '') {
        $secondary = [
          'label' => $labels['view_event'],
          'url' => $eventUrl,
        ];
      }
      return ['primary' => $primary, 'secondary' => $secondary];
    }

    if ($pdfUrl !== '') {
      $primary = [
        'label' => $labels['download_ticket'],
        'url' => $pdfUrl,
      ];
      if ($eventUrl !== '') {
        $secondary = [
          'label' => $labels['view_event'],
          'url' => $eventUrl,
        ];
      }
      return ['primary' => $primary, 'secondary' => $secondary];
    }

    if ($eventUrl !== '') {
      $primary = [
        'label' => $labels['view_event'],
        'url' => $eventUrl,
      ];
    }
    if ($icsUrl !== '') {
      $secondary = [
        'label' => $labels['add_to_calendar'],
        'url' => $icsUrl,
      ];
    }

    return ['primary' => $primary, 'secondary' => $secondary];
  }

  /**
   * @return array<int, array<string, mixed>>
   *   Map keyed by event node ID.
   */
  private function buildEventMap(int $userId, string $userEmail, bool $includeRsvpSubmissions): array {
    $eventMap = [];
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $attendeeStorage = $this->entityTypeManager->getStorage('event_attendee');

    $attendeeQuery = $attendeeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', EventAttendee::STATUS_CONFIRMED);

    if ($userId > 0) {
      if ($userEmail !== '') {
        $identityGroup = $attendeeQuery->orConditionGroup()
          ->condition('uid', $userId)
          ->condition('email', $userEmail);
        $attendeeQuery->condition($identityGroup);
      }
      else {
        $attendeeQuery->condition('uid', $userId);
      }
    }
    elseif ($userEmail !== '') {
      $attendeeQuery->condition('email', $userEmail);
    }
    else {
      return [];
    }

    $attendeeIds = $attendeeQuery->execute();
    $attendees = !empty($attendeeIds) ? $attendeeStorage->loadMultiple($attendeeIds) : [];

    foreach ($attendees as $attendee) {
      if (!$attendee instanceof EventAttendee) {
        continue;
      }
      $eventId = (int) $attendee->get('event')->target_id;
      if ($eventId < 1) {
        continue;
      }

      $source = (string) ($attendee->get('source')->value ?? 'ticket');
      if (isset($eventMap[$eventId]) && !$this->ticketSourcePrecedes($source, (string) ($eventMap[$eventId]['source'] ?? ''))) {
        continue;
      }

      $event = $nodeStorage->load($eventId);
      if (!$event || $event->bundle() !== 'event') {
        continue;
      }

      $orderItemId = NULL;
      $orderId = NULL;
      if ($attendee->hasField('order_item') && !$attendee->get('order_item')->isEmpty()) {
        $orderItemId = (int) $attendee->get('order_item')->target_id;
        $orderItemEntity = $attendee->get('order_item')->entity;
        if ($orderItemEntity instanceof OrderItemInterface) {
          $orderId = (int) $orderItemEntity->getOrderId() ?: NULL;
        }
      }
      $eventMap[$eventId] = $this->buildEventItem(
        $event,
        $source,
        $attendee->get('ticket_code')->value ?? '',
        (int) $attendee->id(),
        $orderItemId,
        $orderId,
      );
    }

    $orderStorage = $this->entityTypeManager->getStorage('commerce_order');
    $orders = [];
    if ($userId > 0 || $userEmail !== '') {
      $orderQuery = $orderStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('state', 'completed');

      if ($userId > 0) {
        if ($userEmail !== '') {
          $orGroup = $orderQuery->orConditionGroup()
            ->condition('uid', $userId)
            ->condition('mail', $userEmail);
          $orderQuery->condition($orGroup);
        }
        else {
          $orderQuery->condition('uid', $userId);
        }
      }
      else {
        $orderQuery->condition('mail', $userEmail);
      }

      $orderIds = $orderQuery->execute();
      $orders = !empty($orderIds) ? $orderStorage->loadMultiple($orderIds) : [];
    }

    foreach ($orders as $order) {
      if (!$order instanceof OrderInterface) {
        continue;
      }
      foreach ($order->getItems() as $orderItem) {
        if (!$orderItem->hasField('field_target_event') || $orderItem->get('field_target_event')->isEmpty()) {
          continue;
        }

        $event = $orderItem->get('field_target_event')->entity;
        if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
          continue;
        }

        $eventId = (int) $event->id();
        if (isset($eventMap[$eventId]) && ($eventMap[$eventId]['source'] ?? '') === 'ticket') {
          continue;
        }

        $oiAttendeeIds = $attendeeStorage->getQuery()
          ->accessCheck(FALSE)
          ->condition('event', $eventId)
          ->condition('order_item', $orderItem->id())
          ->range(0, 1)
          ->execute();

        $ticketCode = '';
        $attendeeId = NULL;
        if (!empty($oiAttendeeIds)) {
          $oiAttendee = $attendeeStorage->load(reset($oiAttendeeIds));
          if ($oiAttendee instanceof EventAttendee) {
            $ticketCode = $oiAttendee->get('ticket_code')->value ?? '';
            $attendeeId = (int) $oiAttendee->id();
          }
        }

        $eventMap[$eventId] = $this->buildEventItem($event, 'ticket', $ticketCode, $attendeeId, (int) $orderItem->id(), (int) $order->id());
      }
    }

    if ($includeRsvpSubmissions && $userId > 0 && $this->entityTypeManager->hasDefinition('rsvp_submission')) {
      $rsvpStorage = $this->entityTypeManager->getStorage('rsvp_submission');
      $rsvpIds = $rsvpStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('user_id', $userId)
        ->condition('status', 'confirmed')
        ->execute();

      $rsvps = !empty($rsvpIds) ? $rsvpStorage->loadMultiple($rsvpIds) : [];

      foreach ($rsvps as $rsvp) {
        if (!$rsvp instanceof ContentEntityInterface) {
          continue;
        }
        if (!$rsvp->hasField('event_id') || $rsvp->get('event_id')->isEmpty()) {
          continue;
        }

        $event = $rsvp->get('event_id')->entity;
        if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
          continue;
        }

        $eventId = (int) $event->id();
        if (isset($eventMap[$eventId])) {
          continue;
        }

        $eventMap[$eventId] = $this->buildEventItem($event, 'rsvp', '', NULL, NULL);
      }
    }

    return $eventMap;
  }

  /**
   * Ticket purchases outrank RSVP rows for the same event in hub previews.
   */
  private function ticketSourcePrecedes(string $candidateSource, string $existingSource): bool {
    if ($candidateSource === 'ticket' && $existingSource !== 'ticket') {
      return TRUE;
    }
    return $existingSource === '';
  }

  /**
   * @param array<string, mixed> $eventData
   */
  private function isPastEvent(array $eventData, int $now): bool {
    $endTs = (int) ($eventData['end_timestamp'] ?? 0);
    $startTs = (int) ($eventData['start_timestamp'] ?? 0);
    if ($endTs > 0) {
      return $endTs < $now;
    }
    return $startTs > 0 && $startTs < $now;
  }

  /**
   * Matches mel_saved_events visibility (published + editorial published state).
   */
  private function isPublishedCustomerVisibleEvent(NodeInterface $event): bool {
    if (!$event->isPublished()) {
      return FALSE;
    }
    if ($event->hasField('moderation_state') && !$event->get('moderation_state')->isEmpty()) {
      return ($event->get('moderation_state')->value ?? '') === 'published';
    }
    return TRUE;
  }

  /**
   * @return array<string, mixed>
   */
  private function buildEventItem(NodeInterface $event, string $source, string $ticketCode, ?int $attendeeId, ?int $orderItemId, ?int $orderId = NULL): array {
    $eventId = (int) $event->id();
    $startTime = $this->getEventStartTime($event);
    $endTime = $this->getEventEndTime($event);

    $imageUrl = '';
    if ($event->hasField('field_event_image') && !$event->get('field_event_image')->isEmpty()) {
      $file = $event->get('field_event_image')->entity;
      if ($file instanceof FileInterface && $file->getFileUri()) {
        $style = $this->getEventImageStyle();
        $imageUrl = $style ? $style->buildUrl($file->getFileUri()) : '';
      }
    }

    $location = '';
    if ($event->hasField('field_venue_name') && !$event->get('field_venue_name')->isEmpty()) {
      $location = $event->get('field_venue_name')->value;
    }
    elseif ($event->hasField('field_location') && !$event->get('field_location')->isEmpty()) {
      $location = $event->get('field_location')->value;
    }

    $code = trim($ticketCode);
    $hasTicketCode = $code !== '';
    $pdfAvailable = ($orderItemId !== NULL && $orderItemId > 0) || $hasTicketCode;

    // Deep link to the My Tickets order screen. Access is enforced by the
    // target route's _custom_access; the URL is only built for the user's own
    // rows, and reuses an existing route (no new route declared here).
    $ticketUrl = NULL;
    if ($orderId !== NULL && $orderId > 0) {
      $ticketUrl = Url::fromRoute('myeventlane_checkout_flow.order_detail', ['commerce_order' => $orderId])->toString();
    }

    // Reuse the canonical ticket PDF endpoints (ownership enforced in
    // TicketDownloadController). Prefer the by-code route; fall back to the
    // order-item route when no ticket code is present.
    $pdfUrl = NULL;
    if ($pdfAvailable) {
      if ($hasTicketCode) {
        $pdfUrl = Url::fromRoute('myeventlane_tickets.download_pdf_by_code', ['ticket_code' => $code])->toString();
      }
      elseif ($orderItemId !== NULL && $orderItemId > 0) {
        $pdfUrl = Url::fromRoute('myeventlane_tickets.download_pdf', ['order_item_id' => $orderItemId])->toString();
      }
    }

    return [
      'id' => $eventId,
      'title' => $event->label(),
      'url' => $event->toUrl()->toString(),
      'ics_url' => Url::fromRoute('myeventlane_rsvp.ics_download', ['node' => $eventId])->toString(),
      'start_date' => $startTime ? date('M j, Y', $startTime) : '',
      'start_time' => $startTime ? date('g:ia', $startTime) : '',
      'start_timestamp' => $startTime ?: 0,
      'end_timestamp' => $endTime ?: 0,
      'image_url' => $imageUrl,
      'location' => $location,
      'source' => $source,
      'ticket_code' => $code,
      'has_ticket_code' => $hasTicketCode,
      'attendee_id' => $attendeeId,
      'order_item_id' => $orderItemId,
      'order_id' => $orderId,
      'pdf_available' => $pdfAvailable,
      'ticket_url' => $ticketUrl,
      'pdf_url' => $pdfUrl,
    ];
  }

  private function getEventImageStyle(): ?ImageStyleInterface {
    $storage = $this->entityTypeManager->getStorage('image_style');
    foreach (['medium', 'thumbnail', 'large'] as $styleId) {
      $style = $storage->load($styleId);
      if ($style instanceof ImageStyleInterface) {
        return $style;
      }
    }
    return NULL;
  }

  private function getEventStartTime(NodeInterface $event): ?int {
    if (!$event->hasField('field_event_start') || $event->get('field_event_start')->isEmpty()) {
      return NULL;
    }
    try {
      $time = strtotime($event->get('field_event_start')->value);
      return $time ?: NULL;
    }
    catch (\Exception) {
      return NULL;
    }
  }

  private function getEventEndTime(NodeInterface $event): ?int {
    if (!$event->hasField('field_event_end') || $event->get('field_event_end')->isEmpty()) {
      return NULL;
    }
    try {
      $time = strtotime($event->get('field_event_end')->value);
      return $time ?: NULL;
    }
    catch (\Exception) {
      return NULL;
    }
  }

}
