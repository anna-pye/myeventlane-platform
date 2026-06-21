<?php

declare(strict_types=1);

namespace Drupal\myeventlane_account\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\image\ImageStyleInterface;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\node\NodeInterface;

/**
 * Canonical customer participation data: tickets, RSVPs, orders, saved context.
 *
 * Consolidates logic previously split between MyAccountController and
 * CustomerDashboardController. Past/upcoming uses event end when present
 * (else start), matching the My Account dashboard semantics.
 */
final class CustomerHubDataBuilder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
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
   *   unified_past: list<array<string, mixed>>
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
        $pastEvents[] = $eventData;
      }
      elseif (($eventData['source'] ?? '') === 'ticket') {
        $upcomingTickets[] = $eventData;
      }
      else {
        $upcomingRsvps[] = $eventData;
      }
    }

    $sortTs = static fn(array $a, array $b): int => ($a['end_timestamp'] ?: $a['start_timestamp']) <=> ($b['end_timestamp'] ?: $b['start_timestamp']);
    usort($upcomingTickets, $sortTs);
    usort($upcomingRsvps, $sortTs);
    usort($pastEvents, static fn(array $a, array $b): int => ($b['end_timestamp'] ?: $b['start_timestamp']) <=> ($a['end_timestamp'] ?: $a['start_timestamp']));

    $unifiedUpcoming = array_merge($upcomingTickets, $upcomingRsvps);
    usort($unifiedUpcoming, $sortTs);

    return [
      'upcoming_tickets' => $upcomingTickets,
      'upcoming_rsvps' => $upcomingRsvps,
      'past_events' => $pastEvents,
      'unified_upcoming' => $unifiedUpcoming,
      'unified_past' => $pastEvents,
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
      $byId[$nid] = $this->buildEventItem($entity, 'saved', '', NULL, NULL);
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

      $orderItemId = $attendee->hasField('order_item') && !$attendee->get('order_item')->isEmpty()
        ? (int) $attendee->get('order_item')->target_id
        : NULL;
      $eventMap[$eventId] = $this->buildEventItem(
        $event,
        $source,
        $attendee->get('ticket_code')->value ?? '',
        (int) $attendee->id(),
        $orderItemId,
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
          if ($oiAttendee) {
            $ticketCode = $oiAttendee->get('ticket_code')->value ?? '';
            $attendeeId = (int) $oiAttendee->id();
          }
        }

        $eventMap[$eventId] = $this->buildEventItem($event, 'ticket', $ticketCode, $attendeeId, (int) $orderItem->id());
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
  private function buildEventItem(NodeInterface $event, string $source, string $ticketCode, ?int $attendeeId, ?int $orderItemId): array {
    $eventId = (int) $event->id();
    $startTime = $this->getEventStartTime($event);
    $endTime = $this->getEventEndTime($event);

    $imageUrl = '';
    if ($event->hasField('field_event_image') && !$event->get('field_event_image')->isEmpty()) {
      $file = $event->get('field_event_image')->entity;
      if ($file && $file->getFileUri()) {
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
      'pdf_available' => ($orderItemId !== NULL && $orderItemId > 0) || $hasTicketCode,
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
