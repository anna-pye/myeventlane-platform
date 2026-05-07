<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;

/**
 * Canonical operational attendance state for vendor surfaces.
 *
 * The {@see EventAttendee::STATUS_*} enum is the source of truth for attendance
 * lifecycle. Operational UI also has to surface order-derived signals (refunds,
 * pending order, invalid order references). This value object maps both axes
 * to a small, presentation-ready vocabulary that vendor dashboards, badges,
 * filters, and CSV exports use.
 *
 * Magic strings live here, never in Twig.
 */
enum MelAttendeeAttendanceState: string {

  case Registered = 'registered';
  case CheckedIn = 'checked_in';
  case Cancelled = 'cancelled';
  case Refunded = 'refunded';
  case Pending = 'pending';
  case Invalid = 'invalid';

  /**
   * Maps an EventAttendee plus optional order context to an operational state.
   *
   * Order context wins for refunds; the attendance status enum wins for
   * everything else. The mapping is deterministic and side-effect-free.
   */
  public static function fromEventAttendee(EventAttendee $attendee, ?OrderItemInterface $orderItem = NULL): self {
    $status = $attendee->getStatus();

    if ($status === EventAttendee::STATUS_CHECKED_IN) {
      return self::CheckedIn;
    }
    if ($status === EventAttendee::STATUS_CANCELLED) {
      return self::Cancelled;
    }

    if ($orderItem instanceof OrderItemInterface) {
      $orderState = self::resolveOrderStateId($orderItem);
      if (in_array($orderState, ['refunded', 'partially_refunded'], TRUE)) {
        return self::Refunded;
      }
      if (in_array($orderState, ['canceled'], TRUE)) {
        return self::Cancelled;
      }
      if ($orderState === '') {
        return self::Invalid;
      }
      if (in_array($orderState, ['draft', 'validation', 'pending'], TRUE)) {
        return self::Pending;
      }
    }

    if ($status === EventAttendee::STATUS_WAITLIST) {
      return self::Pending;
    }
    if ($status === EventAttendee::STATUS_NO_SHOW) {
      return self::Cancelled;
    }
    if ($status === EventAttendee::STATUS_CONFIRMED) {
      return self::Registered;
    }

    return self::Invalid;
  }

  /**
   * Human-readable label for the state (translatable string).
   */
  public function label(): string {
    return match ($this) {
      self::Registered => 'Registered',
      self::CheckedIn => 'Checked in',
      self::Cancelled => 'Cancelled',
      self::Refunded => 'Refunded',
      self::Pending => 'Pending',
      self::Invalid => 'Needs attention',
    };
  }

  /**
   * Short helper text shown alongside the badge in operational rows.
   */
  public function helperText(): string {
    return match ($this) {
      self::Registered => 'Ready to check in.',
      self::CheckedIn => 'Arrived at the event.',
      self::Cancelled => 'Removed from the attendee list.',
      self::Refunded => 'Order refunded; ticket no longer valid.',
      self::Pending => 'Awaiting confirmation or payment.',
      self::Invalid => 'Order or attendee data is incomplete.',
    };
  }

  /**
   * Stable BEM-friendly modifier suffix for templates.
   */
  public function modifier(): string {
    return $this->value;
  }

  /**
   * Whether this state counts toward "active" attendance for KPIs.
   */
  public function countsAsActive(): bool {
    return match ($this) {
      self::Registered, self::CheckedIn => TRUE,
      default => FALSE,
    };
  }

  /**
   * Whether check-in is permitted from this state.
   */
  public function isCheckInEligible(): bool {
    return $this === self::Registered;
  }

  /**
   * Whether the row is "settled" and should not be alerted on.
   */
  public function isSettled(): bool {
    return match ($this) {
      self::CheckedIn, self::Cancelled, self::Refunded => TRUE,
      default => FALSE,
    };
  }

  /**
   * Returns all known states (preserved order).
   *
   * @return list<self>
   */
  public static function all(): array {
    return [
      self::Registered,
      self::CheckedIn,
      self::Pending,
      self::Refunded,
      self::Cancelled,
      self::Invalid,
    ];
  }

  /**
   * Returns the readiness identifier consumed by MelStateSystem traces.
   *
   * Deterministic, audit-friendly, and stable across releases.
   */
  public function readinessId(): string {
    return 'attendee.operational.state.' . $this->value;
  }

  /**
   * Resolves the order item's parent order state, returning '' on failure.
   */
  private static function resolveOrderStateId(OrderItemInterface $orderItem): string {
    try {
      $order = $orderItem->getOrder();
    }
    catch (\Throwable) {
      return '';
    }
    if ($order === NULL) {
      return '';
    }
    try {
      return (string) $order->getState()->getId();
    }
    catch (\Throwable) {
      return '';
    }
  }

}
