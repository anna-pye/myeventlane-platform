<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Service;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_checkout_flow\MelAttendeeAttendanceState;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\myeventlane_event_attendees\Service\AttendanceManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;

/**
 * Single attendee check-in transition manager.
 *
 * Wraps {@see AttendanceManagerInterface::checkIn()} so every operational
 * surface that wants to record a check-in (vendor list toggle, scan
 * controller, future mobile QR scanner, admin override) goes through one
 * audited path with the same:
 * - ownership validation (via {@see MelAttendeeOperationsAccess})
 * - duplicate prevention (idempotent)
 * - audit metadata (actor uid, source path, timestamp, event id)
 * - paragraph mirror updates (existing field_checked_in* on attendee_answer)
 *
 * No new fields are added. Existing storage:
 * - event_attendee.status / event_attendee.checked_in_at (canonical)
 * - attendee_answer.field_checked_in / .field_checked_in_timestamp /
 *   .field_checked_in_by (paragraph mirror, ticket source only)
 *
 * Future work tracked in the audit doc: a base field on event_attendee for
 * the actor uid (out of scope for this layer; would require hook_update_N).
 */
final class MelAttendeeCheckinManager {

  /**
   * Source path: vendor manual check-in via the attendees list.
   */
  public const SOURCE_VENDOR_LIST = 'vendor_list';

  /**
   * Source path: QR scan via the check-in controller.
   */
  public const SOURCE_QR_SCAN = 'qr_scan';

  /**
   * Source path: admin override (cross-store / staff diagnostics).
   */
  public const SOURCE_ADMIN_OVERRIDE = 'admin_override';

  /**
   * Source path: door-mode JSON validate endpoint.
   */
  public const SOURCE_DOOR_JSON = 'door_json';

  /**
   * Source path: explicit arrival / manual door list confirmation.
   */
  public const SOURCE_MARK_ARRIVED = 'mark_arrived';

