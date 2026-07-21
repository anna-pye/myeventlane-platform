<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_tickets\Service\EventAccess;
use Drupal\myeventlane_vendor\Service\EventVendorAccessCheckerInterface;
use Drupal\node\NodeInterface;

/**
 * Access check for tickets workspace routes.
 */
final class EventTicketsAccess implements AccessInterface {

  /**
   * Constructs EventTicketsAccess.
   */
  public function __construct(
    private readonly EventAccess $eventAccess,
    private readonly EventVendorAccessCheckerInterface $eventVendorAccessChecker,
  ) {}

  /**
   * Checks access for tickets workspace routes.
   *
   * Allows when either:
   * - User can manage this event's tickets (manage own events tickets + owner/vendor), or
   * - User has access vendor console + event owner or vendor membership.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user account.
   * @param \Drupal\node\NodeInterface|null $event
   *   The event node, when route parameters have been resolved.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account, ?NodeInterface $event = NULL): AccessResultInterface {
    if (!$event) {
      return AccessResult::forbidden('Event route parameter is required.')->setCacheMaxAge(0);
    }

    if ($event->bundle() !== 'event') {
      return AccessResult::forbidden()->addCacheableDependency($event);
    }

    if ($this->eventAccess->canManageEventTickets($event)) {
      return AccessResult::allowed()->cachePerUser()->addCacheableDependency($event);
    }

    // Allow access vendor console + workspace parity (owner or vendor team).
    if ($account->hasPermission('access vendor console')) {
      if ($account->hasPermission('administer nodes')) {
        return AccessResult::allowed()->cachePerUser()->addCacheableDependency($event);
      }
      if ($this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($event, $account)) {
        return AccessResult::allowed()->cachePerUser()->addCacheableDependency($event);
      }
    }

    return AccessResult::forbidden()->addCacheableDependency($event);
  }

}
