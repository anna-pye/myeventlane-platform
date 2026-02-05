<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_tickets\Service\EventAccess;
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
  ) {}

  /**
   * Checks access for tickets workspace routes.
   *
   * Allows when either:
   * - User can manage this event's tickets (manage own events tickets + owner/vendor), or
   * - User has access vendor console + event owner or vendor membership.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(NodeInterface $event, AccountInterface $account): AccessResultInterface {
    if (!$event || $event->bundle() !== 'event') {
      return AccessResult::forbidden();
    }

    if ($this->eventAccess->canManageEventTickets($event)) {
      return AccessResult::allowed()->cachePerUser()->addCacheableDependency($event);
    }

    // Allow access vendor console + event ownership (same as vendor base).
    if ($account->hasPermission('access vendor console')) {
      if ($account->hasPermission('administer nodes')) {
        return AccessResult::allowed()->cachePerUser()->addCacheableDependency($event);
      }
      if ((int) $event->getOwnerId() === (int) $account->id()) {
        return AccessResult::allowed()->cachePerUser()->addCacheableDependency($event);
      }
      if ($event->hasField('field_event_vendor') && !$event->get('field_event_vendor')->isEmpty()) {
        $vendor = $event->get('field_event_vendor')->entity;
        if ($vendor && $vendor->hasField('field_vendor_users')) {
          foreach ($vendor->get('field_vendor_users')->getValue() as $item) {
            if (isset($item['target_id']) && (int) $item['target_id'] === (int) $account->id()) {
              return AccessResult::allowed()->cachePerUser()->addCacheableDependency($event);
            }
          }
        }
      }
    }

    return AccessResult::forbidden()->addCacheableDependency($event);
  }

}