  public function __construct(
    private readonly AttendanceManagerInterface $attendanceManager,
    private readonly MelAttendeeOperationsAccessInterface $access,
    private readonly TimeInterface $time,
    private readonly LoggerChannelInterface $logger,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Canonical alias for {@see self::checkIn()}.
   *
   * @return array{
   *   success: bool,
   *   transitioned: bool,
   *   reason: string,
   *   state: string,
   *   actor_uid: int,
   *   timestamp: int,
   *   audit_id: string
   * }
   */
  public function checkInAttendee(EventAttendee $attendee, AccountInterface $actor, string $source = self::SOURCE_VENDOR_LIST): array {
    return $this->checkIn($attendee, $actor, $source);
  }

  /**
   * Canonical alias for {@see self::reverseCheckIn()}.
   *
   * @return array{
   *   success: bool,
   *   transitioned: bool,
   *   reason: string,
   *   state: string,
   *   actor_uid: int,
   *   timestamp: int,
   *   audit_id: string
   * }
   */
  public function undoCheckIn(EventAttendee $attendee, AccountInterface $actor): array {
    return $this->reverseCheckIn($attendee, $actor);
  }

  /**
   * Marks arrival using the same eligibility rules as check-in.
   *
   * @return array{
   *   success: bool,
   *   transitioned: bool,
   *   reason: string,
   *   state: string,
   *   actor_uid: int,
   *   timestamp: int,
   *   audit_id: string
   * }
   */
  public function markArrived(EventAttendee $attendee, AccountInterface $actor): array {
    return $this->checkIn($attendee, $actor, self::SOURCE_MARK_ARRIVED);
  }

  /**
   * Staff-style override: force checked-in or undo from confirmed state.
   *
   * @return array{
   *   success: bool,
   *   transitioned: bool,
   *   reason: string,
   *   state: string,
   *   actor_uid: int,
   *   timestamp: int,
   *   audit_id: string
   * }
   */
  public function markManualOverride(EventAttendee $attendee, AccountInterface $actor, bool $checkedIn): array {
    return $checkedIn
      ? $this->checkIn($attendee, $actor, self::SOURCE_ADMIN_OVERRIDE)
      : $this->reverseCheckIn($attendee, $actor);
  }

  /**
   * Resolves a ticket-holder paragraph to an event_attendee and checks in.
   *
   * Used by door JSON, QR token scan, and paragraph forms so canonical entity
   * state and paragraph mirrors stay aligned.
   *
   * @return array{
   *   success: bool,
   *   transitioned: bool,
   *   reason: string,
   *   state: string,
   *   actor_uid: int,
   *   timestamp: int,
   *   audit_id: string,
   *   attendee_id?: int|null
   * }
   */
  public function checkInForTicketParagraph(ParagraphInterface $paragraph, NodeInterface $event, AccountInterface $actor, string $source = self::SOURCE_QR_SCAN): array {
    if ($paragraph->bundle() !== 'attendee_answer') {
      return $this->paragraphResult($actor, FALSE, FALSE, 'invalid_paragraph', MelAttendeeAttendanceState::Invalid->value, NULL);
    }
    if ($event->bundle() !== 'event') {
      return $this->paragraphResult($actor, FALSE, FALSE, 'invalid_event', MelAttendeeAttendanceState::Invalid->value, NULL);
    }
    $eventId = (int) $event->id();
    $attendee = $this->resolveTicketAttendeeFromParagraph($paragraph, $eventId);
    if (!$attendee instanceof EventAttendee) {
      $this->logger->warning('Paragraph check-in: no event_attendee for paragraph=@pid event=@eid.', [
        '@pid' => (string) $paragraph->id(),
        '@eid' => (string) $eventId,
      ]);
      return $this->paragraphResult($actor, FALSE, FALSE, 'attendee_not_found', MelAttendeeAttendanceState::Invalid->value, NULL);
    }
    if ((int) $attendee->getEventId() !== $eventId) {
      return $this->paragraphResult($actor, FALSE, FALSE, 'event_mismatch', MelAttendeeAttendanceState::Invalid->value, (int) $attendee->id());
    }
    $out = $this->checkIn($attendee, $actor, $source);
    $out['attendee_id'] = (int) $attendee->id();
    return $out;
  }

  /**
   * Reverses check-in for the event_attendee resolved from a ticket paragraph.
   *
   * @return array{
   *   success: bool,
   *   transitioned: bool,
   *   reason: string,
   *   state: string,
   *   actor_uid: int,
   *   timestamp: int,
   *   audit_id: string,
   *   attendee_id?: int|null
   * }
   */
  public function undoCheckInForTicketParagraph(ParagraphInterface $paragraph, NodeInterface $event, AccountInterface $actor): array {
    if ($paragraph->bundle() !== 'attendee_answer') {
      return $this->paragraphResult($actor, FALSE, FALSE, 'invalid_paragraph', MelAttendeeAttendanceState::Invalid->value, NULL);
    }
    $eventId = (int) $event->id();
    $attendee = $this->resolveTicketAttendeeFromParagraph($paragraph, $eventId);
    if (!$attendee instanceof EventAttendee) {
      return $this->paragraphResult($actor, FALSE, FALSE, 'attendee_not_found', MelAttendeeAttendanceState::Invalid->value, NULL);
    }
    if ((int) $attendee->getEventId() !== $eventId) {
      return $this->paragraphResult($actor, FALSE, FALSE, 'event_mismatch', MelAttendeeAttendanceState::Invalid->value, (int) $attendee->id());
    }
    $out = $this->reverseCheckIn($attendee, $actor);
    $out['attendee_id'] = (int) $attendee->id();
    return $out;
  }

  /**
   * Result shape for check-in transitions.
   *
   * @return array{
   *   success: bool,
   *   transitioned: bool,
   *   reason: string,
   *   state: string,
   *   actor_uid: int,
   *   timestamp: int,
   *   audit_id: string
   * }
   */
  public function checkIn(EventAttendee $attendee, AccountInterface $actor, string $source = self::SOURCE_VENDOR_LIST): array {
    $event = $attendee->getEvent();
    if (!$event instanceof NodeInterface) {
      return $this->result(
        success: FALSE,
        transitioned: FALSE,
        reason: 'no_event',
        state: MelAttendeeAttendanceState::Invalid->value,
        actor: $actor,
      );
    }

    $accessResult = $this->access->canCheckInAttendees($event, $actor);
    if (!$accessResult->isAllowed()) {
      $this->logger->warning('Check-in denied: uid=@uid, attendee=@aid, event=@nid, source=@src.', [
        '@uid' => (string) $actor->id(),
        '@aid' => (string) $attendee->id(),
        '@nid' => (string) $event->id(),
        '@src' => $source,
      ]);
      return $this->result(
        success: FALSE,
        transitioned: FALSE,
        reason: 'forbidden',
        state: MelAttendeeAttendanceState::fromEventAttendee($attendee, $this->resolveOrderItem($attendee))->value,
        actor: $actor,
      );
    }

    $orderItem = $this->resolveOrderItem($attendee);
    $state = MelAttendeeAttendanceState::fromEventAttendee($attendee, $orderItem);

    if ($state === MelAttendeeAttendanceState::CheckedIn) {
      $this->logger->info('Check-in idempotent (already checked in): uid=@uid, attendee=@aid, event=@nid, source=@src.', [
        '@uid' => (string) $actor->id(),
        '@aid' => (string) $attendee->id(),
        '@nid' => (string) $event->id(),
        '@src' => $source,
      ]);
      return $this->result(
        success: TRUE,
        transitioned: FALSE,
        reason: 'already_checked_in',
        state: $state->value,
        actor: $actor,
      );
    }

    if (!$state->isCheckInEligible()) {
      $this->logger->warning(
        'Check-in blocked by state: uid=@uid, attendee=@aid, event=@nid, state=@state, source=@src.',
        [
          '@uid' => (string) $actor->id(),
          '@aid' => (string) $attendee->id(),
          '@nid' => (string) $event->id(),
          '@state' => $state->value,
          '@src' => $source,
        ]
      );
      return $this->result(
        success: FALSE,
        transitioned: FALSE,
        reason: 'state_not_eligible:' . $state->value,
        state: $state->value,
        actor: $actor,
      );
    }

    $transitioned = FALSE;
    try {
      $transitioned = $this->attendanceManager->checkIn($attendee);
    }
    catch (\Throwable $e) {
      $this->logger->error('Check-in transition failed: uid=@uid, attendee=@aid, event=@nid, error=@msg.', [
        '@uid' => (string) $actor->id(),
        '@aid' => (string) $attendee->id(),
        '@nid' => (string) $event->id(),
        '@msg' => $e->getMessage(),
      ]);
      return $this->result(
        success: FALSE,
        transitioned: FALSE,
        reason: 'transition_failed',
        state: $state->value,
        actor: $actor,
      );
    }

    if ($transitioned && $orderItem instanceof OrderItemInterface) {
      $this->mirrorToParagraph($orderItem, $actor);
    }

    $this->logger->notice(
      'Check-in success: uid=@uid, attendee=@aid, event=@nid, source=@src, transitioned=@t.',
      [
        '@uid' => (string) $actor->id(),
        '@aid' => (string) $attendee->id(),
        '@nid' => (string) $event->id(),
        '@src' => $source,
        '@t' => $transitioned ? '1' : '0',
      ]
    );

    return $this->result(
      success: TRUE,
      transitioned: $transitioned,
      reason: $transitioned ? 'transitioned' : 'noop',
      state: MelAttendeeAttendanceState::CheckedIn->value,
      actor: $actor,
    );
  }

  /**
   * Reverses a check-in. Used for admin override / mistaken scans.
   *
   * @return array{
   *   success: bool,
   *   transitioned: bool,
   *   reason: string,
   *   state: string,
   *   actor_uid: int,
   *   timestamp: int,
   *   audit_id: string
   * }
   */
  public function reverseCheckIn(EventAttendee $attendee, AccountInterface $actor): array {
    $event = $attendee->getEvent();
    if (!$event instanceof NodeInterface) {
      return $this->result(FALSE, FALSE, 'no_event', MelAttendeeAttendanceState::Invalid->value, $actor);
    }

    $accessResult = $this->access->canCheckInAttendees($event, $actor);
    if (!$accessResult->isAllowed()) {
      return $this->result(FALSE, FALSE, 'forbidden', MelAttendeeAttendanceState::Invalid->value, $actor);
    }

    if (!$attendee->isCheckedIn()) {
      return $this->result(TRUE, FALSE, 'not_checked_in', MelAttendeeAttendanceState::Registered->value, $actor);
    }

    try {
      $attendee->setStatus(EventAttendee::STATUS_CONFIRMED);
      if ($attendee->hasField('checked_in_at')) {
        $attendee->set('checked_in_at', NULL);
      }
      $attendee->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('Reverse check-in failed: uid=@uid, attendee=@aid, error=@msg.', [
        '@uid' => (string) $actor->id(),
        '@aid' => (string) $attendee->id(),
        '@msg' => $e->getMessage(),
      ]);
      return $this->result(FALSE, FALSE, 'transition_failed', MelAttendeeAttendanceState::CheckedIn->value, $actor);
    }

    $orderItem = $this->resolveOrderItem($attendee);
    if ($orderItem instanceof OrderItemInterface) {
      $this->reverseParagraphMirror($orderItem);
    }

    $this->logger->notice('Check-in reversed: uid=@uid, attendee=@aid.', [
      '@uid' => (string) $actor->id(),
      '@aid' => (string) $attendee->id(),
    ]);

    return $this->result(TRUE, TRUE, 'reversed', MelAttendeeAttendanceState::Registered->value, $actor);
  }

  /**
   * Operational readiness summary for an event (count breakdown).
   *
   * @return array{
   *   total: int,
   *   ready: int,
   *   checked_in: int,
   *   blocked: int
   * }
   */
  public function readinessForEvent(NodeInterface $event): array {
    $total = 0;
    $ready = 0;
    $checkedIn = 0;
    $blocked = 0;
    $entities = $this->attendanceManager->getAttendeesForEvent((int) $event->id());
    foreach ($entities as $entity) {
      if (!$entity instanceof EventAttendee) {
        continue;
      }
      $total++;
      $orderItem = $this->resolveOrderItem($entity);
      $state = MelAttendeeAttendanceState::fromEventAttendee($entity, $orderItem);
      if ($state === MelAttendeeAttendanceState::CheckedIn) {
        $checkedIn++;
      }
      elseif ($state->isCheckInEligible()) {
        $ready++;
      }
      else {
        $blocked++;
      }
    }
    return [
      'total' => $total,
      'ready' => $ready,
      'checked_in' => $checkedIn,
      'blocked' => $blocked,
    ];
  }

  /**
   * Mirrors check-in state to the attendee_answer paragraph for ticket sources.
   *
   * Uses the existing paragraph fields (field_checked_in,
   * field_checked_in_timestamp, field_checked_in_by). No schema changes.
   */
  private function mirrorToParagraph(OrderItemInterface $orderItem, AccountInterface $actor): void {
    if (!$orderItem->hasField('field_ticket_holder') || $orderItem->get('field_ticket_holder')->isEmpty()) {
      return;
    }
    $now = $this->time->getRequestTime();
    foreach ($orderItem->get('field_ticket_holder')->referencedEntities() as $paragraph) {
      if (!$paragraph instanceof ParagraphInterface || $paragraph->bundle() !== 'attendee_answer') {
        continue;
      }
      try {
        if ($paragraph->hasField('field_checked_in')) {
          $paragraph->set('field_checked_in', TRUE);
        }
        if ($paragraph->hasField('field_checked_in_timestamp')) {
          $paragraph->set('field_checked_in_timestamp', $now);
        }
        if ($paragraph->hasField('field_checked_in_by') && (int) $actor->id() > 0) {
          $paragraph->set('field_checked_in_by', (int) $actor->id());
        }
        $paragraph->save();
      }
      catch (\Throwable $e) {
        $this->logger->warning('Paragraph check-in mirror failed: paragraph=@pid, error=@msg.', [
          '@pid' => (string) $paragraph->id(),
          '@msg' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Reverses paragraph check-in mirror. Quiet on missing fields.
   */
  private function reverseParagraphMirror(OrderItemInterface $orderItem): void {
    if (!$orderItem->hasField('field_ticket_holder') || $orderItem->get('field_ticket_holder')->isEmpty()) {
      return;
    }
    foreach ($orderItem->get('field_ticket_holder')->referencedEntities() as $paragraph) {
      if (!$paragraph instanceof ParagraphInterface || $paragraph->bundle() !== 'attendee_answer') {
        continue;
      }
      try {
        if ($paragraph->hasField('field_checked_in')) {
          $paragraph->set('field_checked_in', FALSE);
        }
        if ($paragraph->hasField('field_checked_in_timestamp')) {
          $paragraph->set('field_checked_in_timestamp', NULL);
        }
        if ($paragraph->hasField('field_checked_in_by')) {
          $paragraph->set('field_checked_in_by', NULL);
        }
        $paragraph->save();
      }
      catch (\Throwable $e) {
        $this->logger->warning('Paragraph reverse mirror failed: paragraph=@pid, error=@msg.', [
          '@pid' => (string) $paragraph->id(),
          '@msg' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Resolves the order item attached to an EventAttendee, if any.
   */
  private function resolveOrderItem(EventAttendee $attendee): ?OrderItemInterface {
    if (!$attendee->hasField('order_item') || $attendee->get('order_item')->isEmpty()) {
      return NULL;
    }
    $candidate = $attendee->get('order_item')->entity;
    return $candidate instanceof OrderItemInterface ? $candidate : NULL;
  }

  /**
   * Locates the canonical event_attendee for a ticket-holder paragraph.
   */
  private function resolveTicketAttendeeFromParagraph(ParagraphInterface $paragraph, int $eventId): ?EventAttendee {
    $pid = (int) $paragraph->id();
    if ($pid <= 0) {
      return NULL;
    }
    $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
    if (!is_object($orderItemStorage) || !method_exists($orderItemStorage, 'getQuery')) {
      return NULL;
    }
    $ids = $orderItemStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_ticket_holder', $pid)
      ->execute();
    if (!$ids) {
      return NULL;
    }
    foreach ($ids as $oiid) {
      $oi = $orderItemStorage->load($oiid);
      if (!$oi instanceof OrderItemInterface) {
        continue;
      }
      $oiEventId = NULL;
      if ($oi->hasField('field_target_event') && !$oi->get('field_target_event')->isEmpty()) {
        $oiEventId = (int) $oi->get('field_target_event')->target_id;
      }
      if ($oiEventId !== $eventId) {
        continue;
      }
      $holders = $oi->get('field_ticket_holder')->referencedEntities();
      $index = -1;
      foreach ($holders as $i => $h) {
        if ($h instanceof ParagraphInterface && (int) $h->id() === $pid) {
          $index = (int) $i;
          break;
        }
      }
      if ($index < 0) {
        continue;
      }
      $aids = $this->entityTypeManager->getStorage('event_attendee')->getQuery()
        ->accessCheck(FALSE)
        ->condition('event', $eventId)
        ->condition('order_item', (int) $oi->id())
        ->condition('source', EventAttendee::SOURCE_TICKET)
        ->execute();
      if (!$aids) {
        continue;
      }
      /** @var \Drupal\myeventlane_event_attendees\Entity\EventAttendee[] $attendees */
      $attendees = array_values($this->entityTypeManager->getStorage('event_attendee')->loadMultiple($aids));
      $resolved = $this->resolveAttendeeForHolderSlot($paragraph, $index, $attendees);
      if ($resolved instanceof EventAttendee) {
        return $resolved;
      }
    }
    return NULL;
  }

  /**
   * Pairs a holder paragraph to the correct event_attendee for one order item.
   *
   * Holder order follows field_ticket_holder deltas (checkout order).
   * Attendee rows for the same order item are matched by:
   * 1. Normalised email + name fingerprint (stable when IDs are out of slot order).
   * 2. Else insertion order: created ASC, then id ASC, aligned by holder index
   *    (matches OrderCompletedSubscriber paid-ticket slot creation order).
   *
   * @param list<EventAttendee> $attendees
   */
  private function resolveAttendeeForHolderSlot(
    ParagraphInterface $paragraph,
    int $holderIndex,
    array $attendees,
  ): ?EventAttendee {
    if ($attendees === []) {
      return NULL;
    }
    $needle = $this->holderIdentityFingerprint($paragraph);
    if ($needle !== '') {
      $matches = [];
      foreach ($attendees as $attendee) {
        if (!$attendee instanceof EventAttendee) {
          continue;
        }
        if ($this->attendeeIdentityFingerprint($attendee) === $needle) {
          $matches[] = $attendee;
        }
      }
      if (count($matches) === 1) {
        return $matches[0];
      }
      if (count($matches) > 1) {
        $this->logger->notice('Paragraph→event_attendee: duplicate identity fingerprint; disambiguating by holder index @idx among @c matches (creation order).', [
          '@idx' => (string) $holderIndex,
          '@c' => (string) count($matches),
          'holder_index' => $holderIndex,
          'match_count' => count($matches),
        ]);
        usort($matches, $this->compareAttendeesByCreation(...));
        $last = count($matches) - 1;
        return $matches[min(max($holderIndex, 0), $last)];
      }
    }

    if ($needle === '' && count($attendees) > 1) {
      $this->logger->notice('Paragraph→event_attendee: empty holder identity; pairing slot @idx by creation order among @c ticket attendees.', [
        '@idx' => (string) $holderIndex,
        '@c' => (string) count($attendees),
        'holder_index' => $holderIndex,
        'attendee_count' => count($attendees),
      ]);
    }

    $ordered = $attendees;
    usort($ordered, $this->compareAttendeesByCreation(...));
    return $ordered[$holderIndex] ?? NULL;
  }

  /**
   * Normalised identity for a ticket-holder paragraph (email + display name).
   */
  private function holderIdentityFingerprint(ParagraphInterface $paragraph): string {
    $first = $paragraph->hasField('field_first_name') && !$paragraph->get('field_first_name')->isEmpty()
      ? trim((string) $paragraph->get('field_first_name')->value)
      : '';
    $last = $paragraph->hasField('field_last_name') && !$paragraph->get('field_last_name')->isEmpty()
      ? trim((string) $paragraph->get('field_last_name')->value)
      : '';
    $email = $paragraph->hasField('field_email') && !$paragraph->get('field_email')->isEmpty()
      ? strtolower(trim((string) $paragraph->get('field_email')->value))
      : '';
    $name = strtolower(trim(preg_replace('/\s+/', ' ', $first . ' ' . $last) ?? ''));
    if ($email === '' && $name === '') {
      return '';
    }
    return $email . '|' . $name;
  }

  /**
   * Normalised identity for an event_attendee row (email + name field).
   */
  private function attendeeIdentityFingerprint(EventAttendee $attendee): string {
    $email = strtolower(trim($attendee->getEmail()));
    $name = strtolower(trim(preg_replace('/\s+/', ' ', $attendee->getName()) ?? ''));
    if ($email === '' && $name === '') {
      return '';
    }
    return $email . '|' . $name;
  }

  private function compareAttendeesByCreation(EventAttendee $a, EventAttendee $b): int {
    $timeCmp = $a->getCreatedTime() <=> $b->getCreatedTime();
    if ($timeCmp !== 0) {
      return $timeCmp;
    }
    return ((int) $a->id()) <=> ((int) $b->id());
  }

  /**
   * Builds the audited result shape.
   */
  private function result(bool $success, bool $transitioned, string $reason, string $state, AccountInterface $actor): array {
    $now = $this->time->getRequestTime();
    return [
      'success' => $success,
      'transitioned' => $transitioned,
      'reason' => $reason,
      'state' => $state,
      'actor_uid' => (int) $actor->id(),
      'timestamp' => $now,
      'audit_id' => sprintf('mel.attendee.checkin.%s.%d.%d', $reason, (int) $actor->id(), $now),
    ];
  }

  /**
   * @param array<string, mixed> $base
   *
   * @return array<string, mixed>
   */
  private function paragraphResult(AccountInterface $actor, bool $success, bool $transitioned, string $reason, string $state, ?int $attendeeId): array {
    $base = $this->result($success, $transitioned, $reason, $state, $actor);
    $base['attendee_id'] = $attendeeId;
    return $base;
  }

}
