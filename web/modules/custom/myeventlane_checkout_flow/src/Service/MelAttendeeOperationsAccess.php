<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Service;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\myeventlane_vendor\Service\EventVendorAccessCheckerInterface;
use Drupal\node\NodeInterface;

/**
 * Single attendee operational access policy.
 *
 * Composes the existing event/vendor ownership and admin gates so every
 * attendee surface (vendor list, CSV export, check-in toggle, future QR
 * scanning) takes its access decision from one place. This avoids parallel
 * access logic accumulating in controllers, presenters, and Twig.
 *
 * Fail-closed: every public method returns AccessResult::forbidden() unless
 * an explicit positive condition is met. Admin override is the named
 * "administer event attendees" permission (matches EventAttendee admin
 * permission) plus broad "administer commerce_order" for cross-store ops.
 */
final class MelAttendeeOperationsAccess implements MelAttendeeOperationsAccessInterface {

  public const ACTION_VIEW = 'view_attendees';
  public const ACTION_EXPORT = 'export_attendees';
  public const ACTION_CHECK_IN = 'check_in_attendees';
  public const ACTION_CANCEL = 'cancel_attendees';

  /**
   * Permission that lets staff bypass event-scoped checks.
   */
  public const PERM_ADMIN = 'administer event attendees';

  /**
   * Broad commerce permission used by store-level admin tools.
   */
  public const PERM_ADMIN_COMMERCE = 'administer commerce_order';

  public function __construct(
    private readonly EventVendorAccessCheckerInterface $eventVendorAccessChecker,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Whether the account can view attendees for an event.
   */
  public function canViewAttendees(NodeInterface $event, AccountInterface $account): AccessResultInterface {
    return $this->resolve(self::ACTION_VIEW, $event, $account);
  }

  /**
   * Whether the account can export attendees for an event.
   */
  public function canExportAttendees(NodeInterface $event, AccountInterface $account): AccessResultInterface {
    return $this->resolve(self::ACTION_EXPORT, $event, $account);
  }

  /**
   * Whether the account can check attendees in for an event.
   */
  public function canCheckInAttendees(NodeInterface $event, AccountInterface $account): AccessResultInterface {
    return $this->resolve(self::ACTION_CHECK_IN, $event, $account);
  }

  /**
   * Whether the account can cancel an attendance for an event.
   */
  public function canCancelAttendance(NodeInterface $event, AccountInterface $account): AccessResultInterface {
    return $this->resolve(self::ACTION_CANCEL, $event, $account);
  }

  /**
   * Convenience: ownership check based on a single EventAttendee row.
   *
   * Resolves the event from the row and applies the same policy. Fails
   * closed if the event cannot be resolved or is the wrong bundle.
   */
  public function canViewAttendeeRow(EventAttendee $attendee, AccountInterface $account): AccessResultInterface {
    $event = $attendee->getEvent();
    if (!$event instanceof NodeInterface) {
      $this->logger->warning('Attendee row @id has no resolvable event; denying access for uid=@uid.', [
        '@id' => (string) $attendee->id(),
        '@uid' => (string) $account->id(),
      ]);
      return AccessResult::forbidden('Attendee has no event reference.')
        ->addCacheableDependency($attendee);
    }
    return $this->canViewAttendees($event, $account)->addCacheableDependency($attendee);
  }

  /**
   * Returns whether *any* operational action is allowed for the event.
   *
   * Useful for hiding the entire vendor attendee surface from accounts
   * that have no relationship with the event.
   */
  public function hasAnyOperationalAccess(NodeInterface $event, AccountInterface $account): AccessResultInterface {
    return $this->canViewAttendees($event, $account);
  }

  /**
   * Internal: shared resolution. Returns a fully cacheable AccessResult.
   */
  private function resolve(string $action, NodeInterface $event, AccountInterface $account): AccessResultInterface {
    if ($event->bundle() !== 'event') {
      $this->logger->warning('Attendee operations request for non-event node @nid (bundle=@bundle); denying.', [
        '@nid' => (string) $event->id(),
        '@bundle' => $event->bundle(),
      ]);
      return AccessResult::forbidden('Not an event node.')
        ->addCacheableDependency($event);
    }

    if ($account->hasPermission(self::PERM_ADMIN) || $account->hasPermission(self::PERM_ADMIN_COMMERCE)) {
      return AccessResult::allowed()
        ->cachePerPermissions()
        ->addCacheableDependency($event);
    }

    if ($account->isAnonymous()) {
      return AccessResult::forbidden('Anonymous accounts have no attendee operations.')
        ->cachePerUser()
        ->addCacheableDependency($event);
    }

    if ($this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($event, $account)) {
      return AccessResult::allowed()
        ->cachePerUser()
        ->addCacheableDependency($event);
    }

    $this->logger->info('Attendee operations action @action denied for uid=@uid on event @nid (no ownership).', [
      '@action' => $action,
      '@uid' => (string) $account->id(),
      '@nid' => (string) $event->id(),
    ]);

    return AccessResult::forbidden('No operational access for this event.')
      ->cachePerUser()
      ->addCacheableDependency($event);
  }

}
