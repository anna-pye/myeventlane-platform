<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Service;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\node\NodeInterface;

/**
 * Interface for {@see MelAttendeeOperationsAccess}.
 *
 * Extracted so MEL Attendee Operations Layer collaborators (notably
 * {@see MelAttendeeCheckinManager}) can be unit-tested without doubling the
 * final implementation. The implementation remains `final`.
 */
interface MelAttendeeOperationsAccessInterface {

  /**
   * Whether the account can view attendees for an event.
   */
  public function canViewAttendees(NodeInterface $event, AccountInterface $account): AccessResultInterface;

  /**
   * Whether the account can export attendees for an event.
   */
  public function canExportAttendees(NodeInterface $event, AccountInterface $account): AccessResultInterface;

  /**
   * Whether the account can check attendees in for an event.
   */
  public function canCheckInAttendees(NodeInterface $event, AccountInterface $account): AccessResultInterface;

  /**
   * Whether the account can cancel an attendance for an event.
   */
  public function canCancelAttendance(NodeInterface $event, AccountInterface $account): AccessResultInterface;

  /**
   * Whether the account can view a single attendee row.
   */
  public function canViewAttendeeRow(EventAttendee $attendee, AccountInterface $account): AccessResultInterface;

  /**
   * Whether any operational attendee action is allowed for the event.
   */
  public function hasAnyOperationalAccess(NodeInterface $event, AccountInterface $account): AccessResultInterface;

  /**
   * Whether the account has organiser workspace parity for the event.
   *
   * Ownership-only hop (no staff bypass, no product permissions). Callers keep
   * their existing admin/product gates and cache metadata; this replaces
   * private author/team loops.
   */
  public function accountHasOrganiserOwnership(NodeInterface $event, AccountInterface $account): bool;

}
