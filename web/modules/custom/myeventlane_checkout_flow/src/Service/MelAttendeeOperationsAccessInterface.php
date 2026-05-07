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

  public function canViewAttendees(NodeInterface $event, AccountInterface $account): AccessResultInterface;

  public function canExportAttendees(NodeInterface $event, AccountInterface $account): AccessResultInterface;

  public function canCheckInAttendees(NodeInterface $event, AccountInterface $account): AccessResultInterface;

  public function canCancelAttendance(NodeInterface $event, AccountInterface $account): AccessResultInterface;

  public function canViewAttendeeRow(EventAttendee $attendee, AccountInterface $account): AccessResultInterface;

  public function hasAnyOperationalAccess(NodeInterface $event, AccountInterface $account): AccessResultInterface;

}
