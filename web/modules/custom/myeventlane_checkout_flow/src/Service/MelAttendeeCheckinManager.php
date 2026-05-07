<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Service;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Component\Datetime\TimeInterface;
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

  public function __construct(
    private readonly AttendanceManagerInterface $attendanceManager,
    private readonly MelAttendeeOperationsAccessInterface $access,
    private readonly TimeInterface $time,
    private readonly LoggerChannelInterface $logger,
  ) {}

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

}
